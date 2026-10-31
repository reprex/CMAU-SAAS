<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Pricing\PricePatterns;
use Illuminate\Console\Command;
use Throwable;

/**
 * Price check (docs/price-check.md). Nightly: recount patterns, pick
 * screengrab examples and mark ratio outliers. With --assign, first works out
 * every full-detail product's pattern (after the TOD import, or when the
 * grouping rules change).
 */
class PricingPatternsCommand extends Command
{
    protected $signature = 'pricing:patterns
        {--assign : Work out every live/active product\'s pattern first}
        {--supplier= : With --assign, one supplier only}';

    protected $description = 'Group products into price patterns for the Price check screen';

    public function handle(PricePatterns $patterns): int
    {
        if ($this->option('assign')) {
            $done = 0;
            Product::query()->fullDetail()->whereNotNull('hydrated_at')
                ->when($this->option('supplier') !== null, fn ($q) => $q->where('supplier_id', (int) $this->option('supplier')))
                ->orderBy('id')->chunkById(100, function ($products) use ($patterns, &$done) {
                    foreach ($products as $product) {
                        try {
                            $patterns->assign($product);
                            $done++;
                        } catch (Throwable $e) {
                            $this->error("Product {$product->id}: {$e->getMessage()}");
                        }
                    }
                });
            $this->info("Patterns worked out for {$done} products.");
        }

        $this->info($patterns->refresh().' price patterns.');

        return self::SUCCESS;
    }
}
