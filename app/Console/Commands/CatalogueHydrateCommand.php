<?php

namespace App\Console\Commands;

use App\Catalogue\ProductHydrator;
use App\Catalogue\RangeManager;
use App\Models\Product;
use Illuminate\Console\Command;
use Throwable;

/**
 * Fetch products in full from Promodata without putting them on the range (tier "active"): colours,
 * images and every price line, priced by the SaaS. One product, or a whole supplier before it is added
 * (e.g. Legend Life, so its lines can be checked against the supplier's pages first). A supplier run
 * skips products already fetched in full, so it can be run again after an interruption.
 */
class CatalogueHydrateCommand extends Command
{
    protected $signature = 'catalogue:hydrate {product? : Promodata product id (APPAID)} {--supplier= : Every current product of this Promodata supplier} {--limit=2000 : At most this many products in one run}';

    protected $description = 'Fetch products in full from the supplier source and keep them in the active tier';

    public function handle(ProductHydrator $hydrator, RangeManager $range): int
    {
        if ($supplier = $this->option('supplier')) {
            $ids = Product::query()->where('supplier_id', (int) $supplier)->where('discontinued', false)->whereNull('hydrated_at')
                ->orderBy('id')->limit((int) $this->option('limit'))->pluck('id');
            $this->info("Fetching {$ids->count()} products in full…");
            $done = 0;
            $failed = 0;
            foreach ($ids as $id) {
                try {
                    if ($p = $hydrator->hydrate((int) $id)) {
                        $range->reprice($p);
                    }
                    $done++;
                } catch (Throwable $e) {
                    $failed++;
                    $this->warn("{$id}: ".mb_substr($e->getMessage(), 0, 160));
                }
                if (($done + $failed) % 50 === 0) {
                    $this->line(($done + $failed).' of '.$ids->count().'…');
                }
            }
            $left = Product::query()->where('supplier_id', (int) $supplier)->where('discontinued', false)->whereNull('hydrated_at')->count();
            $this->info("Fetched {$done} in full, {$failed} failed. {$left} still summary only".($left ? ': run it again.' : '.'));

            return self::SUCCESS;
        }

        $product = $hydrator->hydrate((int) $this->argument('product'));

        if ($product === null) {
            $this->error('The supplier source has no product with that id.');

            return self::FAILURE;
        }

        $this->info("Product {$product->id} ({$product->name}) is now {$product->tier}.");

        return self::SUCCESS;
    }
}
