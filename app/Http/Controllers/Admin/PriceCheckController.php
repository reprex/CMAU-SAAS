<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PriceCheck;
use App\Models\PricePattern;
use App\Models\Product;
use App\Models\Supplier;
use App\Pricing\PricePatterns;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Price check: verify, pattern by pattern, whether Promodata's base price
 * includes branding, from screengrabs of each supplier's own distributor
 * prices (docs/price-check.md). Admin only: it shows supplier costs.
 */
class PriceCheckController extends Controller
{
    public const VIEWS = ['patterns' => 'Patterns to check', 'flagged' => 'Flagged products', 'checks' => 'Checks recorded'];

    public function index(Request $request, PricePatterns $patterns)
    {
        $view = array_key_exists($request->query('view'), self::VIEWS) ? $request->query('view') : 'patterns';
        $supplierId = $request->integer('supplier') ?: null;
        $status = (string) $request->query('status', 'open');

        $summary = $this->summary($patterns);

        $data = ['view' => $view, 'supplierId' => $supplierId, 'status' => $status, 'summary' => $summary,
            'suppliers' => Supplier::query()->whereIn('id', PricePattern::query()->distinct()->pluck('supplier_id'))->orderBy('name')->get(['id', 'name'])];

        if ($view === 'patterns') {
            $all = PricePattern::query()->with('supplier:id,name')
                ->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))
                ->orderByDesc('live_count')->orderByDesc('product_count')->get();
            $statuses = $patterns->statuses($all->pluck('signature')->all());
            $list = $all->filter(fn ($p) => match ($status) {
                'open' => in_array($statuses[$p->signature]['status'] ?? 'unverified', ['unverified', 'mixed'], true),
                'done' => ! in_array($statuses[$p->signature]['status'] ?? 'unverified', ['unverified', 'mixed'], true),
                default => true,
            })->take(200)->values();
            $examples = Product::query()->with('detail:product_id,supplier_website_page')
                ->whereKey($list->pluck('examples')->flatten()->filter()->all())->get(['id', 'supplier_id', 'code', 'name', 'tier'])->keyBy('id');

