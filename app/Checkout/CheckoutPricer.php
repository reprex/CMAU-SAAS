<?php

namespace App\Checkout;

use App\Catalogue\OurCodes;
use App\Catalogue\ProductOptions;
use App\Freight\Consignment;
use App\Freight\FreightDriver;
use App\Freight\PackData;
use App\Models\PriceOption;
use App\Models\Product;
use App\Models\ProductWebPrice;
use App\Models\RelatedStyle;
use App\Pricing\Money;
use App\Pricing\PriceString;
use App\Stock\PromodataStock;
use Carbon\CarbonImmutable;

/**
 * Prices a website checkout and decides whether it can be ordered directly
 * (Scott, 1 Nov 2026; docs/website-checkout.md). Everything is worked out here,
 * from the SaaS pricing and a live stock check; nothing the client sends about
 * prices is read.
 *
 *  - One product, plus its staff-vetted related styles (same supplier). The
 *    whole order is priced at the unbranded price break for the TOTAL quantity,
 *    as one SKU; each style keeps its own unbranded price at that break.
 *  - Unit = unbranded + every position's decoration per unit. Setup once per
 *    position (and the product's own setup once), not per style.
 *  - Ex GST throughout. GST 10% on goods, setup and freight in Australia;
 *    exports are GST-free and the client pays local taxes and duties on arrival.
 *  - Direct order only when: every SKU line is in stock (live), no position needs
 *    artwork review, every position has the client's logo, and the estimated
 *    delivery date meets the need-by date. Otherwise the issues are returned.
 */
class CheckoutPricer
{
    public function __construct(
        private readonly PromodataStock $stock,
        private readonly BrandingPricer $branding,
        private readonly FreightDriver $freight,
        private readonly PackData $packs,
        private readonly DeliveryEstimator $estimator,
    ) {}

    /**
     * @param  array{product_id: int, lines: list<array{product_id: int, colour: string, size: string, qty: int}>,
     *               positions: list<array{name: string, decID: string, colours?: ?int, width_mm?: ?int, has_logo?: bool}>,
     *               delivery: array{country: string, postcode?: ?string}, freight_service?: ?string, need_by?: ?string}  $in
     * @return array<string, mixed>
     */
    public function evaluate(array $in, ?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::now(config('app.timezone')))->startOfDay();
        $issues = [];
        $issue = function (string $code, string $message, array $extra = []) use (&$issues) {
            $issues[] = ['code' => $code, 'message' => $message] + $extra;
        };

        $main = Product::query()->with('supplier')->find((int) $in['product_id']);
        if ($main === null || $main->discontinued) {
            return ['found' => false];
        }
        $related = RelatedStyle::query()->where('product_id', $main->id)->with('related')->orderBy('position')->get()
            ->filter(fn (RelatedStyle $r) => $r->related && ! $r->related->discontinued && $r->related->supplier_id === $main->supplier_id);
        $allowed = collect([$main->id => $main])->union($related->mapWithKeys(fn ($r) => [$r->related_product_id => $r->related]));

        // ---- Lines: merge repeats, keep only the product and its related styles.
        $lines = [];
        foreach ($in['lines'] as $l) {
            $pid = (int) $l['product_id'];
            if (! $allowed->has($pid)) {
                $issue('unrelated', 'Only this product and its related styles can be ordered together. Please ask for a quote for other products.', ['product_id' => $pid]);

                continue;
            }
            $key = $pid.'|'.mb_strtolower(trim($l['colour'])).'|'.mb_strtolower(trim($l['size']));
            $lines[$key] ??= ['product_id' => $pid, 'colour' => trim($l['colour']), 'size' => trim($l['size']), 'qty' => 0];
            $lines[$key]['qty'] += (int) $l['qty'];
        }
        $lines = array_values($lines);
        $units = array_sum(array_column($lines, 'qty'));
        if ($units < 1) {
            $issue('qty', 'Enter how many of each size you want.');
        }

        // ---- Live stock for every SKU line (never stored for the decision).
        $stockByProduct = [];
        foreach (array_unique(array_column($lines, 'product_id')) as $pid) {
            $product = $allowed[$pid];
            $stockByProduct[$pid] = $product->can_check_stock ? $this->stock->rows($pid, fresh: true) : ['ok' => false, 'message' => 'no live stock', 'rows' => []];
            if (! $stockByProduct[$pid]['ok']) {
                $issue('stock_unavailable', "We couldn't check {$product->name} stock with the supplier just now.", ['product_id' => $pid]);
            }
        }

