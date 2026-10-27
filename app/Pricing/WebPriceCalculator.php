<?php

namespace App\Pricing;

use App\Models\DecTypeCode;
use App\Models\PriceOption;
use App\Models\PriceOptionSetting;
use App\Models\Product;
use App\Models\ProductWebPrice;
use Illuminate\Support\Collection;

/**
 * Derives a product's web pricing from its price options, following TOD's
 * "refresh MOQ and OrdVal" and DecorationJSON macros:
 *
 *  - Decoration source: MANUAL (staff picked an option), EXTERNAL (decorations
 *    borrowed from another product, e.g. Melbourne Merch 10001999), else AUTO.
 *  - Default decoration: staff pick, else the default carried over from TOD,
 *    else (see defaultDecoration) the borrowed price file's first option, the
 *    staff default flag, or the cheapest non-zero decoration cost at 100.
 *  - ActualMOQ = larger of the undecorated and default-decoration minimum
 *    quantities, unless staff set an override. CollectionMOQ defaults to it.
 *  - CollectionMOQ: staff override, else the supplier rule (JB's and AS Colour
 *    50, Shiny at least 20), else ActualMOQ.
 *  - Order value at a quantity = decoration price x qty + undecorated setup
 *    + undecorated price x qty, plus the decoration setup when the product
 *    uses its own decorations (see pricing.order_value_decoration_setup).
 *  - Collection "from" price = order value at CollectionMOQ / CollectionMOQ.
 *
 * Staff overrides are read from product_overrides.data:
 *   decoration_product_id, decoration_option_id, actual_moq, collection_moq,
 *   moq_one, carried_default_decoration_option_id (set by the TOD import)
 */
class WebPriceCalculator
{
    public function __construct(private readonly DecorationJson $decorationJson) {}

    public function calculate(Product $product): ProductWebPrice
    {
        $product->loadMissing('override');
        $own = $product->priceOptions()->get();

        $decorationProductId = (int) ($product->overrideValue('decoration_product_id') ?: $product->id);
        $decorationOptions = $decorationProductId === $product->id
            ? $own
            : PriceOption::query()->where('product_id', $decorationProductId)->orderBy('position')->get();

        $decorations = $decorationOptions->where('type_code', DecTypeCode::DECORATION)->values();
        $accessories = $decorationOptions->where('type_code', DecTypeCode::ACCESSORY)->values();
        $undecorated = $own->firstWhere('type_code', DecTypeCode::UNDECORATED);

        $manualOptionId = $product->overrideValue('decoration_option_id');
        $source = match (true) {
            $manualOptionId !== null => 'MANUAL',
            $decorationProductId !== $product->id => 'EXTERNAL',
            default => 'AUTO',
        };

        // A staff pick wins; then the default carried over from TOD at cutover
        // (Scott, 24 Oct 2026: keep TOD's picks so the site does not change).
        $default = $this->defaultDecoration(
            $product,
            $decorations,
            $manualOptionId ?? $product->overrideValue('carried_default_decoration_option_id'),
        );

        $undecMoq = $undecorated?->costs()->minQty();
        $decMoq = $default?->costs()->minQty();
        $actualMoq = (int) $product->overrideValue('actual_moq', 0) > 0
            ? (int) $product->overrideValue('actual_moq')
            : max((int) $undecMoq, (int) $decMoq);
        $actualMoq = $actualMoq > 0 ? $actualMoq : null;

        $collectionMoq = (int) $product->overrideValue('collection_moq', 0) > 0
            ? (int) $product->overrideValue('collection_moq')
            : SupplierRules::for($product->supplier_id)->collectionMoq($actualMoq);

        $includeDecorationSetup = match (config('pricing.order_value_decoration_setup', 'own')) {
            'always' => true,
            'never' => false,
            default => $decorationProductId === $product->id,
        };
        $orderValue = fn (?int $qty) => $qty ? $this->orderValue($default, $undecorated, $qty, $includeDecorationSetup) : null;
        $display = $orderValue($collectionMoq);

        $warnings = $this->warnings($own, $undecorated);

        return ProductWebPrice::query()->updateOrCreate(['product_id' => $product->id], [
            'decoration_product_id' => $decorationProductId,
            'decoration_source' => $source,
            'undecorated_option_id' => $undecorated?->option_id,
            'default_decoration_option_id' => $default?->option_id,
            // TOD WebDecAvailableFlag: any type-10 option, including the placeholder.
            'decoration_available' => $decorations->isNotEmpty(),
            'zero_price_decoration' => $own->where('type_code', DecTypeCode::DECORATION)
                ->where('placeholder', false)
                ->contains(fn (PriceOption $o) => $o->costs()->priceAt(100) == 0),
            'undecorated_moq' => $undecMoq,
            'decoration_moq' => $decMoq,
            'actual_moq' => $actualMoq,
            'collection_moq' => $collectionMoq,
            'order_value_100' => $orderValue(100),
            'order_value_moq' => $orderValue($actualMoq),
            'order_value_display' => $display,
            'collection_variant_price' => ($display !== null && $collectionMoq) ? Money::currency($display / $collectionMoq) : null,
            'unbranded_option_count' => $own->where('type_code', DecTypeCode::UNDECORATED)->where('placeholder', false)->count(),
            'decoration_option_count' => $own->where('type_code', DecTypeCode::DECORATION)->where('placeholder', false)->count(),
            'decoration_json' => $this->decorationJson->build(
                // TOD's WebDecAvailableFlag: any type-10 option, including the
                // "only available unbranded" placeholder (12 live products in Jul 2026).
                brandingAvailable: $decorations->isNotEmpty(),
                undecorated: $undecorated,
                decorations: $decorations,
                sortGroups: $this->sortGroups($decorations),
            ),
            'accessory_list' => $accessories->isEmpty() ? null : $accessories->pluck('description')->implode(' | '),
            'warnings' => $warnings ?: null,
            'computed_at' => now(),
        ]);
    }

