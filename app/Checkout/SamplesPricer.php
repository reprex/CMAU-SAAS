<?php

namespace App\Checkout;

use App\Catalogue\OurCodes;
use App\Models\PriceOption;
use App\Models\Product;
use App\Models\ProductWebPrice;
use App\Pricing\Money;
use Carbon\CarbonImmutable;

/**
 * Prices the client's samples cart (Scott, 1 Nov 2026; docs/website-checkout.md).
 *
 *  - Only suppliers with offers_samples. A sample is the product's UNBRANDED
 *    price at its minimum order, from the SaaS pricing (never from the client).
 *    It may be unprinted or a print overrun: the factory decides, so nothing is
 *    asked or stored about it.
 *  - One cart, grouped by supplier, shown as "From factory 1", "From factory 2"
 *    in order of first appearance. Supplier names are never in the reply.
 *  - Each factory's samples ship together: that supplier's sample_freight
 *    (ex GST, per consignment) once, and a factory subtotal.
 *  - Australia only for now; GST 10% on the total of all factories.
 *  - Delivery: order check, then the slowest factory's supply days, then the
 *    freight transit days for the postcode's zone (TNT Road Express, slowest day).
 */
class SamplesPricer
{
    public function __construct(private readonly DeliveryEstimator $estimator) {}

    /**
     * @param  array{items: list<array{product: int, colour?: ?string}>, postcode?: ?string, country?: ?string}  $in
     * @return array<string, mixed>
     */
    public function evaluate(array $in, ?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::now(config('app.timezone')))->startOfDay();
        $issues = [];
        $issue = function (string $code, string $message, array $extra = []) use (&$issues) {
            $issues[] = ['code' => $code, 'message' => $message] + $extra;
        };

        $country = mb_strtoupper(trim((string) ($in['country'] ?? 'AU')) ?: 'AU');
        if ($country !== 'AU') {
            $issue('not_australia', 'Samples can only be sent within Australia for now. Please ask us for a quote.');
        }

        // ---- Items: one sample per product and colour, in the order added.
        $items = [];
        $products = Product::query()->with(['supplier', 'colours'])->findMany(collect($in['items'] ?? [])->pluck('product')->map(fn ($v) => (int) $v)->unique()->all())->keyBy('id');
        foreach ($in['items'] ?? [] as $raw) {
            $pid = (int) $raw['product'];
            $colour = trim((string) ($raw['colour'] ?? ''));
            $product = $products[$pid] ?? null;
            if ($product === null || $product->discontinued) {
                $issue('product', "That product can't be ordered as a sample.", ['product' => $pid]);

                continue;
            }
            $our = OurCodes::code($product);
            if (! $product->supplier?->offers_samples) {
                $issue('no_samples', "Samples aren't available for {$product->name}.", ['product' => $pid, 'product_code' => $our]);

                continue;
            }
            $names = $product->colours->pluck('name')->filter()->map(fn ($c) => trim($c));
            if ($colour === '') {
                $colour = (string) ($names->first() ?? '');
            } elseif ($names->isNotEmpty() && ! $names->contains(fn ($c) => mb_strtolower($c) === mb_strtolower($colour))) {
                $issue('colour', "{$product->name} doesn't come in {$colour}.", ['product' => $pid, 'product_code' => $our, 'colour' => $colour]);

                continue;
            }
            $key = $pid.'|'.mb_strtolower($colour);
            if (isset($items[$key])) {
                continue; // one sample of each product and colour
            }
            $unit = $this->unitPrice($product);
            if ($unit === null) {
                $issue('price', "{$product->name} can't be priced as a sample online.", ['product' => $pid, 'product_code' => $our]);

                continue;
            }
            $items[$key] = [
                'product_id' => $pid, 'code' => $our, 'sku' => OurCodes::sku($our, $colour), 'name' => $product->name, 'colour' => $colour,
                'unit' => $unit['price'], 'moq' => $unit['moq'], 'supplier_id' => $product->supplier_id, 'supplier' => $product->supplier,
            ];
        }
        if ($items === [] && $issues === []) {
            $issue('empty', 'Add at least one sample.');
        }

