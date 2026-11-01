<?php

namespace App\Catalogue;

use App\Models\BrandingTemplate;
use App\Models\PriceLine;
use App\Models\Product;
use App\Models\Supplier;
use App\Sources\SourceProduct;
use Illuminate\Support\Facades\DB;

/**
 * Stores source products according to their tier:
 *  - every product gets (or keeps) its compact summary row;
 *  - only live/active products (or an explicit hydrate) keep colours,
 *    images, price lines and details.
 * Image data is stored as public URLs only.
 */
class CatalogueWriter
{
    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const UNCHANGED = 'unchanged';

    /** Raise when ProductFacts changes, so the next sync refills every summary row once. */
    public const SUMMARY_VERSION = 1;

    /**
     * @param  bool  $forceDetail  store full detail even for a summary-tier product (used when hydrating)
     */
    public function store(SourceProduct $source, bool $forceDetail = false): string
    {
        return DB::transaction(function () use ($source, $forceDetail) {
            $this->upsertSupplier($source);

            /** @var Product|null $product */
            $product = Product::query()->lockForUpdate()->find($source->id);
            $isNew = $product === null;

            $wantsDetail = $forceDetail || ($product?->keepsFullDetail() ?? false);
            $hasCurrentDetail = $product !== null && $product->hydrated_at !== null;

            if (! $isNew
                && $product->content_hash === $source->contentHash
                && (int) $product->summary_version >= self::SUMMARY_VERSION
                && (! $wantsDetail || $hasCurrentDetail)) {
                return self::UNCHANGED;
            }

            [$minCost, $minQty] = $source->lowestBaseCost();

            $product ??= new Product(['id' => $source->id, 'tier' => Product::TIER_SUMMARY]);
            $product->fill([
                'source' => $source->source,
                'supplier_id' => $source->supplierId,
                'code' => self::clip($source->code),
                'name' => self::clip($source->name !== '' ? $source->name : ($source->code ?? (string) $source->id)),
                'discontinued' => $source->discontinued,
                'can_check_stock' => $source->canCheckStock,
                'country' => self::clip($source->country, 8),
                'supplier_category' => self::clip($source->supplierCategory),
                'supplier_subcategory' => self::clip($source->supplierSubcategory),
                'promodata_type_id' => self::clip($source->promodataTypeId, 32),
                'promodata_type_name' => self::clip($source->promodataTypeName),
                'hero_image_url' => self::clip($source->heroImageUrl ?? ($source->imageUrls[0] ?? null), 1024),
                'colour_count' => $source->colourCount(),
                'min_unit_cost' => $minCost,
                'min_qty' => $minQty,
                'first_listed_at' => $source->firstListedAt,
                'last_changed_at' => $source->lastChangedAt,
                'prices_changed_at' => $source->pricesChangedAt,
                'discontinued_at' => $source->discontinuedAt,
                'content_hash' => $source->contentHash,
                'summary_version' => self::SUMMARY_VERSION,
            ] + self::clipFacts(ProductFacts::from($source)));

            if ($wantsDetail) {
                $product->hydrated_at = now();
            }

            $product->save();

            if ($wantsDetail) {
                $this->writeDetail($product, $source);
            }

            return $isNew ? self::CREATED : self::UPDATED;
        });
    }

    /** Removes the full-detail rows, keeping the summary row and any staff overrides. */
    public function dropDetail(Product $product): void
    {
        DB::transaction(function () use ($product) {
            $this->dropDetailRows($product);
            $product->forceFill(['hydrated_at' => null])->save();
        });
    }

    private function upsertSupplier(SourceProduct $source): void
    {
        $supplier = Supplier::query()->find($source->supplierId);

        if ($supplier === null) {
            Supplier::query()->create([
                'id' => $source->supplierId,
                'name' => $source->supplierName !== '' ? $source->supplierName : 'Supplier '.$source->supplierId,
                'country' => $source->supplierCountry,
                'appa_member_number' => $source->supplierAppaMemberNumber,
            ]);

            return;
        }

        // Only refresh identity fields; markups and settings belong to staff.
        $supplier->fill(array_filter([
            'name' => $source->supplierName,
            'country' => $source->supplierCountry,
            'appa_member_number' => $source->supplierAppaMemberNumber,
        ], fn ($v) => $v !== null && $v !== ''));

        if ($supplier->isDirty()) {
            $supplier->save();
        }
    }

