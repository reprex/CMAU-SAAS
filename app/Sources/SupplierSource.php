<?php

namespace App\Sources;

use DateTimeInterface;

/**
 * A source of supplier product data. Promodata is the first implementation;
 * direct supplier APIs can be added later without changing the catalogue code.
 */
interface SupplierSource
{
    /** Short identifier stored on products and sync runs, e.g. "promodata". */
    public function name(): string;

    /**
     * One page of products. With $since, only products changed since then.
     */
    public function fetchPage(int $page, ?DateTimeInterface $since = null, ?int $supplierId = null): SourcePage;

    /** A single product with full detail, or null if the source has no such product. */
    public function fetchProduct(int $productId): ?SourceProduct;
}
