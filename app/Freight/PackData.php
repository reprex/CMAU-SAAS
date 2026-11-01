<?php

namespace App\Freight;

use App\Models\Product;
use App\Models\ProductDetail;

/**
 * How a product ships: unit weight, units per carton, carton size and the empty
 * carton's weight. Read from Promodata's packing details where the supplier
 * gives them, otherwise the per-type defaults in config/freight.php.
 */
class PackData
{
    /** @return array{unit_kg: float, units_per_carton: int, carton_cm: array{0: float, 1: float, 2: float}, tare_kg: float, source: string} */
    public function for(Product $product): array
    {
        $defaults = $this->defaults((string) $product->promodata_type_name.' '.$product->name);
        $found = $this->fromDetails((array) (ProductDetail::query()->find($product->id)?->details ?? []));

        $pack = array_merge($defaults, $found);
        if (isset($found['carton_kg'])) {
            // A gross carton weight: spread it over the units, no separate tare.
            $pack['unit_kg'] = $found['carton_kg'] / max(1, $pack['units_per_carton']);
            $pack['tare_kg'] = 0.0;
        }
        unset($pack['carton_kg']);
        $pack['source'] = $found === [] ? 'default' : 'promodata';

        return $pack;
    }

    /** @return array{unit_kg: float, units_per_carton: int, carton_cm: array, tare_kg: float} */
    public function defaults(string $type): array
    {
        $type = mb_strtolower($type);
        $all = config('freight.pack_defaults');
        foreach ($all as $key => $pack) {
            if ($key !== 'default' && str_contains($type, $key)) {
                return $this->clean($pack);
            }
        }

        return $this->clean($all['default']);
    }

    /**
     * Promodata details are free text, e.g. {"name":"Carton Quantity","detail":"72"},
     * {"name":"Carton Dimensions","detail":"60 x 45 x 35cm"}, {"name":"Item Weight","detail":"190g"}.
     *
     * @param  list<array{name?: string, detail?: ?string}>  $details
     * @return array<string, mixed>
     */
    public function fromDetails(array $details): array
    {
        $out = [];
        foreach ($details as $d) {
            $name = mb_strtolower(trim((string) ($d['name'] ?? '')));
            $text = mb_strtolower(trim((string) ($d['detail'] ?? '')));
            if ($name === '' || $text === '') {
                continue;
            }
            if (preg_match('/carton\s*(qty|quantity)|(qty|quantity|units?|pieces)\s*(per|\/)\s*carton|^carton$/', $name) && preg_match('/(\d+)/', $text, $m)) {
                $out['units_per_carton'] = max(1, (int) $m[1]);
            } elseif (preg_match('/^(packing|packaging)$/', $name) && preg_match('/(\d+)\s*(pcs|pieces|units|per)?\s*(per|\/)\s*(carton|ctn)/', $text, $m)) {
                $out['units_per_carton'] = max(1, (int) $m[1]);
            }
            if (preg_match('/carton\s*(size|dim)/', $name) && ($dims = $this->dims($text))) {
                $out['carton_cm'] = $dims;
            }
            if (preg_match('/carton\s*weight|gross\s*weight/', $name) && ($kg = $this->kg($text)) !== null) {
                $out['carton_kg'] = $kg;
            } elseif (preg_match('/(item|unit|product|net)\s*weight|^weight$/', $name) && ($kg = $this->kg($text)) !== null) {
                $out['unit_kg'] = $kg;
            }
        }

        return $out;
    }

    /** @return array{0: float, 1: float, 2: float}|null centimetres */
    private function dims(string $text): ?array
    {
        if (! preg_match('/(\d+(?:\.\d+)?)\s*(?:cm|mm)?\s*[x×*]\s*(\d+(?:\.\d+)?)\s*(?:cm|mm)?\s*[x×*]\s*(\d+(?:\.\d+)?)\s*(cm|mm|m)?/u', $text, $m)) {
            return null;
        }
        $f = match ($m[4] ?? 'cm') {
            'mm' => 0.1, 'm' => 100.0, default => 1.0
        };

        return [(float) $m[1] * $f, (float) $m[2] * $f, (float) $m[3] * $f];
    }

    private function kg(string $text): ?float
    {
        if (! preg_match('/(\d+(?:\.\d+)?)\s*(kg|kgs|g|grams?)\b/', $text, $m)) {
            return null;
        }

        return str_starts_with($m[2], 'k') ? (float) $m[1] : (float) $m[1] / 1000;
    }

    private function clean(array $pack): array
    {
        return [
            'unit_kg' => (float) $pack['unit_kg'],
            'units_per_carton' => (int) $pack['units_per_carton'],
            'carton_cm' => array_map('floatval', $pack['carton_cm']),
            'tare_kg' => (float) $pack['tare_kg'],
        ];
    }
}