    /**
     * DecorationJSON display groups: the json_sort setting, else group 1 for a
     * staff default decoration (TOD sets DecJSONSort 1 on the default).
     *
     * @param  Collection<int, PriceOption>  $decorations
     * @return array<string, int>
     */
    private function sortGroups(Collection $decorations): array
    {
        $groups = [];
        $settings = PriceOptionSetting::query()->whereIn('option_id', $decorations->pluck('option_id'))->get();
        foreach ($settings as $setting) {
            if ($setting->json_sort !== null) {
                $groups[$setting->option_id] = $setting->json_sort;
            } elseif ($setting->is_default) {
                $groups[$setting->option_id] = 1;
            }
        }

        return $groups;
    }

    /** @param  Collection<int, PriceOption>  $decorations */
    private function defaultDecoration(Product $product, Collection $decorations, ?string $manualOptionId): ?PriceOption
    {
        if ($manualOptionId !== null && ($manual = $decorations->firstWhere('option_id', $manualOptionId))) {
            return $manual;
        }

        // Borrowed decorations from a price-file product (Melbourne Merch, id > 999999):
        // its first-listed option (TOD "set product DefaultDecCPSID if external decoration").
        $decorationProductId = (int) ($decorations->first()?->product_id ?? $product->id);
        if ($decorationProductId !== $product->id && $decorationProductId > 999999) {
            $first = $this->sortGroups($decorations);
            $leadId = collect($first)->filter(fn (int $g) => $g === 1)->keys()->first();
            if ($leadId !== null && ($lead = $decorations->firstWhere('option_id', $leadId))) {
                return $lead;
            }
        }

        $staffDefaultId = PriceOptionSetting::query()
            ->whereIn('option_id', $decorations->pluck('option_id'))
            ->where('is_default', true)
            ->value('option_id');
        if ($staffDefaultId !== null) {
            return $decorations->firstWhere('option_id', $staffDefaultId);
        }

        // Cheapest decoration with a non-zero cost at 100 units (TOD DefaultDecCost100).
        $cheapest = $decorations
            ->filter(fn (PriceOption $o) => $o->costs()->priceAt(100) != 0)
            ->sortBy(fn (PriceOption $o) => $o->costs()->priceAt(100))
            ->first();

        // No priced decoration (e.g. only the "only available unbranded"
        // placeholder): no default, as in TOD.
        return $cheapest;
    }

    private function orderValue(?PriceOption $decoration, ?PriceOption $undecorated, int $qty, bool $includeDecorationSetup): float
    {
        $value = 0.0;
        if ($decoration !== null) {
            $value += Money::currency($decoration->prices()->priceAt($qty) * $qty);
            if ($includeDecorationSetup) {
                $value += (float) $decoration->setup_price;
            }
        }
        if ($undecorated !== null) {
            $value += Money::currency($undecorated->setup_price + $qty * $undecorated->prices()->priceAt($qty));
        }

        return Money::currency($value);
    }

    /**
     * @param  Collection<int, PriceOption>  $own
     * @return list<string>
     */
    private function warnings(Collection $own, ?PriceOption $undecorated): array
    {
        $warnings = [];

        $unclassified = $own->whereNull('type_code')->pluck('description');
        if ($unclassified->isNotEmpty()) {
            $warnings[] = 'Unclassified price lines (priced with the accessory markup): '.$unclassified->implode('; ');
        }
        if ($undecorated === null || $undecorated->placeholder) {
            $warnings[] = 'No undecorated price: the product shows decoration prices only.';
        }

        return $warnings;
    }
}