    private function writeDetail(Product $product, SourceProduct $source): void
    {
        $this->dropDetailRows($product);

        $product->detail()->create([
            'description' => $source->description,
            'supplier_brand' => self::clip($source->supplierBrand),
            'supplier_label' => self::clip($source->supplierLabel),
            'supplier_catalogue' => $source->supplierCatalogue,
            'supplier_website_page' => $source->supplierWebsitePage,
            'details' => $source->details,
            'promodata_attributes' => $source->promodataAttributes,
            'appa_product_types' => $source->appaProductTypes,
            'colour_notes' => $source->colourNotes,
            'price_tags' => $source->priceTags,
        ]);

        foreach ($source->colours as $i => $colour) {
            $product->colours()->create($colour + ['position' => $i]);
        }

        $position = 0;
        if ($source->heroImageUrl !== null) {
            $product->images()->create(['url' => $source->heroImageUrl, 'role' => 'hero_style', 'position' => $position++]);
        }
        foreach ($source->imageUrls as $url) {
            if ($url === $source->heroImageUrl) {
                continue;
            }
            $product->images()->create(['url' => $url, 'role' => 'other_style', 'position' => $position++]);
        }
        // Supplier line art / branding templates (docs/branding-templates.md): kept apart from the photos.
        foreach ($source->lineArtUrls as $url) {
            $product->images()->create(['url' => $url, 'role' => 'line_art', 'position' => $position++]);
            BrandingTemplate::query()->firstOrCreate(['product_id' => $product->id, 'url' => $url], ['source' => 'promodata']);
        }
        app(ImageTagger::class)->apply($product);

        $position = 0;
        foreach ($source->priceGroups as $group) {
            $base = $this->createPriceLine($product, $group['base'], null, $position++);
            foreach ($group['additions'] as $addition) {
                $this->createPriceLine($product, $addition, $base->id, $position++);
            }
        }
    }

    /** @param  array<string, mixed>  $line */
    private function createPriceLine(Product $product, array $line, ?int $baseId, int $position): PriceLine
    {
        /** @var PriceLine $priceLine */
        $priceLine = $product->priceLines()->create([
            'base_price_line_id' => $baseId,
            'position' => $position,
            'source_key' => $line['key'],
            'type' => $line['type'],
            'description' => $line['description'],
            'setup_cost' => $line['setup'],
            'currency' => $line['currency'],
            'lead_time' => $line['lead_time'],
            'undecorated' => $line['undecorated'],
            'indent' => $line['indent'],
            'promodata_decoration' => $line['promodata_decoration'],
            'tags' => $line['tags'],
        ]);

        foreach ($line['breaks'] as $break) {
            $priceLine->breaks()->create(['qty' => $break['qty'], 'unit_cost' => $break['price']]);
        }

        return $priceLine;
    }

    private function dropDetailRows(Product $product): void
    {
        $product->detail()->delete();
        $product->colours()->delete();
        $product->images()->delete();
        // Add-on lines reference base lines, so delete them first.
        $product->priceLines()->whereNotNull('base_price_line_id')->delete();
        $product->priceLines()->delete();
    }

    /**
     * Promodata sometimes sends a category or name longer than its column (lists
     * joined with commas). Clip it so one long value can't stall the whole sync.
     */
    private static function clip(?string $value, int $max = 255): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $max);
    }

    /** @param array<string, mixed> $facts */
    private static function clipFacts(array $facts): array
    {
        if (isset($facts['material']) && is_string($facts['material'])) {
            $facts['material'] = self::clip($facts['material']);
        }

        return $facts;
    }
}
