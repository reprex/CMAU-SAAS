<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Pricing\PriceOptionBuilder;
use App\Pricing\WebPriceCalculator;
use Illuminate\Console\Command;
use Throwable;

class PricingRecalculateCommand extends Command
{
    protected $signature = 'pricing:recalculate
        {product? : One product id (APPAID)}
        {--supplier= : All full-detail products from one supplier}';

    protected $description = 'Rebuild price options and web prices (incl. DecorationJSON) for live/active products';

    public function handle(PriceOptionBuilder $builder, WebPriceCalculator $calculator): int
    {
        $query = Product::query()->fullDetail()->whereNotNull('hydrated_at');
        if ($this->argument('product') !== null) {
            $query->whereKey((int) $this->argument('product'));
        }
        if ($this->option('supplier') !== null) {
            $query->where('supplier_id', (int) $this->option('supplier'));
        }

        $done = 0;
        $failed = 0;
        $query->orderBy('id')->chunkById(100, function ($products) use ($builder, $calculator, &$done, &$failed) {
            foreach ($products as $product) {
                try {
                    $builder->build($product);
                    $calculator->calculate($product);
                    $done++;
                } catch (Throwable $e) {
                    $failed++;
                    $this->error("Product {$product->id}: {$e->getMessage()}");
                }
            }
        });

        $this->info("Recalculated {$done} products".($failed ? ", {$failed} failed." : '.'));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
