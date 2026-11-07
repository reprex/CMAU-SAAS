<?php

namespace App\Freight;

use App\Pricing\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Australian delivery priced live by MachShip (routes/returnroutes), on the TNT services ROD uses
 * (docs/freight.md). The client sees the service, the price ex GST and the business days in transit.
 */
class MachshipFreightDriver implements FreightDriver
{
    public function configured(): bool
    {
        return (string) config('freight.machship.token') !== '';
    }

    public function quote(Consignment $c, string $country, ?string $postcode): array
    {
        $base = ['country' => 'AU', 'export' => false, 'cartons' => $c->cartons, 'kg' => round($c->kg, 2), 'm3' => round($c->m3, 3),
            'estimated' => $c->estimated, 'source' => 'machship', 'basis' => 'MachShip', 'chargeable_kg' => null];
        $postcode = trim((string) $postcode);
        if (! preg_match('/^\d{4}$/', $postcode)) {
            return $base + ['ok' => false, 'message' => 'Enter a 4-digit Australian postcode we deliver to.', 'zone' => null, 'options' => []];
        }
        try {
            $to = $this->location($postcode);
            if ($to === null) {
                return $base + ['ok' => false, 'message' => 'We don\'t recognise that postcode. Check it, or ask us to confirm delivery.', 'zone' => null, 'options' => []];
            }
            $from = config('freight.from');
            $body = array_filter([
                'companyId' => config('freight.machship.company_id') ? (int) config('freight.machship.company_id') : null,
                'fromLocation' => ['suburb' => $from['suburb'], 'postcode' => $from['postcode']],
                'toLocation' => ['suburb' => $to['suburb'], 'postcode' => $postcode],
                'carrierIds' => config('freight.machship.carrier_ids') ?: null,
                'items' => array_map(fn ($i) => ['itemType' => 'Carton', 'name' => 'Carton', 'quantity' => $i['quantity'],
                    'length' => (int) ceil($i['length']), 'width' => (int) ceil($i['width']), 'height' => (int) ceil($i['height']), 'weight' => max(0.1, round($i['weight'], 1))], $c->items),
            ], fn ($v) => $v !== null);
            $r = $this->http()->post('routes/returnroutes', $body);
            $routes = $r->json('object.routes');
            if (! $r->successful() || ! is_array($routes)) {
                Log::warning('machship returnroutes failed', ['status' => $r->status(), 'errors' => $r->json('errors')]);

                return $base + ['ok' => false, 'message' => 'We couldn\'t price delivery just now. We\'ll confirm it.', 'zone' => $to['state'], 'options' => []];
            }
        } catch (Throwable $e) {
            Log::warning('machship error', ['error' => $e->getMessage()]);

            return $base + ['ok' => false, 'message' => 'We couldn\'t price delivery just now. We\'ll confirm it.', 'zone' => null, 'options' => []];
        }

        return $base + ['ok' => true, 'message' => null, 'zone' => $to['state'] ?? null, 'options' => $this->options($routes)];
    }

    /** @return list<array{id: string, name: string, carrier: string, days: array{0: int, 1: int}, price: float, cost: ?float}> */
    public function options(array $routes): array
    {
        $services = config('freight.machship.services');
        $markup = 1 + (float) config('freight.machship.markup_percent') / 100;
        $out = [];
        foreach ($routes as $route) {
            $sid = (int) ($route['carrierService']['id'] ?? 0);
            if ($services && ! isset($services[$sid])) {
                continue;
            }
            $t = (array) ($route['consignmentTotal'] ?? []);
            $sell = $t['totalSellBeforeTax'] ?? (isset($t['totalSellPrice']) ? (float) $t['totalSellPrice'] - (float) ($t['totalTaxSellPrice'] ?? 0) : null);
            if ($sell === null) {
                continue;
            }
            $costEx = $t['totalCostBeforeTax'] ?? (isset($t['totalCostPrice']) ? (float) $t['totalCostPrice'] - (float) ($t['totalTaxCostPrice'] ?? 0) : null);
            $days = (int) ($route['despatchOptions'][0]['totalBusinessDays'] ?? 0);
            $id = $services[$sid]['id'] ?? 'ms_'.$sid;
            $out[$id] ??= [
                'id' => $id,
                'name' => $services[$sid]['name'] ?? trim(($route['carrier']['name'] ?? '').' '.($route['carrierService']['name'] ?? '')),
                'carrier' => (string) ($route['carrier']['name'] ?? 'MachShip'),
                'days' => [max(1, $days), max(1, $days)],
                'price' => Money::roundToCent((float) $sell * $markup),
                'cost' => $costEx !== null ? Money::roundToCent((float) $costEx) : null,
            ];
        }
        $out = array_values($out);
        usort($out, fn ($a, $b) => $a['price'] <=> $b['price']);

        return $out;
    }

    /** @return array{suburb: string, state: ?string}|null the first MachShip location for the postcode */
    private function location(string $postcode): ?array
    {
        $key = 'machship-loc:'.$postcode;
        if (Cache::has($key)) {
            return Cache::get($key);
        }
        $r = $this->http()->get('locations/returnLocations', ['s' => $postcode]);
        if (! $r->successful()) {
            // A bad token or an outage is never cached against a postcode, and shows as "couldn't price".
            throw new \RuntimeException('machship returnLocations failed: HTTP '.$r->status().' '.mb_substr($r->body(), 0, 300));
        }
        $hit = collect((array) $r->json('object'))->first(fn ($l) => (string) ($l['postcode'] ?? '') === $postcode);
        if (! $hit) {
            Log::info('machship returnLocations: no match', ['postcode' => $postcode, 'body' => mb_substr($r->body(), 0, 500)]);

            return null;
        }
        $loc = ['suburb' => (string) $hit['suburb'], 'state' => $hit['state']['code'] ?? ($hit['state']['name'] ?? null)];
        Cache::put($key, $loc, 86400 * 30);

        return $loc;
    }

    private function http()
    {
        return Http::baseUrl(rtrim((string) config('freight.machship.base_url'), '/').'/')
            ->withHeaders(['token' => (string) config('freight.machship.token')])
            ->acceptJson()->timeout((int) config('freight.machship.timeout'))->connectTimeout(5);
    }
}
