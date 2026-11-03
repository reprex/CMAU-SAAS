<?php

namespace App\Catalogue;

use App\Models\PricePattern;
use App\Models\Product;
use App\Pricing\PricePatterns;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Is a new product ready to go on the range without a closer look (Scott, 3 Nov 2026; docs/range.md)?
 * Checks it against similar products already on the range, so staff can approve the ready ones in one
 * go and look only at the rest:
 *
 *  - priced: the SaaS priced it (TOD way) with a branded price and an MOQ;
 *  - price: its price each is within half to double the usual for its product type on the range;
 *  - markup: price ÷ supplier cost is within 30% of the usual for that supplier and type;
 *  - pattern: Promodata's prices are built the same way as products already on the range, no price-check flags;
 *  - branding: positions come from the supplier's data (or a staff rule), not only the type's default box;
 *  - pictures: a hero picture and a picture for every colour;
 *  - category: its subcategory already has a collection on the store.
 *
 * "Similar" means live products of the same supplier and Promodata type when there are 3 or more,
 * else the same type from any supplier.
 */
class ReadinessCheck
{
    private const SIMILAR_MIN = 3;

    public function __construct(private readonly PositionResolver $positions, private readonly ImageTagger $images) {}

    /** @return array{ready: bool, checks: list<array{key: string, ok: bool, label: string, detail: string}>, price: ?float, usual: ?float} */
    public function check(Product $product): array
    {
        $product->loadMissing(['webPrice', 'images', 'colours', 'override', 'supplier']);
        $checks = [];
        $add = function (string $key, bool $ok, string $label, string $detail) use (&$checks) {
            $checks[] = compact('key', 'ok', 'label', 'detail');
        };

        // Priced
        $wp = $product->webPrice;
        $price = $wp?->collection_variant_price !== null ? (float) $wp->collection_variant_price : null;
        $moq = (int) ($wp?->collection_moq ?? 0);
        if ($product->reprice_error) {
            $add('priced', false, 'Not priced', $product->reprice_error);
        } elseif (! $price || $price <= 0 || $moq <= 0) {
            $add('priced', false, 'Not priced', 'No branded price or MOQ worked out.');
        } elseif (! $wp->decoration_available) {
            $add('priced', false, 'No branding option', 'Priced, but Promodata gives it no decoration to price.');
        } else {
            $add('priced', true, 'Priced', '$'.number_format($price, 2).' each branded, MOQ '.$moq.'.');
        }

        // Price and markup against similar products on the range
        [$similar, $scope] = $this->similar($product);
        $usual = $this->median($similar->pluck('price'));
        if ($price && $usual) {
            $r = $price / $usual;
            $add('price', $r >= 0.5 && $r <= 2.0, $r >= 0.5 && $r <= 2.0 ? 'Price in line' : ($r < 0.5 ? 'Price low' : 'Price high'),
                '$'.number_format($price, 2).' vs usual $'.number_format($usual, 2).' for '.$similar->count().' '.$scope.'.');
        } elseif ($price) {
            $add('price', false, 'Nothing similar', 'No similar products on the range to compare the price with.');
        }
        $cost = (float) ($product->min_unit_cost ?? 0);
        $markups = $similar->filter(fn ($s) => $s->cost > 0)->map(fn ($s) => $s->price / $s->cost);
        $usualMarkup = $this->median($markups->values());
        if ($price && $cost > 0 && $usualMarkup) {
            $m = ($price / $cost) / $usualMarkup;
            $add('markup', abs($m - 1) <= 0.3, abs($m - 1) <= 0.3 ? 'Markup in line' : 'Markup differs',
                'Price is '.number_format($price / $cost, 2).'× cost vs usual '.number_format($usualMarkup, 2).'×.');
        }

        // Price pattern (docs/price-check.md)
        $flags = array_values(array_filter((array) $product->price_flags));
        $pattern = $product->price_pattern ? PricePattern::query()->find($product->price_pattern) : null;
        if ($flags) {
            $add('pattern', false, 'Price check flag', collect($flags)->map(fn ($f) => PricePatterns::FLAGS[$f] ?? $f)->implode('; ').'.');
        } elseif ($pattern && $pattern->live_count > 0) {
            $add('pattern', true, 'Priced like the range', 'Promodata prices it the same way as '.$pattern->live_count.' products on the range.');
        } else {
            $add('pattern', false, 'New price structure', 'No product on the range has its prices built this way yet.');
        }

        // Branding positions
        try {
            $sources = collect($this->positions->forShopify($product))->pluck('source')->unique()->values()->all();
        } catch (Throwable $e) {
            $sources = [];
        }
        $good = array_intersect($sources, ['template', 'staff', 'decoration', 'garment']);
        $add('branding', $good !== [], $good !== [] ? 'Branding positions' : 'Default position only',
            $good !== [] ? 'From '.implode(', ', array_map(fn ($s) => ['template' => 'the supplier template', 'staff' => 'a staff rule', 'decoration' => 'Promodata print areas', 'garment' => 'garment positions'][$s], $good)).'.'
                : 'Only the type\'s default box: check the logo area after adding.');

        // Pictures
        $hero = $this->images->hero($product);
        $shop = $this->images->forShopify($product);
        $missing = collect($shop['colours'])->filter(fn ($c) => empty($c['image']))->keys();
        $add('pictures', $hero && $missing->isEmpty(), $hero ? ($missing->isEmpty() ? 'Pictures' : 'Colour pictures missing') : 'No hero picture',
            $hero ? ($missing->isEmpty() ? count($shop['colours']).' colours, all with pictures.' : 'No picture for '.$missing->take(5)->implode(', ').($missing->count() > 5 ? '…' : '').'.') : 'No style picture to show on the collection page.');

        // Category
        $collection = $product->promodata_type_name ? DB::table('shopify_collections')->where('product_type', $product->promodata_type_name)->exists() : false;
        $add('category', $collection, $collection ? 'Category' : 'New subcategory',
            $collection ? (string) $product->promodata_type_name : 'No store collection for '.($product->promodata_type_name ?: 'its type').' yet: run the collections sync after adding.');

        return ['ready' => collect($checks)->every('ok'), 'checks' => $checks, 'price' => $price, 'usual' => $usual];
    }

