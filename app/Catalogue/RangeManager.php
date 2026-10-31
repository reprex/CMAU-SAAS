<?php

namespace App\Catalogue;

use App\Models\AuditLog;
use App\Models\Product;
use App\Pricing\PriceOptionBuilder;
use App\Pricing\PricePatterns;
use App\Pricing\WebPriceCalculator;
use RuntimeException;
use Throwable;

/**
 * Range curation: which supplier products are on the CrowdMerch web range.
 *
 *  - Adding fetches the product's full detail from the supplier source (if not
 *    already stored), makes it live and prices it the TOD way.
 *  - Removing makes it active: detail and staff overrides are kept, and it
 *    drops to the summary tier after the active window if nobody uses it.
 *  - Every change stamps range_changed_at so the Shopify publisher can send
 *    changed products only.
 */
class RangeManager
{
    public function __construct(
        private readonly ProductHydrator $hydrator,
        private readonly PriceOptionBuilder $builder,
        private readonly WebPriceCalculator $calculator,
    ) {}

    public function add(Product $product): Product
    {
        if ($product->discontinued) {
            throw new RuntimeException("{$product->name} ({$product->id}) is discontinued by the supplier.");
        }

        if ($product->hydrated_at === null || ! $product->keepsFullDetail()) {
            if ($product->source === 'promodata') {
                $product = $this->hydrator->hydrate($product->id)
                    ?? throw new RuntimeException("Promodata no longer has product {$product->id}.");
            } elseif ($product->hydrated_at === null) {
                throw new RuntimeException("Product {$product->id} has no stored price data to price from.");
            }
        }

        $wasLive = $product->tier === Product::TIER_LIVE;
        $product->forceFill([
            'tier' => Product::TIER_LIVE,
            'last_used_at' => now(),
            'range_changed_at' => $wasLive ? $product->range_changed_at : now(),
        ])->save();

        $this->reprice($product);
        if (! $wasLive) {
            AuditLog::record('range.added', $product, ['name' => $product->name]);
        }

        return $product->refresh();
    }

    public function remove(Product $product): Product
    {
        if ($product->tier !== Product::TIER_LIVE) {
            return $product;
        }

        $product->forceFill([
            'tier' => Product::TIER_ACTIVE,
            'last_used_at' => now(),
            'range_changed_at' => now(),
        ])->save();
        AuditLog::record('range.removed', $product, ['name' => $product->name]);

        return $product;
    }

    /** Fetches the latest supplier data now, then reprices. */
    public function refresh(Product $product): Product
    {
        if ($product->source !== 'promodata') {
            throw new RuntimeException('Only Promodata products can be refreshed from the supplier source.');
        }
        $tier = $product->tier;
        $fresh = $this->hydrator->hydrate($product->id)
            ?? throw new RuntimeException("Promodata no longer has product {$product->id}.");
        if ($tier === Product::TIER_LIVE && $fresh->tier !== Product::TIER_LIVE) {
            $fresh->forceFill(['tier' => Product::TIER_LIVE])->save();
        }
        $this->reprice($fresh);

        return $fresh->refresh();
    }

    /**
     * Rebuilds price options and web prices (incl. DecorationJSON). Errors are
     * stored on the product rather than thrown, so one bad product never stops
     * a batch. Returns the error, or null.
     */
    public function reprice(Product $product): ?string
    {
        if (! $product->keepsFullDetail() || $product->hydrated_at === null) {
            return null;
        }

        try {
            $before = $product->webPrice?->only(['decoration_json', 'collection_variant_price', 'actual_moq', 'collection_moq']);
            $this->builder->build($product);
            $after = $this->calculator->calculate($product)->only(['decoration_json', 'collection_variant_price', 'actual_moq', 'collection_moq']);

            $changes = ['reprice_error' => null];
            if ($product->tier === Product::TIER_LIVE && $before != $after) {
                $changes['range_changed_at'] = now();
            }
            $product->forceFill($changes)->save();
            $this->assignPricePattern($product);

            return null;
        } catch (Throwable $e) {
            $message = mb_substr($e->getMessage(), 0, 500);
            $product->forceFill(['reprice_error' => $message])->save();

            return $message;
        }
    }

    /** Price check grouping (docs/price-check.md). Never lets a failure stop pricing. */
    private function assignPricePattern(Product $product): void
    {
        try {
            app(PricePatterns::class)->assign($product);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