        // ---- Factory groups, in order of first appearance; freight once per factory.
        $groups = [];
        foreach ($items as $it) {
            $sid = $it['supplier_id'];
            $groups[$sid] ??= ['factory' => count($groups) + 1, 'supplier' => $it['supplier'], 'items' => []];
            $groups[$sid]['items'][] = $it;
        }
        $factories = [];
        foreach ($groups as $sid => $g) {
            $freight = $g['supplier']->sample_freight;
            if ($freight === null) {
                $issue('sample_freight', "We can't price sample freight from factory {$g['factory']} online yet. Please ask us for a quote.", ['factory' => $g['factory']]);
            }
            $goods = Money::roundToCent(array_sum(array_column($g['items'], 'unit')));
            $freight = Money::roundToCent((float) $freight);
            $factories[] = [
                'factory' => $g['factory'],
                'label' => 'From factory '.$g['factory'],
                'supplier_id' => $sid, // removed from the public reply (staff only)
                'days' => $g['supplier']->sampleLeadDays(),
                'state' => (string) ($g['supplier']->state ?: config('website.lead_times.default_state')),
                'items' => array_map(fn ($it) => array_diff_key($it, ['supplier' => 1, 'supplier_id' => 1]), $g['items']),
                'goods' => $goods,
                'freight' => $freight,
                'subtotal' => Money::roundToCent($goods + $freight),
            ];
        }

        // ---- Delivery estimate.
        $postcode = trim((string) ($in['postcode'] ?? ''));
        $zone = preg_match('/^\d{4}$/', $postcode) ? config('freight.au_zones.'.$postcode[0]) : null;
        $estimate = null;
        if ($country === 'AU' && $zone === null) {
            $issue('postcode', 'Enter a 4-digit Australian postcode we deliver to.');
        } elseif ($zone !== null && $factories !== []) {
            $slowest = collect($factories)->sortByDesc('days')->first();
            $service = (string) config('website.samples.freight_service', 'tnt_road');
            $transit = (int) max($zone[$service]['days'] ?? [(int) config('website.samples.transit_days_default', 3)]);
            $estimate = $this->estimator->stages($slowest['state'], [
                ['key' => 'order_check', 'name' => 'Order check and invoice', 'days' => (int) config('website.lead_times.order_check')],
                ['key' => 'supply', 'name' => count($factories) > 1 ? 'Samples from the factories' : 'Samples from the factory', 'days' => (int) $slowest['days']],
                ['key' => 'freight', 'name' => 'Delivery to '.$zone['name'], 'days' => $transit],
            ], $today);
        }

        // ---- Totals.
        $goods = Money::roundToCent(array_sum(array_column($factories, 'goods')));
        $freight = Money::roundToCent(array_sum(array_column($factories, 'freight')));
        $subtotal = Money::roundToCent($goods + $freight);
        $gst = Money::roundToCent($subtotal * (float) config('quotes.gst_rate'));

        return [
            'can_order' => $issues === [],
            'issues' => $issues,
            'samples' => count($items),
            'factories' => $factories,
            'delivery_estimate' => $estimate,
            'totals' => [
                'goods' => $goods, 'freight' => $freight, 'subtotal' => $subtotal,
                'gst_rate' => (float) config('quotes.gst_rate'), 'gst' => $gst, 'total' => Money::roundToCent($subtotal + $gst),
                'inc_gst' => true, 'currency' => 'AUD',
            ],
            'note' => config('website.samples.note'),
        ];
    }

    /** The reply for the website: no supplier ids, names or codes. */
    public static function publicReply(array $result): array
    {
        $result['factories'] = array_map(fn ($f) => array_diff_key($f, ['supplier_id' => 1, 'state' => 1]), $result['factories']);
        if ($result['delivery_estimate']) {
            unset($result['delivery_estimate']['state']);
        }

        return $result;
    }

    /** The product's unbranded price at its minimum order (SaaS pricing). @return array{price: float, moq: int}|null */
    private function unitPrice(Product $product): ?array
    {
        $web = ProductWebPrice::query()->find($product->id);
        $opt = $web?->undecorated_option_id ? PriceOption::query()->where('option_id', $web->undecorated_option_id)->first() : null;
        $prices = $opt?->prices();
        $moq = $prices?->minQty();
        if (! $moq) {
            return null;
        }
        $price = Money::roundToCent($prices->priceAt($moq));

        return $price > 0 ? ['price' => $price, 'moq' => $moq] : null;
    }
}
