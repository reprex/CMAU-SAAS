<?php

namespace App\Catalogue;

use App\Models\BrandingTemplate;
use App\Models\DecTypeCode;
use App\Models\PhotoBranding;
use App\Models\PriceOption;
use App\Models\PricePattern;
use App\Models\Product;
use App\Models\RangeSuggestion;
use App\Models\Supplier;
use App\Pricing\AutoClassifier;
use App\Pricing\PricePatterns;
use App\Shopify\PublishHealth;
use Illuminate\Support\Facades\Cache;

/**
 * The one Vet screen (Scott, 5 Nov 2026; docs/vet.md): everything about the catalogue and website data
 * that still needs a person, in one place. Everything else is worked out automatically from the
 * supplier pages, the templates and the garment size charts; this is only the exceptions.
 */
class VetQueue
{
    /** @return array<string, array<string, mixed>> section key => section */
    public function sections(): array
    {
        $names = Supplier::query()->pluck('name', 'id');

        return [
            'new' => $this->newProducts($names),
            'discontinued' => $this->discontinued(),
            'types' => $this->types($names),
            'templates' => $this->templates(),
            'publishing' => $this->publishing(),
            'prices' => $this->prices($names),
            'options' => $this->options($names),
            'mockups' => $this->mockups($names),
        ];
    }

    /** The number shown on the menu: sections that need a decision (not the information-only ones). */
    public function count(): int
    {
        return Cache::remember('vet:count', 300, fn () => collect($this->sections())->where('action', true)->sum('count'));
    }

    private function newProducts($names): array
    {
        $q = RangeSuggestion::query()->where('kind', RangeSuggestion::NEW)->where('status', RangeSuggestion::PENDING);
        $rows = (clone $q)->with('product:id,name,code,supplier_id,hero_image_url')->orderByDesc('ready')->orderBy('id')->limit(25)->get();

        return ['title' => 'New products from suppliers', 'action' => true, 'count' => (clone $q)->count(),
            'ready' => (clone $q)->where('ready', true)->count(),
            'why' => 'Suppliers set to suggest new products. They are fetched, priced and checked first; nothing goes on the range until approved.',
            'rows' => $rows->map(fn ($r) => ['id' => $r->id, 'product_id' => $r->product_id, 'name' => $r->product?->name, 'code' => $r->product?->code,
                'supplier' => $names[$r->product?->supplier_id] ?? null, 'ready' => (bool) $r->ready, 'image' => $r->product?->hero_image_url])->all(),
            'more' => route('range.changes', ['view' => 'ready'])];
    }

    private function discontinued(): array
    {
        $q = RangeSuggestion::query()->where('kind', RangeSuggestion::DISCONTINUED)->where('status', RangeSuggestion::PENDING);

        return ['title' => 'Discontinued with open quotes', 'action' => true, 'count' => (clone $q)->count(),
            'why' => 'Products a supplier discontinued that are on a quote not yet ordered: offer the client a substitute.',
            'rows' => (clone $q)->with('product:id,name,code')->orderBy('id')->limit(15)->get()
                ->map(fn ($r) => ['id' => $r->id, 'product_id' => $r->product_id, 'name' => $r->product?->name, 'code' => $r->product?->code])->all(),
            'more' => route('range.changes', ['view' => 'discontinued'])];
    }

    private function types($names): array
    {
        // Suppliers whose pages we've read are typed per supplier, nightly; what no reading covers is cached.
        $cached = Cache::get('vet:classify', ['left' => []]);
        $read = app(AutoClassifier::class)->suppliers();
        $covered = collect($cached['left'] ?? [])->map(fn ($l) => ['code' => $l['code'], 'description' => $l['description'], 'supplier' => $names[$l['supplier_id']] ?? null, 'on_range' => (int) $l['on_range']]);
        // Other suppliers still use TOD's shared table: wording on the range with no type there.
        $others = DecTypeCode::query()->whereNull('type_code')
            ->joinSub(PriceOption::query()->join('products as p', 'p.id', '=', 'price_options.product_id')
                ->where('p.tier', Product::TIER_LIVE)->where('p.discontinued', false)->whereNotIn('p.supplier_id', $read)
                ->selectRaw('price_options.code, count(distinct p.id) as live_n, min(p.supplier_id) as sid')->groupBy('price_options.code'), 'lu', 'lu.code', '=', 'dec_type_codes.code')
            ->orderByDesc('lu.live_n')->limit(30)->get(['dec_type_codes.code', 'full_description', 'lu.sid', 'lu.live_n'])
            ->map(fn ($l) => ['code' => $l->code, 'description' => $l->full_description, 'supplier' => $names[$l->sid] ?? null, 'on_range' => (int) $l->live_n, 'shared' => true]);
        $left = $covered->take(30)->concat($others)->values();

        return ['title' => 'Decoration types', 'action' => true, 'count' => $covered->count() + $others->count(),
            'why' => 'Each supplier\'s types are set nightly from its own pages and rules, for that supplier only. Listed: wording on the range no reading covers yet (Claude adds a rule from the supplier\'s pages), and for suppliers whose pages aren\'t read yet, wording with no type.',
            'disagree' => [], 'left' => $left->all(), 'mixed' => [], 'at' => $cached['at'] ?? null, 'more' => route('admin.dectypes.index', ['show' => 'live'])];
    }