        // ---- Unbranded price of each style at the TOTAL quantity's break.
        $undec = [];
        foreach ($allowed as $pid => $product) {
            $web = ProductWebPrice::query()->find($pid);
            $opt = $web?->undecorated_option_id ? PriceOption::query()->where('option_id', $web->undecorated_option_id)->first() : null;
            $undec[$pid] = $opt;
        }
        foreach (array_unique(array_column($lines, 'product_id')) as $pid) {
            if ($undec[$pid] === null) {
                $issue('price', "{$allowed[$pid]->name} can't be priced online.", ['product_id' => $pid]);
            }
        }

        // ---- Branding: each position priced from the main product's decorations at the total quantity.
        $decProductId = (int) (ProductWebPrice::query()->find($main->id)?->decoration_product_id ?: $main->id);
        $options = $this->branding->options($decProductId);
        $positions = [];
        if ($in['positions'] === []) {
            $issue('branding', 'Choose at least one branding position.');
        }
        foreach ($in['positions'] as $p) {
            $priced = $this->branding->price($options, (string) $p['decID'], isset($p['colours']) ? (int) $p['colours'] : null, max(1, $units));
            if ($priced === null) {
                $issue('decoration', "{$p['name']}: that branding isn't available for this product.", ['position' => $p['name']]);

                continue;
            }
            $priced = ['name' => $p['name'], 'width_mm' => isset($p['width_mm']) ? (int) $p['width_mm'] : null, 'has_logo' => (bool) ($p['has_logo'] ?? false)] + $priced;
            if ($priced['method'] === null) {
                $issue('review', "{$p['name']}: {$priced['decoration_name']} needs our team to check your artwork.", ['position' => $p['name']]);
            } elseif ($priced['needs_review']) {
                $what = $priced['method'] === 'embroidery' ? 'and stitch count' : 'and colours';
                $issue('review', "{$p['name']}: ".mb_strtolower($priced['method_name'])." needs our team to check your artwork {$what}.", ['position' => $p['name'], 'method' => $priced['method']]);
            }
            if (! $priced['has_logo']) {
                $issue('logo', "{$p['name']}: upload your logo.", ['position' => $p['name']]);
            }
            $positions[] = $priced;
        }
        $speedDays = $this->checkSpeed($main, array_column($in['positions'], 'decID'), max(1, $units), $issue);
        // ---- Product options (custom.options, docs/product-options.md): priced here from the SaaS's own lines, never the client's.
        $opts = $this->options($main, $in['options'] ?? [], max(1, $units), $positions, $issue);
        $brandUnit = array_sum(array_column($positions, 'unit')) + $opts['unit'];
        $brandSetup = array_sum(array_column($positions, 'setup')) + $opts['once'];
        $productSetup = $undec[$main->id] ? Money::roundToCent((float) $undec[$main->id]->setup_price) : 0.0;

