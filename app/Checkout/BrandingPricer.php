<?php

namespace App\Checkout;

use App\Catalogue\ProductOptions;
use App\Models\DecTypeCode;
use App\Models\PriceOption;
use App\Models\Product;
use App\Pricing\Money;
use Illuminate\Support\Collection;

/**
 * Prices one branding position from the product's own decoration options
 * (the SaaS pricing, the same options DecorationJSON lists), at the order's
 * total quantity, by method (docs/branding-templates.md, Scott 1 Nov 2026):
 *  - screen print: the price line for the number of colours (above the
 *    largest line, the top line, flagged for the team);
 *  - pad print: the single colour price (setup and unit) × colours;
 *  - embroidery, DTF, oversize DTF: one price whatever the colours.
 */
class BrandingPricer
{
    /** @return Collection<string, PriceOption> option id => decoration option of the decoration product */
    public function options(int $decorationProductId): Collection
    {
        $lines = PriceOption::query()->where('product_id', $decorationProductId)->where('type_code', DecTypeCode::DECORATION)
            ->whereNotNull('price_string')->where('price_string', '!=', '')->orderBy('position')->get();
        $claimed = app(ProductOptions::class)->claimed((int) Product::query()->whereKey($decorationProductId)->value('supplier_id'), $lines);

        return $lines->reject(fn ($o) => in_array($o->option_id, $claimed, true))->keyBy('option_id'); // product options aren't branding
    }

    /**
     * @param  Collection<string, PriceOption>  $options
     * @return array<string, mixed>|null null when the option is not one of this product's decorations
     */
    public function price(Collection $options, string $decId, ?int $colours, int $qty): ?array
    {
        $chosen = $options->get($decId);
        if ($chosen === null) {
            return null;
        }
        $label = fn (PriceOption $o) => (string) ($o->display_description ?: $o->description);
        $method = DecorationMethods::classify($label($chosen));
        $pricing = DecorationMethods::pricing($method);
        $solid = in_array($method, ['screen', 'pad', 'embroidery'], true);
        $colours = $solid ? max(1, (int) ($colours ?: 1)) : null;
        $over = false;

        $priced = $chosen;
        $times = 1;
        if ($method !== null && $pricing !== 'flat') {
            // Lines of the same method, by the colour count in their names (no count = 1 colour).
            $family = $options->filter(fn (PriceOption $o) => DecorationMethods::classify($label($o)) === $method)
                ->map(fn (PriceOption $o) => ['o' => $o, 'n' => DecorationMethods::colourCount($label($o)) ?? 1])
                ->sortBy('n')->values();
            if ($pricing === 'grid') {
                $hit = $family->first(fn ($f) => $f['n'] >= $colours) ?? $family->last();
                $priced = $hit['o'];
                $over = $colours > $hit['n'];
            } else { // per_colour
                $priced = $family->first()['o'];
                $times = $colours;
            }
        }
        $max = config("website.methods.{$method}.max_colours");
        $over = $over || ($max && $colours && $colours > $max);

        return [
            'method' => $method,
            'method_name' => DecorationMethods::name($method),
            'decoration_option_id' => $priced->option_id,
            'decoration_name' => $label($priced),
            'colours' => $colours,
            'unit' => Money::roundToCent($priced->prices()->priceAt($qty) * $times),
            'setup' => Money::roundToCent((float) $priced->setup_price * $times),
            'needs_review' => DecorationMethods::needsReview($method),
            'over_colour_limit' => (bool) $over,
        ];
    }
}
