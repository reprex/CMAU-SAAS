<?php

namespace App\Console\Commands;

use App\Catalogue\ImageTagger;
use App\Catalogue\ImageVision;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Claude image check (docs/images.md). Each scheduler minute it works through
 * the web range a few products at a time, looking only at images it hasn't
 * seen, then re-tags those products. Stops for the day at images_per_day.
 */
class CatalogueCheckImagesCommand extends Command
{
    protected $signature = 'catalogue:check-images {--product= : One product (any tier)} {--products= : Products to check this run}';

    protected $description = 'Have Claude look at unsure product images';

    public function handle(ImageVision $vision, ImageTagger $tagger): int
    {
        if (! $vision->enabled()) {
            $this->line('Image check is off (IMAGE_CHECK and ANTHROPIC_API_KEY).');

            return self::SUCCESS;
        }
        if ($id = $this->option('product')) {
            $product = Product::query()->findOrFail((int) $id);
            $n = $vision->check($product);
            $tagger->apply($product);
            $this->info("Checked {$n} images.");

            return self::SUCCESS;
        }

        $want = (int) ($this->option('products') ?: config('images.check.products_per_run'));
        $cursor = (int) Cache::get('image-check:cursor', 0);
        $started = microtime(true);
        $checkedProducts = $images = $scanned = 0;
        $products = Product::query()->where('tier', Product::TIER_LIVE)->whereNotNull('hydrated_at')
            ->where('id', '>', $cursor)->orderBy('id')->limit(200)->get();
        if ($products->isEmpty()) {
            Cache::forget('image-check:cursor'); // round again from the start next run

            return self::SUCCESS;
        }
        foreach ($products as $product) {
            if ($checkedProducts >= $want || microtime(true) - $started > 40) {
                break;
            }
            $scanned++;
            $cursor = $product->id;
            try {
                $n = $vision->check($product);
            } catch (Throwable $e) {
                $this->error("Product {$product->id}: {$e->getMessage()}");

                continue;
            }
            if ($n > 0) {
                $tagger->apply($product);
                $checkedProducts++;
                $images += $n;
            }
        }
        Cache::forever('image-check:cursor', $cursor);
        $this->info("Checked {$images} images on {$checkedProducts} products ({$scanned} looked at).");

        return self::SUCCESS;
    }
}
