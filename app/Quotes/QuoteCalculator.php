<?php

namespace App\Quotes;

use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteExtra;
use App\Models\QuoteItem;
use App\Models\Supplier;
use App\Pricing\Money;
use Illuminate\Support\Collection;

/**
 * Freight lines and totals.
 *
 * Freight comes from each supplier's standard freight (admin only):
 *  - per_delivery: one line per supplier per quote (one delivery address);
 *  - per_line: one line per product line from that supplier;
 *  - free when that supplier's goods on the quote reach free_freight_over;
 *  - suppliers with nothing set get a $0 "to be confirmed" line.
 * Items decorated by an outside decorator (their decoration product belongs
 * to another supplier) are delivered by the decorator; the product supplier's
 * freight is then added too only if that supplier has freight_decorator_leg.
 * Lines staff have overridden keep their price.
 */
class QuoteCalculator
{
    public function recalculate(Quote $quote): Quote
    {
        $quote->load(['items.skus', 'extras']);
        $this->syncFreight($quote);
        $quote->load('extras');

        return $this->totals($quote);
    }

    public function totals(Quote $quote): Quote
    {
        $subtotal = 0.0;
        $cost = 0.0;
        $costKnown = true;

        foreach ($quote->items as $item) {
            $subtotal += $item->total();
            $c = $item->costTotal();
            $c === null ? $costKnown = false : $cost += $c;
        }
        foreach ($quote->extras as $extra) {
            $subtotal += $extra->total();
            $c = $extra->costTotal();
            $c === null ? $costKnown = false : $cost += $c;
        }

        $subtotal = Money::roundToCent($subtotal);
        $gst = Money::roundToCent($subtotal * (float) config('quotes.gst_rate'));

        $quote->forceFill([
            'subtotal' => $subtotal,
            'gst' => $gst,
            'total' => Money::roundToCent($subtotal + $gst),
            'cost_total' => $costKnown ? Money::roundToCent($cost) : null,
        ])->saveQuietly();

        return $quote;
    }

    /**
     * The freight legs a quote needs, keyed so auto lines can be matched.
     *
     * @return array<string, array{supplier: Supplier, goods: float, label: string, items: list<string>}>
     */
    public function freightLegs(Quote $quote): array
    {
        $legs = [];
        $suppliers = Supplier::query()
            ->whereIn('id', $this->supplierIds($quote))
            ->get()->keyBy('id');

        $add = function (string $key, ?Supplier $supplier, float $goods, string $itemName, string $label) use (&$legs) {
            if ($supplier === null) {
                return;
            }
            $legs[$key] ??= ['supplier' => $supplier, 'goods' => 0.0, 'label' => $label, 'items' => []];
            $legs[$key]['goods'] += $goods;
            $legs[$key]['items'][] = $itemName;
        };

        foreach ($quote->items as $item) {
            $supplier = $suppliers->get($item->supplier_id);
            $decorator = $this->outsideDecorator($item, $suppliers);
            $goods = $item->goodsTotal();

            if ($decorator !== null) {
                $add($this->key($decorator, $item, 'client'), $decorator, $goods, $item->name, 'delivery');
                if ($supplier?->freight_decorator_leg) {
                    $add($this->key($supplier, $item, 'decorator'), $supplier, $goods, $item->name, 'to_decorator');
                }
            } else {
                $add($this->key($supplier, $item, 'client'), $supplier, $goods, $item->name, 'delivery');
            }
        }

        foreach ($quote->extras->where('kind', 'custom') as $extra) {
            $supplier = $suppliers->get($extra->supplier_id);
            $add($this->key($supplier, null, 'client', 'x'.$extra->id), $supplier, $extra->total(), $extra->description, 'delivery');
        }

        return $legs;
    }

    /**
     * What one leg charges the client: a price, 0 when free, or null when the
     * supplier has no standard freight yet ("to be confirmed").
     */
    public function legCharge(array $leg): ?float
    {
        /** @var Supplier $s */
        $s = $leg['supplier'];
        if (! $s->hasStandardFreight()) {
            return null;
        }
        if ($s->free_freight_over !== null && $leg['goods'] >= (float) $s->free_freight_over) {
            return 0.0;
        }

        return (float) $s->freight_sell;
    }

    private function syncFreight(Quote $quote): void
    {
        $legs = $this->freightLegs($quote);
        $existing = $quote->extras->where('kind', 'freight')->where('auto', true)->keyBy('auto_key');
        $where = $quote->deliverySuburb();
        $position = 1000;

        foreach ($legs as $key => $leg) {
            /** @var Supplier $s */
            $s = $leg['supplier'];
            $items = implode(', ', array_unique($leg['items']));
            $tbc = ! $s->hasStandardFreight();
            $free = ! $tbc && $s->free_freight_over !== null && $leg['goods'] >= (float) $s->free_freight_over;

            $what = $leg['label'] === 'to_decorator' ? 'Freight to decorator' : ($where ? "Delivery to {$where}" : 'Delivery');
            $description = match (true) {
                $tbc => "{$what}: {$items} (to be confirmed)",
                $free => "{$what}: {$items} (free on this order)",
                default => "{$what}: {$items}",
            };

            $values = [
                'description' => $description,
                'supplier_id' => $s->id,
                'tbc' => $tbc,
                'quantity' => 1,
                'position' => $position++,
            ];

            $line = $existing->get($key);
            if ($line === null || ! $line->overridden) {
                $values['unit_price'] = ($tbc || $free) ? 0 : (float) $s->freight_sell;
                $values['unit_cost'] = $tbc ? null : ($free ? 0 : $s->freight_cost);
            }

            if ($line) {
                $line->fill($values)->saveQuietly();
            } else {
                $quote->extras()->create($values + ['kind' => 'freight', 'auto' => true, 'auto_key' => $key]);
            }
        }

        $quote->extras->where('kind', 'freight')->where('auto', true)
            ->reject(fn (QuoteExtra $e) => array_key_exists($e->auto_key, $legs))
            ->each(fn (QuoteExtra $e) => $e->delete());
    }

    private function key(?Supplier $supplier, ?QuoteItem $item, string $leg, ?string $extraKey = null): string
    {
        if ($supplier === null) {
            return '';
        }
        $perLine = $supplier->freight_basis === 'per_line';

        return "s{$supplier->id}:{$leg}".($perLine ? ':'.($item ? 'i'.$item->id : $extraKey) : '');
    }

    /** @param Collection<int, Supplier> $suppliers */
    private function outsideDecorator(QuoteItem $item, Collection $suppliers): ?Supplier
    {
        if (! $item->hasDecoration() || ! $item->decoration_product_id || $item->decoration_product_id === $item->product_id) {
            return null;
        }
        $decSupplierId = Product::query()->whereKey($item->decoration_product_id)->value('supplier_id');
        if (! $decSupplierId || $decSupplierId === $item->supplier_id) {
            return null;
        }

        return $suppliers->get($decSupplierId) ?? Supplier::find($decSupplierId);
    }

    /** @return list<int> */
    private function supplierIds(Quote $quote): array
    {
        $ids = $quote->items->pluck('supplier_id')
            ->merge($quote->extras->where('kind', 'custom')->pluck('supplier_id'));
        $decProducts = $quote->items->pluck('decoration_product_id')->filter()->unique();
        if ($decProducts->isNotEmpty()) {
            $ids = $ids->merge(Product::query()->whereIn('id', $decProducts)->pluck('supplier_id'));
        }

        return $ids->filter()->unique()->values()->all();
    }
}
