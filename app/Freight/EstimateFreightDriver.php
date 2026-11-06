<?php

namespace App\Freight;

use App\Pricing\Money;

/**
 * Freight from the placeholder rate tables in config/freight.php (the
 * approved prototype's numbers):
 *  - Australia: zone by the postcode's first digit; TNT Road Express and TNT
 *    Overnight Express, each base + per kg × chargeable kg, where chargeable
 *    kg = max(dead weight, cubic at 250 kg/m³);
 *  - overseas: FedEx International Priority and Economy by country, volumetric
 *    weight = cm³ ÷ 5000.
 */
class EstimateFreightDriver implements FreightDriver
{
    public function quote(Consignment $c, string $country, ?string $postcode): array
    {
        $country = mb_strtoupper($country);
        $base = ['country' => $country, 'export' => $country !== 'AU', 'cartons' => $c->cartons, 'kg' => round($c->kg, 2), 'm3' => round($c->m3, 3), 'estimated' => $c->estimated, 'source' => 'estimate'];

        if ($country === 'AU') {
            $postcode = trim((string) $postcode);
            $zone = preg_match('/^\d{4}$/', $postcode) ? config('freight.au_zones.'.$postcode[0]) : null;
            if ($zone === null) {
                return $base + ['ok' => false, 'message' => 'Enter a 4-digit Australian postcode we deliver to.', 'zone' => null, 'basis' => null, 'chargeable_kg' => null, 'options' => []];
            }
            $cubic = (float) config('freight.au_cubic_kg_per_m3');
            $charge = max($c->kg, $c->m3 * $cubic);

            return $base + [
                'ok' => true, 'message' => null, 'zone' => $zone['name'],
                'basis' => $charge > $c->kg ? "cubic weight ({$cubic} kg/m³)" : 'dead weight',
                'chargeable_kg' => round($charge, 2),
                'options' => $this->options(config('freight.au_services'), $zone, $charge),
            ];
        }

        $dest = config('freight.intl_countries.'.$country);
        if ($dest === null) {
            return $base + ['ok' => false, 'message' => 'We can\'t price delivery to that country online yet.', 'zone' => null, 'basis' => null, 'chargeable_kg' => null, 'options' => []];
        }
        $divisor = (float) config('freight.intl_volumetric_divisor');
        $charge = max($c->kg, $c->cm3 / $divisor);

        return $base + [
            'ok' => true, 'message' => null, 'zone' => $dest['name'],
            'basis' => $charge > $c->kg ? "volumetric weight (cm³ ÷ {$divisor})" : 'dead weight',
            'chargeable_kg' => round($charge, 2),
            'options' => $this->options(config('freight.intl_services'), $dest, $charge),
        ];
    }

    /** @return list<array{id: string, name: string, carrier: string, days: array{0: int, 1: int}, price: float}> */
    private function options(array $services, array $rates, float $charge): array
    {
        $out = [];
        foreach ($services as $id => $service) {
            if (! isset($rates[$id])) {
                continue;
            }
            $r = $rates[$id];
            $out[] = [
                'id' => $id,
                'name' => $service['name'],
                'carrier' => $service['carrier'],
                'days' => [(int) $r['days'][0], (int) $r['days'][1]],
                'price' => Money::roundToCent($r['base'] + $r['per_kg'] * $charge),
            ];
        }

        return $out;
    }
}
