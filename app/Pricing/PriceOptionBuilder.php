<?php

namespace App\Pricing;

use App\Models\DecTypeCode;
use App\Models\PriceLine;
use App\Models\PriceOption;
use App\Models\PriceOptionSetting;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Builds a product's cost and price options from its supplier price lines,
 * reproducing TOD's CostPriceBreak -> CostPriceSummary steps:
 *
 *  1. Each price line becomes an option keyed "APPAID-code", where the code is
 *     the description in lower-case letters and digits. Lines sharing a code
 *     are merged (the first line's setup and description are kept).
 *  2. The code is looked up in dec_type_codes (TOD: DecTypeCode). The unit
 *     markup is SupplierMarkupDec for type 10, SupplierMarkupUnDec for 12 and
 *     SupplierMarkupAcc for anything else (including unclassified codes).
 *     New codes are added to dec_type_codes unclassified, for staff to review.
 *  3. Unit price = cost x markup, rounded UP to the cent; setup price =
 *     setup cost x SupplierMarkupSetup.
 *  4. Staff type overrides (TOD: LocalPrice DecType) change the option's type
 *     but not the markup, exactly as TOD did.
 *  5. Zero-price placeholders are added when there is no undecorated (12) or
 *     no decoration (10) option.
 * External (EXT) options are left untouched.
 */
class PriceOptionBuilder
{
    /** @return Collection<int, PriceOption> */
    public function build(Product $product): Collection
    {
        if (! $product->keepsFullDetail() || $product->hydrated_at === null) {
            throw new RuntimeException("Product {$product->id} has no price lines stored; hydrate it first.");
        }

        $supplier = $product->supplier ?? new Supplier;
        $lines = $product->priceLines()->with('breaks')->get();

        $groups = $this->groupByCode($lines);
        $types = $this->typeCodes($groups, $product, $supplier);
        $settings = PriceOptionSetting::query()->where('product_id', $product->id)->get()->keyBy('option_id');

        $options = [];
        $position = 0;
        foreach ($groups as $code => $group) {
            /** @var PriceLine $first */
            $first = $group[0];
            $type = $types->get($code);
            $tableType = $type?->type_code;
            $markup = $this->unitMarkup($tableType, $supplier);
            $optionId = $product->id.'-'.$code;

            $costs = PriceString::fromBreaks(
                collect($group)->flatMap(fn (PriceLine $l) => $l->breaks->map(fn ($b) => ['qty' => $b->qty, 'price' => (float) $b->unit_cost]))
            );
            $prices = $costs->map(fn (float $cost) => Money::roundUpToCent(Money::applyMarkup($cost, $markup)));

            $override = $settings->get($optionId)?->type_code_override;

            $options[] = [
                'product_id' => $product->id,
                'option_id' => $optionId,
                'code' => $code,
                'kind' => ($first->undecorated || $first->base_price_line_id === null)
                    ? PriceOption::KIND_UNDECORATED
                    : PriceOption::KIND_DECORATED,
                'type_code' => $override ?? $tableType,
                'description' => (string) $first->description,
                'display_description' => $type?->overwrite_description ?: (string) $first->description,
                'setup_cost' => Money::currency((float) $first->setup_cost),
                'setup_price' => Money::applyMarkup((float) $first->setup_cost, (float) ($supplier->markup_setup ?? 1)),
                'cost_string' => $costs->toString(),
                'price_string' => $prices->toString(),
                'promodata_decoration' => $first->promodata_decoration,
                'placeholder' => false,
                'position' => $position++,
            ];
        }

        if (! in_array($product->supplier_id, config('pricing.no_placeholder_suppliers'), true)) {
            $options = $this->addPlaceholders($product, $options, $position);
        }

        return DB::transaction(function () use ($product, $options) {
            PriceOption::query()
                ->where('product_id', $product->id)
                ->where('kind', '!=', PriceOption::KIND_EXTERNAL)
                ->delete();

            foreach ($options as $option) {
                PriceOption::query()->create($option);
            }

            return $product->priceOptions()->get();
        });
    }

    /**
     * @param  Collection<int, PriceLine>  $lines
     * @return array<string, list<PriceLine>>
     */
    private function groupByCode(Collection $lines): array
    {
        $groups = [];
        foreach ($lines as $line) {
            $code = DecorationCode::fromDescription($line->description);
            if ($code === '') {
                $code = $line->undecorated ? 'undecorated' : 'branded';
            }
            $groups[$code][] = $line;
        }

        return $groups;
    }

    /**
     * Known type codes for these option codes; unknown codes are added unclassified.
     *
     * @param  array<string, list<PriceLine>>  $groups
     * @return Collection<string, DecTypeCode>
     */
    private function typeCodes(array $groups, Product $product, Supplier $supplier): Collection
    {
        $codes = array_map('strval', array_keys($groups));
        $known = DecTypeCode::query()->whereIn('code', $codes)->get()->keyBy('code');

        foreach ($groups as $code => $group) {
            $code = (string) $code;
            if (! $known->has($code)) {
                $known[$code] = DecTypeCode::query()->create([
                    'code' => $code,
                    'type_code' => null,
                    'full_description' => $group[0]->description,
                    'promodata_decoration' => $group[0]->promodata_decoration,
                    'first_supplier_id' => $product->supplier_id,
                    'first_supplier_name' => $supplier->name,
                ]);
            }
        }

        return $known;
    }

    private function unitMarkup(?int $typeCode, Supplier $supplier): float
    {
        return (float) match ($typeCode) {
            DecTypeCode::DECORATION => $supplier->markup_decoration ?? 1.50,
            DecTypeCode::UNDECORATED => $supplier->markup_undecorated ?? 1.45,
            default => $supplier->markup_accessory ?? 1.45,
        };
    }

    /**
     * TOD's "create 1:0 0 unbranded if none exists" and "only available unbranded" steps.
     *
     * @param  list<array<string, mixed>>  $options
     * @return list<array<string, mixed>>
     */
    private function addPlaceholders(Product $product, array $options, int $position): array
    {
        $hasType = fn (int $type) => collect($options)->contains(
            fn ($o) => $o['type_code'] === $type && ! ($type === DecTypeCode::UNDECORATED && $o['price_string'] === '1:0')
        );

        $placeholder = fn (string $code, string $description, int $type, int $pos) => [
            'product_id' => $product->id,
            'option_id' => $product->id.'-'.$code,
            'code' => $code,
            'kind' => PriceOption::KIND_UNDECORATED,
            'type_code' => $type,
            'description' => $description,
            'display_description' => $description,
            'setup_cost' => 0,
            'setup_price' => 0,
            'cost_string' => '1:0',
            'price_string' => '1:0',
            'promodata_decoration' => null,
            'placeholder' => true,
            'position' => $pos,
        ];

        if (! $hasType(DecTypeCode::UNDECORATED)) {
            // TOD also deletes any existing zero-price undecorated option first.
            $options = array_values(array_filter($options, fn ($o) => ! ($o['type_code'] === DecTypeCode::UNDECORATED && $o['price_string'] === '1:0')));
            $options[] = $placeholder('unbranded', 'Unbranded', DecTypeCode::UNDECORATED, $position++);
        }

        if (! $hasType(DecTypeCode::DECORATION)) {
            $options[] = $placeholder('unbrandeddec', 'Product is only available unbranded', DecTypeCode::DECORATION, $position);
        }

        return $options;
    }
}
