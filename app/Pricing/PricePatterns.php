<?php

namespace App\Pricing;

use App\Models\PriceCheck;
use App\Models\PriceLine;
use App\Models\PricePattern;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Groups products by how their Promodata prices are built, so one verified
 * screengrab covers every product priced the same way (docs/price-check.md).
 *
 * A pattern is the supplier plus each base price line's wording (lower case,
 * numbers replaced by #), whether it is marked undecorated, whether it names a
 * decoration method, whether it has a setup charge and whether it has add-on
 * lines. Add-on wording is left out: surcharges vary by product and don't
 * decide whether the base price includes branding.
 *
 * Per-product flags catch products that don't fit:
 *  - branded_below_unbranded: a decorated base costs less than the unbranded line
 *  - rising_breaks: a unit cost goes up as the quantity rises
 *  - ratio_outlier: branded ÷ unbranded is far from the pattern's usual ratio
 *  - changed_since_check: Promodata prices changed after the product was checked
 */
class PricePatterns
{
    public const FLAGS = [
        'branded_below_unbranded' => 'Branded base costs less than unbranded',
        'rising_breaks' => 'Unit cost rises with quantity',
        'ratio_outlier' => "Branded ÷ unbranded far from the pattern's usual",
        'changed_since_check' => 'Prices changed since it was checked',
    ];

    /** Ratio more than this many times above or below the pattern median is an outlier. */
    private const OUTLIER_FACTOR = 1.5;