        // ---- Lines priced, with stock.
        $styles = [];
        $alerts = [];
        foreach ($lines as $i => $l) {
            $pid = $l['product_id'];
            $unbranded = $undec[$pid] ? Money::roundToCent($undec[$pid]->prices()->priceAt(max(1, $units))) : 0.0;
            $unit = Money::roundToCent($unbranded + $brandUnit);
            $row = $this->stockRow($stockByProduct[$pid]['rows'] ?? [], $l['colour'], $l['size']);
            $label = ($pid === $main->id ? '' : $allowed[$pid]->name.' · ')."{$l['colour']} {$l['size']}";
            if ($stockByProduct[$pid]['ok'] ?? false) {
                if ($row === null || $row['qty'] === null) {
                    $issue('stock', "{$label}: the supplier doesn't list this colour and size.", ['product_id' => $pid, 'colour' => $l['colour'], 'size' => $l['size']]);
                } elseif ($l['qty'] > $row['qty']) {
                    $issue('stock', "{$label}: you want {$l['qty']}, the supplier has {$row['qty']}.", ['product_id' => $pid, 'colour' => $l['colour'], 'size' => $l['size'], 'available' => $row['qty']]);
                } elseif ($row['qty'] > 0 && $l['qty'] >= $row['qty'] * (float) config('website.orders.stock_low_share')) {
                    $alerts[] = ['code' => 'stock_low', 'message' => "Stock low: {$label} takes {$l['qty']} of the supplier's {$row['qty']}. We'll confirm it's still there before you pay."];
                }
            }
            $our = OurCodes::code($allowed[$pid]);
            $lines[$i] += [
                'product_code' => $our,                      // our code (SupplierID-Code); clients never see the supplier's
                'product_name' => $allowed[$pid]->name,
                'sku' => OurCodes::sku($our, $l['colour'], $l['size']),
                'supplier_product_code' => $allowed[$pid]->code, // staff only: removed from the public reply
                'supplier_sku' => $row['sku'] ?? null,           // staff only: removed from the public reply
                'stock' => $row['qty'] ?? null,
                'unbranded_unit' => $unbranded,
                'branding_unit' => Money::roundToCent($brandUnit),
                'unit' => $unit,
                'total' => Money::roundToCent($unit * $l['qty']),
            ];
            $styles[$pid] ??= ['product_id' => $pid, 'code' => $our, 'name' => $allowed[$pid]->name, 'units' => 0, 'unbranded_unit' => $unbranded, 'branding_unit' => Money::roundToCent($brandUnit), 'unit' => $unit, 'goods' => 0.0];
            $styles[$pid]['units'] += $l['qty'];
            $styles[$pid]['goods'] = Money::roundToCent($styles[$pid]['goods'] + $lines[$i]['total']);
        }
        $goods = Money::roundToCent(array_sum(array_column($lines, 'total')));
        $setup = $units > 0 ? Money::roundToCent($brandSetup + $productSetup) : 0.0;

        // ---- Freight from the pack data of each style.
        $country = mb_strtoupper((string) ($in['delivery']['country'] ?? 'AU'));
        $consignment = Consignment::from(array_map(fn ($s) => ['pack' => $this->packs->for($allowed[$s['product_id']]), 'units' => $s['units']], array_values($styles)));
        $freight = $units > 0 ? $this->freight->quote($consignment, $country, $in['delivery']['postcode'] ?? null) : null;
        $chosen = null;
        if ($freight && $freight['ok']) {
            $chosen = collect($freight['options'])->firstWhere('id', $in['freight_service'] ?? null) ?? $freight['options'][0] ?? null;
            if (($in['freight_service'] ?? null) && $chosen['id'] !== $in['freight_service']) {
                $issue('freight', 'That delivery option is not available for this address.');
            }
        } elseif ($freight) {
            $issue('freight', $freight['message'] ?? 'We can\'t price delivery to that address online.');
        }
        $freightPrice = $chosen ? (float) $chosen['price'] : 0.0;

        // ---- Delivery estimate per service, and the deadline check.
        $supplier = $main->supplier;
        $state = (string) ($supplier?->state ?: config('website.lead_times.default_state'));
        $stockDays = collect(array_keys($styles))->map(fn ($pid) => $allowed[$pid]->supplier?->stockLeadDays() ?? (int) config('website.lead_times.stock_default'))->max()
            ?? (int) config('website.lead_times.stock_default');
        $slowest = collect($positions)->pluck('method')->filter()->sortByDesc(fn ($m) => (int) config('website.lead_times.decoration.'.$m))->first() ?? 'dtf';
        if (collect($positions)->contains(fn ($p) => $p['method'] === null)) {
            $slowest = collect(config('website.lead_times.decoration'))->sort()->keys()->last();
        }
        $estimates = [];
        foreach ($freight['options'] ?? [] as $o) {
            // No supplier name: the estimate is shown to the client ("Stock from the supplier").
            $estimates[$o['id']] = $this->estimator->estimate($state, '', $speedDays !== null ? 0 : $stockDays, $slowest, $o, $today, $speedDays);
        }
        foreach ($freight['options'] ?? [] as $k => $o) {
            $freight['options'][$k]['delivery_date'] = $estimates[$o['id']]['delivery_date'];
        }
        $deadline = null;
        $needBy = ! empty($in['need_by']) ? CarbonImmutable::parse($in['need_by'], config('app.timezone'))->startOfDay() : null;
        if ($needBy === null) {
            $issue('need_by', 'Enter the date you need it by.');
        } elseif ($needBy->lte($today)) {
            $issue('need_by', 'The need-by date must be after today.');
        } elseif ($chosen) {
            $deadline = $this->estimator->deadline($estimates, $chosen['id'], $needBy, $state, collect($freight['options'])->pluck('name', 'id')->all());
            if (! $deadline['met']) {
                $date = CarbonImmutable::parse($needBy)->format('D j M');
                $deadline['faster_service']
                    ? $issue('deadline', "{$chosen['name']} arrives too late for {$date}. Choose {$deadline['faster_service']['name']}.", ['faster_service' => $deadline['faster_service']['id']])
                    : $issue('late', "We can't promise delivery by {$date} online. The earliest is ".CarbonImmutable::parse($deadline['earliest']['delivery_date'])->format('D j M').'.');
            } elseif ($deadline['spare_business_days'] <= (int) config('website.orders.deadline_risk_spare_days')) {
                array_unshift($alerts, ['code' => 'deadline_at_risk', 'message' => 'Deadline at risk: only '.$deadline['spare_business_days'].' business day'.($deadline['spare_business_days'] === 1 ? '' : 's').' to spare before your date. Any delay with stock, artwork or freight could make it late.']);
            }
        }

