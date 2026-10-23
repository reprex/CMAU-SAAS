<?php

namespace App\Catalogue;

use App\Models\Product;
use App\Sources\SupplierSource;

/**
 * Fetches a product's full detail on demand (when staff open, quote or add a
 * summary-tier product) and promotes it to the active tier.
 */
class ProductHydrator
{
    public function __construct(
        private readonly SupplierSource $source,
        private readonly CatalogueWriter $writer,
    ) {}

    public function hydrate(int $productId): ?Product
    {
        $sourceProduct = $this->source->fetchProduct($productId);
        if ($sourceProduct === null) {
            return null;
        }

        $this->writer->store($sourceProduct, forceDetail: true);

        $product = Product::query()->findOrFail($productId);
        $product->forceFill([
            'tier' => $product->tier === Product::TIER_LIVE ? Product::TIER_LIVE : Product::TIER_ACTIVE,
            'last_used_at' => now(),
        ])->save();

        return $product;
    }

    /** Records use of a product (quote, order, saved list) so it stays in the active tier. */
    public function touch(Product $product): void
    {
        $product->forceFill(['last_used_at' => now()])->save();
    }
}
