<?php

namespace App\Http\Controllers;

use App\Catalogue\ImageTagger;
use App\Catalogue\RangeManager;
use App\Jobs\RangeBulkAction;
use App\Models\AuditLog;
use App\Models\DecTypeCode;
use App\Models\PriceOption;
use App\Models\PriceOptionSetting;
use App\Models\Product;
use App\Models\ProductOverride;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Range curation: browse the supplier catalogue, add and remove products from
 * the CrowdMerch web range, and curate each product (overrides, decoration
 * choices, colours, images). Pricing follows TOD exactly (docs/pricing.md).
 */
class RangeController extends Controller
{
    public const VIEWS = [
        'live' => 'On the range',
        'changed' => 'Changed, not yet published',
        'problems' => 'Needs attention',
        'active' => 'Recently used',
        'all' => 'Whole catalogue',
    ];

    /** Override keys staff can set on the product screen (docs/range.md). */
    public const OVERRIDE_KEYS = ['title', 'description', 'subcategory', 'hero_image_url', 'image_tags', 'hidden_colours', 'notes',
        'decoration_option_id', 'decoration_product_id', 'actual_moq', 'collection_moq', 'moq_one'];

    public function index(Request $request)
    {
        $view = array_key_exists($request->query('view'), self::VIEWS) ? $request->query('view') : 'live';
        $q = trim((string) $request->query('q'));
        $supplierId = $request->integer('supplier') ?: null;
        $type = trim((string) $request->query('type'));
        $showDiscontinued = $request->boolean('discontinued');
        $sort = in_array($request->query('sort'), ['name', 'changed', 'cost', 'id'], true) ? $request->query('sort') : 'name';

        $products = Product::query()
            ->select(['id', 'supplier_id', 'code', 'name', 'tier', 'discontinued', 'promodata_type_name', 'hero_image_url',
                'colour_count', 'min_unit_cost', 'min_qty', 'undecorated_moq', 'undecorated_cost', 'summary_version', 'last_changed_at', 'range_changed_at', 'published_at', 'reprice_error', 'hydrated_at'])
            ->with(['supplier:id,name', 'webPrice:product_id,collection_variant_price,collection_moq,decoration_source,actual_moq,undecorated_option_id'])
            ->when($view === 'live', fn ($w) => $w->where('tier', Product::TIER_LIVE))
            ->when($view === 'active', fn ($w) => $w->where('tier', Product::TIER_ACTIVE))
            ->when($view === 'changed', fn ($w) => $w->where('tier', Product::TIER_LIVE)->whereNotNull('range_changed_at')
                ->where(fn ($w) => $w->whereNull('published_at')->orWhereColumn('range_changed_at', '>', 'published_at')))
            ->when($view === 'problems', fn ($w) => $w->where('tier', Product::TIER_LIVE)
                ->where(fn ($w) => $w->whereNotNull('reprice_error')->orWhere('discontinued', true)))
            ->when(! $showDiscontinued && in_array($view, ['all', 'active'], true), fn ($w) => $w->where('discontinued', false))
            ->when($q !== '', fn ($w) => $w->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%")
                ->when(ctype_digit($q), fn ($w) => $w->orWhere('id', (int) $q))))
            ->when($supplierId, fn ($w) => $w->where('supplier_id', $supplierId))
            ->when($type !== '', fn ($w) => $w->where('promodata_type_name', $type))
            ->when($sort === 'name', fn ($w) => $w->orderBy('name'))
            ->when($sort === 'changed', fn ($w) => $w->orderByDesc('last_changed_at'))
            ->when($sort === 'cost', fn ($w) => $w->orderBy('min_unit_cost'))
            ->when($sort === 'id', fn ($w) => $w->orderBy('id'))
            ->paginate(50)->withQueryString();

        // Supplier's unbranded cost at the minimum order, for the products on this page only.
        $undecorated = PriceOption::query()
            ->whereIn('option_id', $products->getCollection()->pluck('webPrice.undecorated_option_id')->filter()->all())
            ->get(['option_id', 'cost_string', 'placeholder'])->keyBy('option_id');

        $counts = Cache::remember('range:counts', 60, fn () => [
            'live' => Product::query()->where('tier', Product::TIER_LIVE)->count(),
            'active' => Product::query()->where('tier', Product::TIER_ACTIVE)->count(),
            'all' => Product::query()->count(),
            'problems' => Product::query()->where('tier', Product::TIER_LIVE)->where(fn ($w) => $w->whereNotNull('reprice_error')->orWhere('discontinued', true))->count(),
        ]);