        // ---- Totals.
        $export = $country !== 'AU';
        $subtotal = Money::roundToCent($goods + $setup + $freightPrice);
        $gst = $export ? 0.0 : Money::roundToCent($subtotal * (float) config('quotes.gst_rate'));
        if ($export) {
            $alerts[] = ['code' => 'export', 'message' => config('website.orders.duty_note')];
        }

        return [
            'found' => true,
            'can_order' => $issues === [],
            'issues' => $issues,
            'product' => ['id' => $main->id, 'code' => OurCodes::code($main), 'name' => $main->name],
            'related_styles' => $related->values()->map(fn (RelatedStyle $r) => ['product_id' => $r->related_product_id, 'code' => OurCodes::code($r->related), 'name' => $r->related->name, 'label' => $r->label])->all(),
            'units' => $units,
            'priced_at_qty' => $units,
            'styles' => array_values($styles),
            'lines' => $lines,
            'positions' => $positions,
            'options' => $opts['items'],
            'setup' => [
                'positions' => array_map(fn ($p) => ['name' => $p['name'], 'amount' => $p['setup']], $positions),
                'options' => $opts['once'],
                'product' => $productSetup,
                'total' => $setup,
            ],
            'freight' => $freight ? $freight + ['chosen' => $chosen['id'] ?? null] : null,
            'delivery_estimate' => $chosen ? $estimates[$chosen['id']] : null,
            'deadline' => $deadline,
            'totals' => [
                'goods' => $goods, 'setup' => $setup, 'freight' => $freightPrice, 'subtotal' => $subtotal,
                'gst_rate' => $export ? 0 : (float) config('quotes.gst_rate'), 'gst' => $gst,
                'total' => Money::roundToCent($subtotal + $gst), 'inc_gst' => ! $export, 'currency' => 'AUD',
            ],
            'export' => $export,
            'duty_note' => $export ? config('website.orders.duty_note') : null,
            'payment_terms' => $supplier?->payment_terms ?: config('website.payment_terms.default'),
            'alerts' => $alerts,
            'artwork_review_note' => collect($positions)->contains('needs_review', true) ? 'Prices subject to the CrowdMerch team reviewing your artwork.' : null,
        ];
    }

    /**
     * The reply for the website (Scott, 1 Nov 2026): our codes only, never the
     * supplier's name or its own codes (those stay for staff and ordering).
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public static function publicReply(array $result): array
    {
        if (isset($result['lines'])) {
            $result['lines'] = array_map(fn ($l) => array_diff_key($l, ['supplier_sku' => 1, 'supplier_product_code' => 1]), $result['lines']);
        }

        return $result;
    }

    /** @param list<array{colour: ?string, size: ?string, qty: ?int, sku: ?string}> $rows */
    /**
     * Price the chosen product options: [{id, n}] against the product's custom.options.
     *
     * @return array{items: list<array<string, mixed>>, unit: float, once: float}
     */
    /**
     * Production time (Dex, docs/product-options.md): every position must use the same production time,
     * within the supplier's quantity range for that line. Returns that time's working days (the
     * supplier's stock and branding together) for the delivery estimate.
     *
     * @param  list<string>  $decIds
     */
    private function checkSpeed(Product $main, array $decIds, int $units, callable $issue): ?int
    {
        $main->loadMissing('priceOptions');
        $speed = app(ProductOptions::class)->speedFor($main);
        if (! $speed) {
            return null;
        }
        $used = [];
        foreach ($decIds as $id) {
            foreach ($speed['options'] as $o) {
                if (in_array((string) $id, $o['ids'], true)) {
                    $used[$o['key']] = $o;
                    $r = $o['ranges'][(string) $id] ?? null;
                    if ($r && ($units < $r[0] || $units > $r[1])) {
                        $issue('speed', "{$o['name']} production is available for {$r[0]} to {$r[1]} units of {$main->name}. Choose a longer production time.", ['speed' => $o['key']]);
                    }
                }
            }
        }
        if (count($used) > 1) {
            $issue('speed', 'Choose one production time for all branding positions.', ['speed' => array_keys($used)]);
        }

        return $used ? max(array_map(fn ($o) => (int) ($o['days'] ?? 0), $used)) : null;
    }

    private function options(Product $main, array $chosen, int $units, array $positions, callable $issue): array
    {
        $out = ['items' => [], 'unit' => 0.0, 'once' => 0.0];
        if ($chosen === []) {
            return $out;
        }
        $main->loadMissing('priceOptions');
        $groups = app(ProductOptions::class)->forProduct($main)['groups'] ?? [];
        $byId = [];
        foreach ($groups as $g) {
            foreach ($g['options'] as $o) {
                $byId[$o['id']] = [$g, $o];
            }
        }
        $methods = array_filter(array_column($positions, 'method'));
        $ns = [];
        foreach ($chosen as $c) {
            $ns[(string) ($c['id'] ?? '')] = max(1, min(20, (int) ($c['n'] ?? 1)));
        }
        $oneTaken = [];
        foreach ($ns as $id => $n) {
            [$g, $o] = $byId[$id] ?? [null, null];
            if (! $o) {
                $issue('option', "That option isn't available for {$main->name} any more.", ['option' => $id]);

                continue;
            }
            if (($g['with_method'] ?? null) && ! array_intersect($g['with_method'], $methods)) {
                continue; // e.g. embroidery extras without embroidery
            }
            if ($g['pick'] === 'one' && isset($oneTaken[$g['key']])) {
                continue;
            }
            $oneTaken[$g['key']] = true;
            $n = $g['pick'] === 'count' ? min($n, (int) ($g['max'] ?? 3)) : (isset($o['count']) ? min($n, (int) ($o['count']['max'] ?? 9)) : 1);
            $each = isset($o['breaks']) ? PriceString::parse($o['breaks'])->priceAt($units) : 0.0;
            $unit = 0.0;
            $once = (float) ($o['setup'] ?? 0) * (($o['per'] ?? null) === 'order' ? $n : 1);
            if (isset($o['per'])) {
                $once += $each * ($o['per'] === 'order' ? $n : 1);
            } elseif (isset($o['per_stitches'])) {
                $extra = collect($ns)->filter(fn ($k, $i) => isset($byId[$i]) && $byId[$i][0]['key'] === $g['key'] && isset($byId[$i][1]['count']))->sum();
                $unit = $each * ((int) ($g['base_stitches'] ?? 10000) + 5000 * $extra) / (int) $o['per_stitches'];
            } else {
                $unit = $each * $n;
            }
            if (($o['per'] ?? null) === 'artwork') {
                $once *= max(1, count($positions));
            }
            $item = ['group' => $g['label'], 'id' => $id, 'name' => $o['name'], 'n' => $n, 'unit' => Money::roundToCent($unit), 'once' => Money::roundToCent($once), 'included' => (bool) ($o['included'] ?? false)];
            $out['items'][] = $item;
            $out['unit'] += $item['unit'];
            $out['once'] += $item['once'];
        }
        $out['unit'] = Money::roundToCent($out['unit']);
        $out['once'] = Money::roundToCent($out['once']);

        return $out;
    }

    private function stockRow(array $rows, string $colour, string $size): ?array
    {
        $norm = fn (?string $s) => preg_replace('/\s+/', ' ', mb_strtolower(trim((string) $s)));
        // A row with no size is ordered as "One size" (the theme's name for it).
        $sizeOf = fn (?string $s) => $norm($s) === '' ? 'one size' : $norm($s);
        foreach ($rows as $r) {
            if ($norm($r['colour']) === $norm($colour) && $sizeOf($r['size']) === $sizeOf($size)) {
                return $r;
            }
        }

        return null;
    }
}
