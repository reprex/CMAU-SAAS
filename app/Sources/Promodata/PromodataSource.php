<?php

namespace App\Sources\Promodata;

use App\Sources\SourcePage;
use App\Sources\SourceProduct;
use App\Sources\SupplierSource;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

class PromodataSource implements SupplierSource
{
    public function __construct(
        private readonly PromodataClient $client,
        private readonly PromodataNormaliser $normaliser,
    ) {}

    public function name(): string
    {
        return 'promodata';
    }

    public function fetchPage(int $page, ?DateTimeInterface $since = null, ?int $supplierId = null): SourcePage
    {
        $json = $this->client->listProducts($page, $since, $supplierId);

        $products = [];
        foreach (array_values((array) ($json['data'] ?? [])) as $item) {
            try {
                $product = $this->normaliser->normalise((array) $item);
                if ($product->id > 0) {
                    $products[] = $product;
                }
            } catch (Throwable $e) {
                // One malformed product must not stop the whole page (TOD logged these to tblPdError).
                Log::warning('Skipped malformed Promodata product', [
                    'page' => $page,
                    'id' => $item['meta']['id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return new SourcePage(
            page: $page,
            totalPages: max(1, (int) ($json['total_pages'] ?? 1)),
            products: $products,
        );
    }

    public function fetchProduct(int $productId): ?SourceProduct
    {
        $json = $this->client->getProduct($productId);
        if ($json === null || ! is_array($json['data'] ?? null)) {
            return null;
        }

        return $this->normaliser->normalise($json['data']);
    }
}