        return view('range.index', [
            'products' => $products,
            'undecorated' => $undecorated,
            'view' => $view, 'q' => $q, 'supplierId' => $supplierId, 'type' => $type, 'sort' => $sort, 'showDiscontinued' => $showDiscontinued,
            'counts' => $counts,
            'suppliers' => Cache::remember('range:suppliers', 600, fn () => Supplier::query()->whereHas('products')->orderBy('name')->get(['id', 'name'])),
            'types' => Cache::remember('range:types', 600, fn () => Product::query()->whereNotNull('promodata_type_name')
                ->distinct()->orderBy('promodata_type_name')->pluck('promodata_type_name')),
        ]);
    }

    public function bulk(Request $request, RangeManager $range)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(RangeBulkAction::ACTIONS)],
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'integer',
        ]);
        $ids = array_values(array_unique(array_map('intval', $data['ids'])));
        Cache::forget('range:counts');

        // A single add/refresh runs now (one Promodata call); bigger batches go to the queue.
        if (count($ids) === 1 || in_array($data['action'], ['remove'], true)) {
            (new RangeBulkAction($data['action'], $ids, $request->user()->id))->handle($range);

            return back()->with('status', $this->doneMessage($data['action'], count($ids)));
        }

        foreach (array_chunk($ids, 25) as $chunk) {
            RangeBulkAction::dispatch($data['action'], $chunk, $request->user()->id);
        }

        return back()->with('status', sprintf('%d products queued to %s. They are processed in the background, about 25 a minute; refresh this page to see them change.', count($ids), $data['action']));
    }

    public function show(Product $product)
    {
        $product->load(['supplier', 'detail', 'colours', 'images', 'priceOptions', 'webPrice', 'override']);
        $imageTags = $product->images->isNotEmpty() ? app(ImageTagger::class)->tags($product) : collect();
        $settings = PriceOptionSetting::query()->where('product_id', $product->id)->get()->keyBy('option_id');
        $types = DecTypeCode::query()->whereIn('code', $product->priceOptions->pluck('code'))->get()->keyBy('code');

        $decorationProductId = (int) ($product->overrideValue('decoration_product_id') ?: $product->id);
        $decorationChoices = PriceOption::query()->where('product_id', $decorationProductId)
            ->where('type_code', DecTypeCode::DECORATION)->orderBy('position')->get();

        $history = AuditLog::query()->with('user:id,name')->where('subject_type', 'Product')->where('subject_id', (string) $product->id)
            ->latest('id')->limit(12)->get();

        return view('range.show', compact('product', 'settings', 'types', 'decorationChoices', 'history', 'imageTags'));
    }

    public function update(Request $request, Product $product, RangeManager $range)
    {
        $imageUrls = $product->images()->pluck('url')->push($product->hero_image_url)->filter()->unique()->values()->all();
        $colourNames = $product->colours()->pluck('name')->unique()->values()->all();

        $data = $request->validate([
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:20000',
            'subcategory' => 'nullable|string|max:120',
            'hero_image_url' => ['nullable', Rule::in($imageUrls)],
            'hidden_colours' => 'nullable|array',
            'hidden_colours.*' => [Rule::in($colourNames)],
            'notes' => 'nullable|string|max:2000',
            'decoration_option_id' => 'nullable|string|exists:price_options,option_id',
            'decoration_product_id' => 'nullable|integer|exists:products,id',
            'actual_moq' => 'nullable|integer|min:1|max:1000000',
            'collection_moq' => 'nullable|integer|min:1|max:1000000',
            'moq_one' => 'boolean',
            'images' => 'nullable|array|max:500',
            'images.*.url' => ['required', Rule::in($imageUrls)],
            'images.*.role' => ['required', Rule::in(array_keys(ImageTagger::ROLES))],
            'images.*.colour' => ['nullable', Rule::in($colourNames)],
        ]);
        if (array_key_exists('images', $data)) {
            [$data['hero_image_url'], $data['image_tags']] = $this->imageTags($product, $data['images'] ?? []);
        } else {
            $data['hero_image_url'] ??= $product->overrideValue('hero_image_url');
            $data['image_tags'] = $product->overrideValue('image_tags');
        }
        $data['moq_one'] = $request->boolean('moq_one') ?: null;
        $data['hidden_colours'] = array_values($data['hidden_colours'] ?? []) ?: null;
        if ((int) ($data['decoration_product_id'] ?? 0) === $product->id) {
            $data['decoration_product_id'] = null;
        }

        $current = $product->override?->data ?? [];
        $next = $current;
        foreach (self::OVERRIDE_KEYS as $key) {
            $value = $data[$key] ?? null;
            if ($value === null || $value === '') {
                unset($next[$key]);
            } else {
                $next[$key] = is_string($value) ? trim($value) : $value;
            }
        }

        if ($next !== $current) {
            if ($next === []) {
                ProductOverride::query()->whereKey($product->id)->delete();
            } else {
                ProductOverride::query()->updateOrCreate(['product_id' => $product->id], ['data' => $next, 'updated_by' => $request->user()->id]);
            }
            $product->unsetRelation('override');
            if ($product->tier === Product::TIER_LIVE) {
                $product->forceFill(['range_changed_at' => now()])->save();
            }
            AuditLog::record('range.curated', $product, ['before' => $current, 'after' => $next]);
        }

        app(ImageTagger::class)->apply($product);
        $error = $range->reprice($product);

        return redirect()->route('range.show', $product)->with('status', $error ? "Saved, but pricing failed: {$error}" : 'Saved and repriced.');
    }

    /**
     * Turn the image tag form into overrides: the chosen hero goes in
     * hero_image_url, and only tags that differ from the automatic ones are kept
     * in image_tags (keyed by URL, so they survive Promodata refreshes).
     *
     * @param  list<array{url:string, role:string, colour:?string}>  $rows
     * @return array{0: ?string, 1: ?array<string, array{role:string, colour:?string}>}
     */
    private function imageTags(Product $product, array $rows): array
    {
        $tagger = app(ImageTagger::class);
        $chosenHero = collect($rows)->firstWhere('role', ImageTagger::HERO_STYLE)['url'] ?? null;

        // The automatic tags if the chosen hero were the only staff choice.
        $data = $product->override?->data ?? [];
        unset($data['image_tags']);
        $data['hero_image_url'] = $chosenHero;
        $probe = clone $product;
        $probe->setRelation('override', new ProductOverride(['data' => $data]));
        $auto = $tagger->tags($probe)->keyBy('url');
        $heroOverride = $chosenHero && $chosenHero !== $product->hero_image_url ? $chosenHero : null;

        $tags = [];
        foreach ($rows as $row) {
            if ($row['role'] === ImageTagger::HERO_STYLE) {
                continue;
            }
            $colour = in_array($row['role'], [ImageTagger::COLOUR, ImageTagger::OTHER_COLOUR], true) ? ($row['colour'] ?? null) : null;
            $a = $auto[$row['url']] ?? null;
            if (! $a || $a['role'] !== $row['role'] || ($colour !== null && $a['colour'] !== $colour)) {
                $tags[$row['url']] = ['role' => $row['role'], 'colour' => $colour];
            }
        }

        return [$heroOverride, $tags ?: null];
    }

    public function options(Request $request, Product $product, RangeManager $range)
    {
        $data = $request->validate([
            'type' => 'array',
            'type.*' => ['nullable', Rule::in(['', '10', '11', '12', '23'])],
            'sort' => 'array',
            'sort.*' => 'nullable|integer|min:1|max:9',
        ]);
        $optionIds = $product->priceOptions()->pluck('option_id')->all();

        foreach ($optionIds as $optionId) {
            $type = $data['type'][$optionId] ?? '';
            $sort = $data['sort'][$optionId] ?? null;
            $setting = PriceOptionSetting::query()->firstOrNew(['option_id' => $optionId], ['product_id' => $product->id]);
            $setting->fill([
                'type_code_override' => $type === '' ? null : (int) $type,
                'json_sort' => $sort === null || $sort === '' ? null : (int) $sort,
                'updated_by' => $request->user()->id,
            ]);
            if ($setting->isDirty()) {
                $setting->save();
            }
        }
        AuditLog::record('range.options_set', $product, ['type' => array_filter($data['type'] ?? []), 'sort' => array_filter($data['sort'] ?? [])]);
        if ($product->tier === Product::TIER_LIVE) {
            $product->forceFill(['range_changed_at' => now()])->save();
        }

        $error = $range->reprice($product);

        return redirect()->route('range.show', $product)->with('status', $error ? "Saved, but pricing failed: {$error}" : 'Option settings saved and repriced.');
    }

    public function action(Request $request, Product $product, RangeManager $range)
    {
        $action = $request->validate(['action' => ['required', Rule::in(RangeBulkAction::ACTIONS)]])['action'];
        Cache::forget('range:counts');

        try {
            match ($action) {
                'add' => $range->add($product),
                'remove' => $range->remove($product),
                'refresh' => $range->refresh($product),
                'reprice' => ($error = $range->reprice($product)) === null ? null : throw new \RuntimeException($error),
            };
        } catch (Throwable $e) {
            return back()->withErrors(['range' => mb_substr($e->getMessage(), 0, 300)]);
        }

        return redirect()->route('range.show', $product)->with('status', $this->doneMessage($action, 1));
    }

    private function doneMessage(string $action, int $n): string
    {
        $what = $n === 1 ? 'product' : "{$n} products";

        return match ($action) {
            'add' => ucfirst("{$what} added to the range and priced."),
            'remove' => ucfirst("{$what} removed from the range. Details and overrides are kept."),
            'refresh' => ucfirst("{$what} refreshed from Promodata and repriced."),
            default => ucfirst("{$what} repriced."),
        };
    }
}
