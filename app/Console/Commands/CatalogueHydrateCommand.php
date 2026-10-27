<?php

namespace App\Console\Commands;

use App\Catalogue\ProductHydrator;
use Illuminate\Console\Command;

class CatalogueHydrateCommand extends Command
{
    protected $signature = 'catalogue:hydrate {product : Promodata product id (APPAID)}';

    protected $description = 'Fetch one product in full from the supplier source and keep it in the active tier';

    public function handle(ProductHydrator $hydrator): int
    {
        $product = $hydrator->hydrate((int) $this->argument('product'));

        if ($product === null) {
            $this->error('The supplier source has no product with that id.');

            return self::FAILURE;
        }

        $this->info("Product {$product->id} ({$product->name}) is now {$product->tier}.");

        return self::SUCCESS;
    }
}
