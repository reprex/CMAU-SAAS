<?php

namespace App\Catalogue;

use App\Models\Product;

/**
 * Keeps hosted data compact: active products unused for the configured
 * window drop back to the summary tier and lose their detail rows
 * (staff overrides are kept). Live products are never demoted here.
 */
class TierManager
{
    public function __construct(private readonly CatalogueWriter $writer) {}

    /** @return int number of products demoted */
    public function demoteStale(): int
    {
        $cutoff = now()->subMonths(config('catalogue.active_window_months'));
        $count = 0;

        Product::query()
            ->where('tier', Product::TIER_ACTIVE)
            ->where(fn ($q) => $q->whereNull('last_used_at')->orWhere('last_used_at', '<', $cutoff))
            ->orderBy('id')
            ->chunkById(200, function ($products) use (&$count) {
                foreach ($products as $product) {
                    $this->writer->dropDetail($product);
                    $product->forceFill(['tier' => Product::TIER_SUMMARY])->save();
                    $count++;
                }
            });

        return $count;
    }
}
