<?php

namespace App\Orders;

use App\Checkout\DecorationMethods;
use App\Models\AuditLog;
use App\Models\PriceOption;
use App\Models\ProductWebPrice;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\WebsiteOrder;
use App\Pricing\Money;
use Illuminate\Support\Str;

/**
 * Supplier purchase orders from website orders (Scott, 7 Nov 2026; docs/purchase-orders.md). Kept simple:
 * the sizes and colours with the supplier's SKUs, each branding position, the options, all at supplier cost
 * (the catalogue's cost prices at the order's total quantity); delivered blind to the client; the client's
 * date, the despatch date and the event; a link to the artwork. Freight is left out: the supplier gives the
 * actual cost and staff add it.
 */
class PurchaseOrders
{
    public function fromWebsiteOrder(WebsiteOrder $order, ?User $by = null): PurchaseOrder
    {
        $order->loadMissing(['lines', 'positions', 'supplier']);
        $units = (int) $order->lines->sum('qty');
        $complete = true;

        // Sizes and colours: each style at its unbranded cost at the total quantity.
        $lines = [];
        $goods = 0.0;
        $setup = 0.0;
        foreach ($order->lines->groupBy('product_id') as $pid => $rows) {
            $web = ProductWebPrice::query()->find($pid);
            $undec = $web?->undecorated_option_id ? PriceOption::query()->where('option_id', $web->undecorated_option_id)->first() : null;
            $unit = $undec ? Money::roundToCent($undec->costs()->priceAt(max(1, $units))) : null;
            $complete = $complete && $unit !== null;
            if ($undec && (float) $undec->setup_cost > 0 && $pid === $order->product_id) {
                $setup += (float) $undec->setup_cost;
            }
            foreach ($rows as $l) {
                $total = $unit !== null ? Money::roundToCent($unit * $l->qty) : null;
                $goods += (float) $total;
                $lines[] = ['code' => $l->product_code, 'name' => $l->product_name, 'colour' => $l->colour, 'size' => $l->size,
                    'supplier_sku' => $l->supplier_sku, 'qty' => (int) $l->qty, 'unit_cost' => $unit, 'total' => $total];
            }
        }

        // Branding positions at cost (per colour where the method is priced per colour).
        $branding = [];
        $brandingCost = 0.0;
        foreach ($order->positions as $p) {
            $opt = $p->decoration_option_id ? PriceOption::query()->where('option_id', $p->decoration_option_id)->first() : null;
            $times = DecorationMethods::pricing($p->method) === 'per_colour' ? max(1, (int) $p->colours) : 1;
            $unit = $opt ? Money::roundToCent($opt->costs()->priceAt(max(1, $units)) * $times) : null;
            $set = $opt ? Money::roundToCent((float) $opt->setup_cost * $times) : null;
            $complete = $complete && $unit !== null;
            $brandingCost += (float) $unit * $units;
            $setup += (float) $set;
            $branding[] = ['position' => $p->position, 'decoration' => $p->decoration_name ?: $p->methodName(), 'colours' => $p->colours, 'width_mm' => $p->width_mm,
                'logo' => $p->logo_name, 'units' => $units, 'unit_cost' => $unit, 'setup_cost' => $set, 'total' => $unit !== null ? Money::roundToCent($unit * $units) : null];
        }

        // Options the client chose (clips, packaging…), at cost where the catalogue has it.
        $extras = [];
        foreach ((array) ($order->options ?? []) as $o) {
            $opt = PriceOption::query()->where('option_id', (string) ($o['id'] ?? ''))->first();
            $unit = $opt ? Money::roundToCent($opt->costs()->priceAt(max(1, $units))) : null;
            $complete = $complete && ($opt !== null || ((float) ($o['unit'] ?? 0) <= 0 && (float) ($o['once'] ?? 0) <= 0));
            $extras[] = ['name' => trim(($o['group'] ?? '').': '.($o['name'] ?? ''), ': '), 'n' => (int) ($o['n'] ?? 1), 'unit_cost' => $unit,
                'setup_cost' => $opt ? (float) $opt->setup_cost : null, 'total' => $opt ? Money::roundToCent($unit * $units + (float) $opt->setup_cost) : null];
        }

        $stages = collect($order->eta_breakdown['stages'] ?? []);
        $made = $stages->reject(fn ($s) => in_array($s['key'] ?? '', ['freight', 'transit'], true))->last();
        $number = 'PO-'.$order->number;
        $n = PurchaseOrder::query()->where('number', 'like', $number.'%')->count();

        $po = new PurchaseOrder([
            'number' => $n ? $number.'-'.($n + 1) : $number,
            'website_order_id' => $order->id,
            'supplier_id' => $order->supplier_id,
            'deliver_to' => [
                'name' => $order->contact_name, 'organisation' => $order->organisation, 'unit' => $order->address_unit, 'street' => $order->address_street,
                'suburb' => $order->address_suburb, 'state' => $order->address_state, 'postcode' => $order->address_postcode, 'country' => $order->address_country, 'phone' => $order->phone,
            ],
            'need_by' => $order->need_by,
            'despatch_by' => $made['ends'] ?? null,
            'event' => collect([$order->event_name, $order->event_date?->format('D j M Y'), $order->event_place])->filter()->implode(' · ') ?: null,
            'lines' => $lines, 'branding' => $branding, 'extras' => $extras ?: null,
            'goods_cost' => Money::roundToCent($goods), 'branding_cost' => Money::roundToCent($brandingCost), 'setup_cost' => Money::roundToCent($setup),
            'freight_cost' => null, 'costs_complete' => $complete,
            'artwork_token' => Str::random(40),
            'created_by' => $by?->id,
        ]);
        $po->recalculate();
        $po->save();
        AuditLog::record('purchase_order.created', $order, ['po' => $po->number]);

        return $po;
    }
}