    private function templates(): array
    {
        $q = BrandingTemplate::query()->whereIn('product_id', Product::query()->where('tier', Product::TIER_LIVE)->select('id'))
            ->where(fn ($w) => $w->where('status', 'failed')->orWhere(fn ($w) => $w->where('status', 'stored')->where('message', 'like', 'Kept for emails; too large%')));
        $rows = (clone $q)->with('product:id,name,code')->orderByDesc('updated_at')->limit(20)->get();

        return ['title' => 'Branding templates that could not be read', 'action' => true, 'count' => (clone $q)->count(),
            'why' => 'A failed read is retried with "Read again"; a file over 20 MB or not a PDF is still sent with quotes, but gives no mockup.',
            'rows' => $rows->map(fn ($t) => ['id' => $t->id, 'product_id' => $t->product_id, 'name' => $t->product?->name, 'code' => $t->product?->code,
                'status' => $t->status, 'message' => $t->message, 'file' => $t->filename])->all(),
            'waiting' => BrandingTemplate::query()->where('status', 'new')->whereIn('product_id', Product::query()->where('tier', Product::TIER_LIVE)->select('id'))->count()];
    }

    private function publishing(): array
    {
        $failures = collect(rescue(fn () => app(PublishHealth::class)->summary()['failures'], [], false));

        return ['title' => 'Shopify uploads that failed', 'action' => true, 'count' => $failures->count(),
            'why' => 'Each is retried automatically; listed while the last try failed (last 24 hours).',
            'rows' => $failures->take(15)->map(fn ($f) => ['product_id' => $f->product_id, 'name' => $f->name, 'message' => $f->message])->all(),
            'more' => route('admin.shopify-publishing.index')];
    }

    private function prices($names): array
    {
        $patterns = PricePattern::query()->where('live_count', '>', 0)->orderByDesc('live_count')->get(['signature', 'supplier_id', 'label', 'live_count']);
        $statuses = $patterns->isEmpty() ? [] : app(PricePatterns::class)->statuses($patterns->pluck('signature')->all());
        $open = $patterns->filter(fn ($p) => in_array($statuses[$p->signature]['status'] ?? 'unverified', ['unverified', 'mixed'], true))->values();
        $flagged = Product::query()->where('tier', Product::TIER_LIVE)->where(fn ($q) => collect(PricePatterns::FLAGS)->keys()
            ->each(fn ($f) => $q->orWhere('price_flags', 'like', '%"'.$f.'":true%')))->count();

        return ['title' => 'Price check', 'action' => true, 'count' => $open->count(),
            'why' => 'Is Promodata\'s base price the blank price or the branded price? Price patterns on the range not yet confirmed from a supplier page.',
            'rows' => $open->take(12)->map(fn ($p) => ['supplier' => $names[$p->supplier_id] ?? null, 'label' => $p->label, 'on_range' => (int) $p->live_count,
                'status' => $statuses[$p->signature]['status'] ?? 'unverified'])->all(),
            'flagged' => $flagged, 'products' => (int) $open->sum('live_count'), 'more' => route('admin.price-check.index')];
    }

    private function options($names): array
    {
        $cached = Cache::get('vet:options', ['lines' => []]);
        $lines = collect($cached['lines']);

        return ['title' => 'Add-on lines no supplier rule groups yet', 'action' => false, 'count' => $lines->count(),
            'why' => 'They show on the product page as Promodata words them, priced as add-ons. A rule from the supplier\'s pages would group them (clip choices, packaging…): Claude adds these.',
            'rows' => $lines->take(20)->map(fn ($l) => $l + ['supplier' => $names[$l['supplier_id']] ?? null])->all(), 'at' => $cached['at'] ?? null];
    }

    private function mockups($names): array
    {
        $r = Cache::get('positions:report', []);
        $m = $r['mockups'] ?? null;

        return ['title' => 'Mockups', 'action' => false, 'count' => (int) ($m['guide'] ?? 0),
            'why' => 'A logo is drawn on the photo only where it can be trusted: a template read, a staff position, or a garment with the supplier\'s size chart. The rest show a guide card.',
            'summary' => $m, 'by_supplier' => collect($m['guide_by_supplier'] ?? [])->sortDesc()->mapWithKeys(fn ($n, $sid) => [$names[$sid] ?? "Supplier {$sid}" => $n])->all(),
            'garments' => Product::query()->whereKey($m['garment_no_chart'] ?? [])->limit(20)->get(['id', 'name', 'code'])->all(),
            'photos' => ['checked' => PhotoBranding::query()->count(), 'branded' => PhotoBranding::query()->where('branding', '!=', PhotoBrandings::NONE)->count(),
                'unusable' => PhotoBranding::query()->where(fn ($q) => $q->whereNull('branding')->orWhere('branding', PhotoBrandings::LARGE)->orWhere('plain', false))->count()],
            'at' => $r['at'] ?? null, 'more' => route('range.positions')];
    }
}
