<?php

namespace App\Sources;

use DateTimeInterface;

/**
 * A supplier product in a source-neutral shape. Child arrays use plain PHP
 * arrays so they can be stored directly in JSON columns.
 */
final class SourceProduct
{
    /**
     * @param  list<string>  $imageUrls
     * @param  list<array{name: string, detail: ?string}>  $details
     * @param  list<array{name: string, value: ?string}>  $promodataAttributes
     * @param  list<array{applies_to: ?string, name: string, image_url: ?string, swatch_urls: list<string>, supplier_colours: list<string>, appa_colours: list<string>}>  $colours
     * @param  list<array{base: array<string, mixed>, additions: list<array<string, mixed>>}>  $priceGroups
     *                                                                                                       Each price line: key, type, description, setup, currency, lead_time,
     *                                                                                                       undecorated, indent, promodata_decoration, tags, breaks: list<array{qty:int, price:float}>
     */
    public function __construct(
        public readonly string $source,
        public readonly int $id,
        public readonly int $supplierId,
        public readonly string $supplierName,
        public readonly ?string $supplierCountry,
        public readonly ?string $supplierAppaMemberNumber,
        public readonly string $name,
        public readonly ?string $code,
        public readonly ?string $country,
        public readonly bool $discontinued,
        public readonly bool $canCheckStock,
        public readonly ?DateTimeInterface $firstListedAt,
        public readonly ?DateTimeInterface $lastChangedAt,
        public readonly ?DateTimeInterface $pricesChangedAt,
        public readonly ?DateTimeInterface $discontinuedAt,
        public readonly ?string $supplierCategory,
        public readonly ?string $supplierSubcategory,
        public readonly ?string $promodataTypeId,
        public readonly ?string $promodataTypeName,
        public readonly ?string $heroImageUrl,
        public readonly array $imageUrls,
        public readonly ?string $description,
        public readonly ?string $supplierBrand,
        public readonly ?string $supplierLabel,
        public readonly ?string $supplierCatalogue,
        public readonly ?string $supplierWebsitePage,
        public readonly array $details,
        public readonly array $promodataAttributes,
        public readonly array $appaProductTypes,
        public readonly array $colourNotes,
        public readonly array $colours,
        public readonly array $priceGroups,
        public readonly array $priceTags,
        public readonly string $contentHash,
    ) {}

    /** Number of individual colours offered (falls back to the colour groups). */
    public function colourCount(): int
    {
        $count = 0;
        foreach ($this->colours as $group) {
            $count += max(1, count($group['supplier_colours']));
        }

        return $count;
    }

    /**
     * Lowest unit cost and its quantity across the base (not add-on) price lines.
     *
     * @return array{0: ?float, 1: ?int}
     */
    public function lowestBaseCost(): array
    {
        $minCost = null;
        $minQty = null;

        foreach ($this->priceGroups as $group) {
            foreach ($group['base']['breaks'] ?? [] as $break) {
                if ($minCost === null || $break['price'] < $minCost) {
                    $minCost = $break['price'];
                }
                if ($minQty === null || $break['qty'] < $minQty) {
                    $minQty = $break['qty'];
                }
            }
        }

        return [$minCost, $minQty];
    }
}
