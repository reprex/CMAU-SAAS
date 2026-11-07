<?php

namespace App\Freight;

use App\Pricing\Money;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Overseas delivery priced live by FedEx (REST Rate API, Replicat's account rates): International Priority and
 * Economy. Duties and taxes are the receiver's, paid to FedEx before delivery (docs/freight.md).
 */
class FedexFreightDriver implements FreightDriver
{
    public function configured(): bool
    {
        return (string) config('freight.fedex.client_id') !== '' && (string) config('freight.fedex.client_secret') !== '' && (string) config('freight.fedex.account_number') !== '';
    }

    public function quote(Consignment $c, string $country, ?string $postcode): array
    {
        $country = mb_strtoupper($country);
        $base = ['country' => $country, 'export' => true, 'cartons' => $c->cartons, 'kg' => round($c->kg, 2), 'm3' => round($c->m3, 3),
            'estimated' => $c->estimated, 'source' => 'fedex', 'basis' => 'FedEx', 'chargeable_kg' => null, 'zone' => Countries::name($country)];
        if (! preg_match('/^[A-Z]{2}$/', $country) || $country === 'AU') {
            return $base + ['ok' => false, 'message' => 'Choose the country to deliver to.', 'options' => []];
        }
        $fail = $base + ['ok' => false, 'message' => 'We couldn\'t price delivery to '.Countries::name($country).' just now. We\'ll confirm it.', 'options' => []];
        try {
            $token = $this->token();
            if ($token === null) {
                return $fail;
            }
            // FedEx refuses some payment set-ups per account (RATE.PAYMENTTYPE.NOTALLOWED, 7 Nov 2026):
            // try as built, then without the duties payment, then with the sender paying transport.
            $body = $this->request($c, $country, $postcode);
            $variants = [$body, Arr::except($body, 'requestedShipment.customsClearanceDetail.dutiesPayment')];
            $variants[2] = $variants[1];
            $variants[2]['requestedShipment']['shippingChargesPayment'] = ['paymentType' => 'SENDER', 'payor' => ['responsibleParty' => ['accountNumber' => ['value' => (string) config('freight.fedex.account_number')]]]];
            foreach ($variants as $n => $try) {
                $r = Http::baseUrl(rtrim((string) config('freight.fedex.base_url'), '/'))->withToken($token)->acceptJson()
                    ->withHeaders(['X-locale' => 'en_US'])->timeout((int) config('freight.fedex.timeout'))->connectTimeout(5)
                    ->post('/rate/v1/rates/quotes', $try);
                $codes = collect((array) $r->json('errors'))->pluck('code')->implode(',');
                if ($r->successful() || ! str_contains($codes, 'PAYMENTTYPE')) {
                    break;
                }
                Log::info('fedex rate retry', ['variant' => $n, 'errors' => $codes]);
            }
            $details = $r->json('output.rateReplyDetails');
            if (! $r->successful() || ! is_array($details)) {
                Log::warning('fedex rate failed', ['status' => $r->status(), 'errors' => $r->json('errors')]);

                return $fail;
            }
        } catch (Throwable $e) {
            Log::warning('fedex error', ['error' => $e->getMessage()]);

            return $fail;
        }
        $options = $this->options($details);

        return $base + ($options === [] ? ['ok' => false, 'message' => $fail['message'], 'options' => []] : ['ok' => true, 'message' => null, 'options' => $options]);
    }

    /** @return array<string, mixed> */
    public function request(Consignment $c, string $country, ?string $postcode): array
    {
        $from = $c->shipsFrom();
        $packages = [];
        foreach ($c->items as $i) {
            for ($k = 0; $k < $i['quantity']; $k++) {
                $packages[] = ['weight' => ['units' => 'KG', 'value' => max(0.1, round($i['weight'], 1))],
                    'dimensions' => ['length' => (int) ceil($i['length']), 'width' => (int) ceil($i['width']), 'height' => (int) ceil($i['height']), 'units' => 'CM']];
            }
        }
        $value = max(1.0, round($c->value, 2));

        return [
            'accountNumber' => ['value' => (string) config('freight.fedex.account_number')],
            'rateRequestControlParameters' => ['returnTransitTimes' => true],
            'requestedShipment' => [
                'shipper' => ['address' => ['postalCode' => $from['postcode'], 'countryCode' => 'AU', 'city' => $from['suburb']]],
                'recipient' => ['address' => array_filter(['postalCode' => trim((string) $postcode) ?: null, 'countryCode' => $country])],
                'pickupType' => 'DROPOFF_AT_FEDEX_LOCATION',
                'rateRequestType' => ['ACCOUNT', 'LIST'],
                'preferredCurrency' => 'AUD',
                'customsClearanceDetail' => [
                    'dutiesPayment' => ['paymentType' => 'RECIPIENT'],
                    'commodities' => [[
                        'description' => (string) config('freight.fedex.commodity'),
                        'quantity' => max(1, $c->cartons), 'quantityUnits' => 'PCS',
                        'weight' => ['units' => 'KG', 'value' => max(0.1, round($c->kg, 1))],
                        'customsValue' => ['amount' => $value, 'currency' => 'AUD'],
                    ]],
                ],
                'totalPackageCount' => count($packages),
                'requestedPackageLineItems' => $packages ?: [['weight' => ['units' => 'KG', 'value' => max(0.1, round($c->kg, 1))]]],
            ],
        ];
    }

    /** @return list<array{id: string, name: string, carrier: string, days: array{0: int, 1: int}, price: float, cost: ?float}> */
    public function options(array $details): array
    {
        $services = config('freight.fedex.services');
        $names = ['fedex_ip' => 'FedEx International Priority', 'fedex_ie' => 'FedEx International Economy'];
        $markup = 1 + (float) config('freight.fedex.markup_percent') / 100;
        $out = [];
        foreach ($details as $d) {
            $id = $services[(string) ($d['serviceType'] ?? '')] ?? null;
            if ($id === null || isset($out[$id])) {
                continue;
            }
            $rated = collect((array) ($d['ratedShipmentDetails'] ?? []));
            $pick = $rated->first(fn ($x) => str_contains((string) ($x['rateType'] ?? ''), 'ACCOUNT') && ($x['currency'] ?? 'AUD') === 'AUD')
                ?? $rated->first(fn ($x) => ($x['currency'] ?? 'AUD') === 'AUD');
            if (! $pick || ! isset($pick['totalNetCharge'])) {
                continue;
            }
            $days = $this->days($d) ?? ($id === 'fedex_ip' ? 5 : 9);
            $cost = Money::roundToCent((float) $pick['totalNetCharge']);
            $out[$id] = ['id' => $id, 'name' => $names[$id], 'carrier' => 'FedEx', 'days' => [$days, $days], 'price' => Money::roundToCent($cost * $markup), 'cost' => $cost];
        }
        $out = array_values($out);
        usort($out, fn ($a, $b) => $a['price'] <=> $b['price']);

        return $out;
    }

    private function days(array $d): ?int
    {
        $words = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5, 'SIX' => 6, 'SEVEN' => 7, 'EIGHT' => 8, 'NINE' => 9, 'TEN' => 10, 'ELEVEN' => 11, 'TWELVE' => 12];
        foreach ([$d['commit']['transitDays']['minimumTransitTime'] ?? null, $d['operationalDetail']['transitTime'] ?? null, $d['commit']['transitDays'] ?? null] as $t) {
            if (is_string($t) && preg_match('/^([A-Z]+)_DAYS?$/', $t, $m) && isset($words[$m[1]])) {
                return $words[$m[1]];
            }
            if (is_numeric($t)) {
                return (int) $t;
            }
        }

        return null;
    }

    private function token(): ?string
    {
        return Cache::remember('fedex-token', 50 * 60, function () {
            $r = Http::asForm()->timeout(10)->post(rtrim((string) config('freight.fedex.base_url'), '/').'/oauth/token', [
                'grant_type' => 'client_credentials', 'client_id' => config('freight.fedex.client_id'), 'client_secret' => config('freight.fedex.client_secret'),
            ]);
            if (! $r->successful() || ! $r->json('access_token')) {
                Log::warning('fedex token failed', ['status' => $r->status(), 'body' => mb_substr($r->body(), 0, 500)]);

                throw new \RuntimeException('FedEx sign-in failed');
            }

            return (string) $r->json('access_token');
        });
    }
}
