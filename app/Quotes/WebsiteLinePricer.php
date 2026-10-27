<?php

namespace App\Quotes;

use App\Models\PriceOption;
use App\Models\ProductWebPrice;
use App\Pricing\Money;

/**
 * Prices a website line the way the CMAU product page shows it, and returns
 * the draft order line properties the quote desk reads back:
 *   undecunit, undecsetup, decunit, decsetup, decdesc, decID
 *   price = (decsetup + undecsetup) / qty + decunit + undecunit
 */
class WebsiteLinePricer
{
    /** @return array{price: float, properties: array<string, string>}|null */
    public function price(int $productId, int $qty, ?string $decId): ?array
    {
        $web = ProductWebPrice::find($productId);
        $undec = $web?->undecorated_option_id ? PriceOption::query()->where('option_id', $web->undecorated_option_id)->first() : null;
        if ($undec === null) {
            return null;
        }

        $dec = null;
        if ($decId !== null && $decId !== '') {
            $dec = PriceOption::query()->where('option_id', $decId)->first();
            $allowed = [$productId, (int) $web->decoration_product_id];
            if ($dec === null || ! in_array((int) $dec->product_id, $allowed, true)) {
                return null; // decoration does not belong to this product
            }
        }

        $undecUnit = Money::roundToCent($undec->prices()->priceAt($qty));
        $undecSetup = Money::roundToCent($undec->setup_price);
        $decUnit = $dec ? Money::roundToCent($dec->prices()->priceAt($qty)) : 0.0;
        $decSetup = $dec ? Money::roundToCent($dec->setup_price) : 0.0;

        $properties = [
            'decsetup' => number_format($decSetup, 2, '.', ''),
            'decunit' => number_format($decUnit, 2, '.', ''),
            'undecsetup' => number_format($undecSetup, 2, '.', ''),
            'undecunit' => number_format($undecUnit, 2, '.', ''),
        ];
        if ($dec) {
            $properties['decdesc'] = (string) ($dec->display_description ?: $dec->description);
            $properties['decID'] = $dec->option_id;
        }

        return [
            'price' => Money::roundToCent(($decSetup + $undecSetup) / max(1, $qty) + $decUnit + $undecUnit),
            'properties' => $properties,
        ];
    }
}