            return view('admin.price-check', $data + compact('list', 'statuses', 'examples'));
        }

        if ($view === 'flagged') {
            $products = Product::query()->with('supplier:id,name')
                ->whereNotNull('price_flags')
                // Plain text match on the stored JSON works the same on MariaDB and SQLite.
                ->where(fn ($q) => collect(PricePatterns::FLAGS)->keys()->each(fn ($f) => $q->orWhere('price_flags', 'like', '%"'.$f.'":true%')))
                ->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))
                ->orderByRaw("case when tier = 'live' then 0 else 1 end")->orderBy('supplier_id')->orderBy('id')
                ->paginate(50)->withQueryString();

            return view('admin.price-check', $data + compact('products'));
        }

        $checks = PriceCheck::query()->with(['product:id,supplier_id,code,name', 'checker:id,name'])
            ->when($supplierId, fn ($q) => $q->whereHas('product', fn ($q) => $q->where('supplier_id', $supplierId)))
            ->latest('id')->paginate(50)->withQueryString();

        return view('admin.price-check', $data + compact('checks'));
    }

    /** Record what a screengrab showed for one product; it counts for the product's whole pattern. */
    public function store(Request $request, PricePatterns $patterns)
    {
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'verdict' => ['required', Rule::in(array_keys(PriceCheck::VERDICTS))],
            'notes' => 'nullable|string|max:2000',
            'evidence' => 'nullable|string|max:255',
        ]);
        $check = $this->record(Product::query()->findOrFail($data['product_id']), $data, $request->user()->id, $patterns);

        return back()->with('status', "Recorded for {$check->product_id}: ".PriceCheck::VERDICTS[$check->verdict].'.');
    }

    /**
     * Upload checks in bulk: a CSV with columns appaid, verdict, notes, evidence
     * (e.g. the results Claude reads from a folder of screengrabs).
     */
    public function import(Request $request, PricePatterns $patterns)
    {
        $request->validate(['file' => 'required|file|max:2048|mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel']);
        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = array_map(fn ($h) => Str::snake(trim((string) $h, " \t\n\r\0\x0B\xEF\xBB\xBF")), fgetcsv($handle) ?: []);

        $done = 0;
        $skipped = [];
        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null]) {
                continue;
            }
            $r = array_combine(array_slice($header, 0, count($row)), array_slice($row, 0, count($header))) ?: [];
            $id = (int) ($r['appaid'] ?? 0);
            $verdict = trim((string) ($r['verdict'] ?? ''));
            $product = $id ? Product::query()->find($id) : null;
            if (! $product || ! array_key_exists($verdict, PriceCheck::VERDICTS)) {
                $skipped[] = ($r['appaid'] ?? '?').($product ? " (verdict '{$verdict}')" : ' (not found)');

                continue;
            }
            $this->record($product, ['verdict' => $verdict, 'notes' => $r['notes'] ?? null, 'evidence' => $r['evidence'] ?? null], $request->user()->id, $patterns);
            $done++;
        }
        fclose($handle);

        $message = "{$done} checks recorded.".($skipped ? ' Skipped: '.implode(', ', array_slice($skipped, 0, 20)).(count($skipped) > 20 ? '…' : '') : '');

        return back()->with('status', $message);
    }

    /** The screengrab list: example products for each pattern still to check. */
    public function export(Request $request, PricePatterns $patterns): StreamedResponse
    {
        $supplierId = $request->integer('supplier') ?: null;
        $list = PricePattern::query()->with('supplier:id,name')
            ->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))
            ->orderBy('supplier_id')->orderByDesc('live_count')->orderByDesc('product_count')->get();
        $statuses = $patterns->statuses($list->pluck('signature')->all());
        $open = $list->filter(fn ($p) => in_array($statuses[$p->signature]['status'] ?? 'unverified', ['unverified', 'mixed'], true));
        $products = Product::query()->with('detail:product_id,supplier_website_page')
            ->whereKey($open->pluck('examples')->flatten()->filter()->all())->get(['id', 'supplier_id', 'code', 'name'])->keyBy('id');

        return response()->streamDownload(function () use ($open, $products, $statuses) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['supplier', 'pattern', 'products_in_pattern', 'live_in_pattern', 'status', 'appaid', 'code', 'name', 'supplier_page', 'save_screengrab_as', 'verdict', 'notes', 'evidence']);
            foreach ($open as $pattern) {
                foreach ($pattern->examples ?? [] as $id) {
                    $p = $products->get($id);
                    if (! $p) {
                        continue;
                    }
                    fputcsv($out, [$pattern->supplier?->name, $pattern->label, $pattern->product_count, $pattern->live_count,
                        $statuses[$pattern->signature]['status'] ?? 'unverified', $p->id, $p->code, $p->name,
                        $p->detail?->supplier_website_page, self::screengrabName($p), '', '', '']);
                }
            }
            fclose($out);
        }, 'price-check-screengrabs-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv']);
    }

    public static function screengrabName(Product $product): string
    {
        return $product->supplier_id.'-'.(preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) ($product->code ?: $product->id))).'.png';
    }

    private function record(Product $product, array $data, int $userId, PricePatterns $patterns): PriceCheck
    {
        if ($product->price_pattern === null) {
            $patterns->assign($product);
            $product->refresh();
        }
        $check = PriceCheck::query()->create([
            'product_id' => $product->id,
            'price_pattern' => $product->price_pattern,
            'verdict' => $data['verdict'],
            'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
            'evidence' => filled($data['evidence'] ?? null) ? trim($data['evidence']) : null,
            'prices_changed_at' => $product->prices_changed_at,
            'checked_by' => $userId,
        ]);
        $flags = $product->price_flags ?? [];
        if (isset($flags['changed_since_check'])) {
            unset($flags['changed_since_check']);
            $product->forceFill(['price_flags' => $flags])->saveQuietly();
        }
        AuditLog::record('pricing.checked', $product, ['verdict' => $check->verdict, 'pattern' => $check->price_pattern, 'evidence' => $check->evidence]);

        return $check;
    }

    /** Per supplier: patterns, how many verified, and the share of products they cover. */
    private function summary(PricePatterns $patterns)
    {
        $all = PricePattern::query()->with('supplier:id,name')->get(['signature', 'supplier_id', 'product_count', 'live_count', 'flagged_count']);
        $statuses = $patterns->statuses($all->pluck('signature')->all());

        return $all->groupBy('supplier_id')->map(function ($rows) use ($statuses) {
            $done = $rows->filter(fn ($p) => ! in_array($statuses[$p->signature]['status'] ?? 'unverified', ['unverified', 'mixed'], true));

            return (object) [
                'supplier' => $rows->first()->supplier?->name ?? (string) $rows->first()->supplier_id,
                'supplier_id' => $rows->first()->supplier_id,
                'patterns' => $rows->count(),
                'verified' => $done->count(),
                'products' => $rows->sum('product_count'),
                'live' => $rows->sum('live_count'),
                'covered' => $done->sum('product_count'),
                'flagged' => $rows->sum('flagged_count'),
            ];
        })->sortByDesc('live')->values();
    }
}