    /** @return array{0: Collection<int, object{price: float, cost: float}>, 1: string} */
    private function similar(Product $product): array
    {
        $base = fn () => DB::table('products')->join('product_web_prices as w', 'w.product_id', '=', 'products.id')
            ->where('products.tier', Product::TIER_LIVE)->where('products.discontinued', false)->where('products.id', '!=', $product->id)
            ->where('w.collection_variant_price', '>', 0)->where('products.promodata_type_name', $product->promodata_type_name)
            ->select(['w.collection_variant_price as price', 'products.min_unit_cost as cost']);
        if (! $product->promodata_type_name) {
            return [collect(), ''];
        }
        $same = $base()->where('products.supplier_id', $product->supplier_id)->limit(500)->get();
        if ($same->count() >= self::SIMILAR_MIN) {
            return [$same->map(fn ($r) => (object) ['price' => (float) $r->price, 'cost' => (float) $r->cost]), 'similar from this supplier'];
        }
        $any = $base()->limit(500)->get();

        return [$any->count() >= self::SIMILAR_MIN ? $any->map(fn ($r) => (object) ['price' => (float) $r->price, 'cost' => (float) $r->cost]) : collect(), 'similar on the range'];
    }

    private function median($values): ?float
    {
        $v = collect($values)->filter(fn ($x) => $x > 0)->sort()->values();
        if ($v->isEmpty()) {
            return null;
        }
        $n = $v->count();

        return $n % 2 ? (float) $v[intdiv($n, 2)] : ((float) $v[$n / 2 - 1] + (float) $v[$n / 2]) / 2;
    }
}
