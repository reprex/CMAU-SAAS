<?php

namespace App\Console\Commands;

use App\Catalogue\ImageTagger;
use App\Models\Product;
use Illuminate\Console\Command;
use Throwable;

/**
 * Tag every full-detail product's images as hero style, other style, colour or
 * other colour (docs/images.md). Run once after deploying image tags, and
 * whenever the automatic rules change. Promodata refreshes tag as they go.
 */
class CatalogueTagImagesCommand extends Command
{
    protected $signature = 'catalogue:tag-images {--supplier= : One supplier only} {--product= : One product only}';

    protected $description = 'Tag product images (hero style, other style, colour, other colour)';

    public function handle(ImageTagger $tagger): int
    {
        $done = 0;
        Product::query()->whereNotNull('hydrated_at')
            ->when($this->option('supplier') !== null, fn ($q) => $q->where('supplier_id', (int) $this->option('supplier')))
            ->when($this->option('product') !== null, fn ($q) => $q->whereKey((int) $this->option('product')))
            ->orderBy('id')->chunkById(100, function ($products) use ($tagger, &$done) {
                foreach ($products as $product) {
                    try {
                        $tagger->apply($product);
                        $done++;
                    } catch (Throwable $e) {
                        $this->error("Product {$product->id}: {$e->getMessage()}");
                    }
                }
            });
        $this->info("Images tagged for {$done} products.");

        return self::SUCCESS;
    }
}
