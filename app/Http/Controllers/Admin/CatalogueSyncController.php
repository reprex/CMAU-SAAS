<?php

namespace App\Http\Controllers\Admin;

use App\Catalogue\ProductHydrator;
use App\Catalogue\RangeManager;
use App\Catalogue\SyncRunner;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DecTypeCode;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SyncRun;
use App\Sources\Promodata\PromodataClient;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

/** Admin only: the Promodata → SaaS interface (sync runs, connection, one-off refreshes). */
class CatalogueSyncController extends Controller
{
    public function index()
    {
        $runs = SyncRun::query()->latest('id')->limit(15)->get();
        $open = SyncRun::query()->open()->oldest('id')->first();
        $lastDone = SyncRun::query()->where('status', SyncRun::STATUS_COMPLETED)->latest('finished_at')->first();
        $tiers = Product::query()->selectRaw('tier, count(*) as n')->groupBy('tier')->pluck('n', 'tier');
        $heartbeat = cache('scheduler:last_run');

        return view('admin.catalogue.sync', [
            'runs' => $runs,
            'open' => $open,
            'lastDone' => $lastDone,
            'tiers' => $tiers,
            'suppliers' => Supplier::query()->count(),
            'unclassified' => DecTypeCode::query()->unclassified()->count(),
            'repriceErrors' => Product::query()->where('tier', Product::TIER_LIVE)->whereNotNull('reprice_error')->count(),
            'queued' => DB::table('jobs')->count(),
            'failedJobs' => DB::table('failed_jobs')->count(),
            'tokenSet' => (string) config('promodata.token') !== '',
            'heartbeat' => $heartbeat ? Carbon::parse($heartbeat) : null,
            'pagesPerStep' => (int) config('promodata.pages_per_step'),
            'supplierList' => Supplier::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function check(PromodataClient $client)
    {
        try {
            $json = $client->listProducts(1);

            return back()->with('status', sprintf('Promodata answered: %s pages of products, %d on the first page.', $json['total_pages'] ?? '?', count((array) ($json['data'] ?? []))));
        } catch (Throwable $e) {
            return back()->withErrors(['promodata' => 'Promodata did not answer: '.mb_substr($e->getMessage(), 0, 200)]);
        }
    }

    public function start(Request $request, SyncRunner $runner)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in([SyncRun::KIND_CHANGED, SyncRun::KIND_FULL, SyncRun::KIND_SUPPLIER])],
            'supplier_id' => 'required_if:kind,supplier|nullable|integer|exists:suppliers,id',
            'since' => 'nullable|date',
        ]);

        $run = match ($data['kind']) {
            SyncRun::KIND_FULL => $runner->start(SyncRun::KIND_FULL),
            SyncRun::KIND_SUPPLIER => $runner->start(SyncRun::KIND_SUPPLIER, null, (int) $data['supplier_id']),
            default => ! empty($data['since'])
                ? $runner->start(SyncRun::KIND_CHANGED, Carbon::parse($data['since']))
                : $runner->startChangedSinceLastRun(),
        };

        if ($run === null) {
            return back()->with('status', 'A sync is already running; it was left as it is.');
        }
        AuditLog::record('promodata.sync_started', $run, ['kind' => $run->kind, 'supplier_id' => $run->supplier_id]);

        return back()->with('status', "Sync #{$run->id} queued. The scheduler works through {$this->pages()} pages a minute.");
    }

    public function cancel(SyncRun $run)
    {
        if (! $run->isFinished()) {
            $run->forceFill(['status' => SyncRun::STATUS_FAILED, 'finished_at' => now(), 'error' => 'Cancelled by '.auth()->user()->name])->save();
            AuditLog::record('promodata.sync_cancelled', $run);
        }

        return back()->with('status', "Sync #{$run->id} stopped. Products already stored are kept.");
    }

    public function refreshProduct(Request $request, ProductHydrator $hydrator, RangeManager $range)
    {
        $id = (int) $request->validate(['product_id' => 'required|integer|min:1'])['product_id'];

        try {
            $existing = Product::query()->find($id);
            $product = $existing && $existing->source === 'promodata' && $existing->keepsFullDetail()
                ? $range->refresh($existing)
                : $hydrator->hydrate($id);
        } catch (Throwable $e) {
            return back()->withErrors(['product_id' => "Product {$id}: ".mb_substr($e->getMessage(), 0, 200)]);
        }
        if ($product === null) {
            return back()->withErrors(['product_id' => "Promodata has no product {$id}."]);
        }
        $range->reprice($product);

        return redirect()->route('range.show', $product)->with('status', "Fetched {$product->name} from Promodata.");
    }

    private function pages(): int
    {
        return (int) config('promodata.pages_per_step');
    }
}
