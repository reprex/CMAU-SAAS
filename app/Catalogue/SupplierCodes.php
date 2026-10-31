<?php

namespace App\Catalogue;

use App\Models\Product;
use App\Models\ProductSku;
use Illuminate\Support\Facades\DB;

/**
 * Supplier codes on the website (docs/supplier-codes.md). Clients search for
 * supplier codes and quote them to us, so every product page shows the
 * supplier's product code and the supplier SKU of every colour and size.
 * Stock levels are always live from Promodata (never stored); the SKU codes
 * a live check returns are kept so the page text can list them for search
 * engines. No extra calls are made to collect them.
 */
class SupplierCodes
{
    /**
     * Store the SKUs from a stock check. Marks a live product for publishing
     * when its SKU list changed (quantities alone don't count).
     *
     * @param  list<array{colour: ?string, size: ?string, qty: ?int, sku: ?string}>  $rows
     */
    public function record(int $productId, array $rows): void
    {
        $product = Product::query()->find($productId);
        if (! $product) {
            return;
        }
        $rows = array_values(array_filter($rows, fn ($r) => filled($r['sku'] ?? null) || filled($r['colour'] ?? null)));
        $key = fn ($r) => ($r['colour'] ?? '').'|'.($r['size'] ?? '').'|'.($r['sku'] ?? '');
        $before = ProductSku::query()->where('product_id', $productId)->orderBy('position')->get(['colour', 'size', 'sku'])
            ->map(fn ($r) => $key($r->toArray()))->all();
        $after = array_map($key, $rows);

        DB::transaction(function () use ($product, $rows) {
            ProductSku::query()->where('product_id', $product->id)->delete();
            $now = now();
            foreach (array_chunk($rows, 200, true) as $chunk) {
                ProductSku::query()->insert(array_map(fn ($r, $i) => [
                    'product_id' => $product->id, 'position' => $i,
                    'colour' => isset($r['colour']) ? mb_substr($r['colour'], 0, 255) : null,
                    'size' => isset($r['size']) ? mb_substr($r['size'], 0, 60) : null,
                    'sku' => isset($r['sku']) ? mb_substr($r['sku'], 0, 120) : null,
                    'qty' => null, 'created_at' => $now, 'updated_at' => $now,
                ], $chunk, array_keys($chunk)));
            }
        });

        $product->forceFill(['skus_checked_at' => now()]);
        if ($before !== $after && $product->tier === Product::TIER_LIVE) {
            $product->range_changed_at = now();
        }
        $product->save();
    }

    /**
     * What Shopify gets: product metafields `custom.suppliercode` (for the
     * product page and Google's mpn) and `custom.supplierskus` (JSON list of
     * colour, size and SKU, without quantities, which go stale).
     *
     * @return array{supplier_code: string, skus: list<array{colour: ?string, size: ?string, sku: string}>}
     */
    public function forShopify(Product $product): array
    {
        $product->loadMissing('supplier');
        $hidden = (array) $product->overrideValue('hidden_colours', []);
        $skus = ProductSku::query()->where('product_id', $product->id)->whereNotNull('sku')->orderBy('position')->get()
            ->reject(fn ($r) => $r->colour !== null && in_array($r->colour, $hidden, true))
            ->unique('sku')
            ->map(fn ($r) => ['colour' => $r->colour, 'size' => $r->size, 'sku' => $r->sku])->values()->all();

        return [
            'supplier_code' => trim(($product->supplier?->name ? $product->supplier->name.' ' : '').(string) $product->code),
            'skus' => $skus,
        ];
    }
}