    public static function normalise(?string $text): string
    {
        $text = mb_strtolower((string) $text);
        $text = preg_replace('/\d[\d,.]*/', '#', $text);
        $text = preg_replace('/[^a-z#]+/', ' ', $text);

        return trim(preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Work out the product's pattern and flags from its stored price lines and
     * save them. Products without price lines are left alone.
     */
    public function assign(Product $product): void
    {
        /** @var Collection<int, PriceLine> $lines */
        $lines = $product->priceLines()->with('breaks')->get();
        if ($lines->isEmpty()) {
            return;
        }

        $bases = $lines->whereNull('base_price_line_id');
        $withAdditions = $lines->whereNotNull('base_price_line_id')->pluck('base_price_line_id')->flip();

        $structure = $bases->map(fn (PriceLine $l) => [
            'wording' => self::normalise($l->description),
            'undecorated' => (bool) $l->undecorated,
            'method' => filled($l->promodata_decoration),
            'setup' => (float) $l->setup_cost > 0,
            'additions' => $withAdditions->has($l->id),
        ])->unique(fn ($b) => json_encode($b))->sortBy(fn ($b) => json_encode($b))->values()->all();

        $signature = sha1($product->supplier_id.'|'.json_encode($structure));
        $label = collect($structure)->map(fn ($b) => '“'.($b['wording'] ?: '(no wording)').'”'
            .($b['undecorated'] ? ' unbranded' : '')
            .($b['method'] ? ' · method named' : '')
            .($b['setup'] ? ' · setup' : '')
            .($b['additions'] ? ' · add-ons' : ''))->implode('  +  ');

        PricePattern::query()->firstOrCreate(['signature' => $signature], [
            'supplier_id' => $product->supplier_id,
            'label' => mb_substr($label, 0, 1024),
            'structure' => $structure,
        ]);

        $flags = $product->price_flags ?? [];
        unset($flags['branded_below_unbranded'], $flags['rising_breaks'], $flags['changed_since_check']);
        $flags['ratio'] = $this->ratio($bases);

        if ($this->brandedBelowUnbranded($bases)) {
            $flags['branded_below_unbranded'] = true;
        }
        if ($lines->contains(fn (PriceLine $l) => $this->rises($l))) {
            $flags['rising_breaks'] = true;
        }
        $lastCheck = PriceCheck::query()->where('product_id', $product->id)->latest('id')->first();
        if ($lastCheck && $product->prices_changed_at && $lastCheck->prices_changed_at
            && $product->prices_changed_at->gt($lastCheck->prices_changed_at)) {
            $flags['changed_since_check'] = true;
        }
        if ($product->price_pattern !== $signature) {
            unset($flags['ratio_outlier']); // re-judged against the new pattern on the next refresh
        }

        $product->forceFill(['price_pattern' => $signature, 'price_flags' => $flags])->saveQuietly();
    }

    /**
     * Recount every pattern, pick the example products to screengrab and mark
     * ratio outliers. Cheap enough for the nightly cron: one pass over the
     * product rows that have a pattern.
     */
    public function refresh(): int
    {
        $rows = Product::query()->whereNotNull('price_pattern')
            ->orderBy('id')
            ->get(['id', 'tier', 'price_pattern', 'price_flags', 'last_used_at', 'discontinued']);

        $byPattern = $rows->groupBy('price_pattern');
        foreach ($byPattern as $signature => $products) {
            $ratios = $products->pluck('price_flags.ratio')->filter(fn ($r) => is_numeric($r) && $r > 0)->sort()->values();
            $median = $ratios->isEmpty() ? null : (float) $ratios[intdiv($ratios->count(), 2)];

            $flagged = 0;
            foreach ($products as $p) {
                $flags = $p->price_flags ?? [];
                $ratio = $flags['ratio'] ?? null;
                $outlier = $median && is_numeric($ratio) && $ratio > 0 && $ratios->count() >= 3
                    && ($ratio > $median * self::OUTLIER_FACTOR || $ratio < $median / self::OUTLIER_FACTOR);
                if ($outlier !== (bool) ($flags['ratio_outlier'] ?? false)) {
                    if ($outlier) {
                        $flags['ratio_outlier'] = true;
                    } else {
                        unset($flags['ratio_outlier']);
                    }
                    DB::table('products')->where('id', $p->id)->update(['price_flags' => json_encode($flags)]);
                }
                if (array_intersect_key($flags, self::FLAGS) !== []) {
                    $flagged++;
                }
            }

            // Screengrab examples: live products first, then the most recently used; never discontinued.
            $examples = $products->where('discontinued', false)
                ->sortBy(fn ($p) => [$p->tier === Product::TIER_LIVE ? 0 : 1, -($p->last_used_at?->timestamp ?? 0), $p->id])
                ->take(2)->pluck('id')->values()->all();

            PricePattern::query()->whereKey($signature)->update([
                'product_count' => $products->count(),
                'live_count' => $products->where('tier', Product::TIER_LIVE)->count(),
                'flagged_count' => $flagged,
                'examples' => json_encode($examples),
                'median_ratio' => $median,
                'updated_at' => now(),
            ]);
        }

        // Patterns no product uses any more.
        PricePattern::query()->whereNotIn('signature', $byPattern->keys()->all())->delete();

        return $byPattern->count();
    }

    /**
     * Pattern status from the checks recorded against it: unverified, one of
     * the PriceCheck verdicts (all checks agree) or "mixed".
     *
     * @param  list<string>  $signatures
     * @return array<string, array{status: string, checks: int}>
     */
    public function statuses(array $signatures): array
    {
        $out = [];
        PriceCheck::query()->whereIn('price_pattern', $signatures)
            ->get(['price_pattern', 'verdict'])->groupBy('price_pattern')
            ->each(function ($checks, $signature) use (&$out) {
                $verdicts = $checks->pluck('verdict')->unique();
                $out[$signature] = ['status' => $verdicts->count() === 1 ? $verdicts->first() : 'mixed', 'checks' => $checks->count()];
            });

        return $out;
    }

    /** Lowest-quantity decorated base cost ÷ unbranded cost at that quantity. */
    private function ratio(Collection $bases): ?float
    {
        $unbranded = $bases->firstWhere('undecorated', true);
        $branded = $bases->first(fn (PriceLine $l) => ! $l->undecorated && $l->breaks->isNotEmpty());
        if (! $unbranded || ! $branded || $unbranded->breaks->isEmpty()) {
            return null;
        }
        $first = $branded->breaks->first();
        $blank = $this->costAt($unbranded, (int) $first->qty);

        return $blank > 0 ? round((float) $first->unit_cost / $blank, 4) : null;
    }

    private function brandedBelowUnbranded(Collection $bases): bool
    {
        $unbranded = $bases->firstWhere('undecorated', true);
        if (! $unbranded || $unbranded->breaks->isEmpty()) {
            return false;
        }

        return $bases->contains(fn (PriceLine $l) => ! $l->undecorated && $l->breaks->contains(
            fn ($b) => (float) $b->unit_cost + 0.0001 < $this->costAt($unbranded, (int) $b->qty)
        ));
    }

    private function rises(PriceLine $line): bool
    {
        $previous = null;
        foreach ($line->breaks as $break) {
            if ($previous !== null && (float) $break->unit_cost > $previous + 0.0001) {
                return true;
            }
            $previous = (float) $break->unit_cost;
        }

        return false;
    }

    /** Cost at a quantity: the break with the largest qty not above it, else the first break. */
    private function costAt(PriceLine $line, int $qty): float
    {
        $break = $line->breaks->filter(fn ($b) => (int) $b->qty <= $qty)->last() ?? $line->breaks->first();

        return (float) $break->unit_cost;
    }
}
