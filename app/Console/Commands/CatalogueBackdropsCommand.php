<?php

namespace App\Console\Commands;

use App\Catalogue\Backdrop;
use App\Catalogue\ImageTagger;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * White-background check (docs/images.md): each scheduler minute it looks at the
 * style photos of a batch of web-range products, then re-tags any that changed, so a
 * white-background style photo becomes the hero. Republish to send the new order to Shopify.
 */
class CatalogueBackdropsCommand extends Command
{
    protected $signature = 'catalogue:backdrops {--product= : One product (any tier)} {--seconds=40 : Time budget for a run} {--fresh : With --product, look at every photo again}';

    protected $description = 'Find style photos on a white background (they become the hero)';

    public function handle(Backdrop $backdrop, ImageTagger $tagger): int
    {
        if (! $backdrop->enabled()) {
            $this->line('PHP GD is not installed, so photos cannot be checked.');

            return self::SUCCESS;
        }
        if ($id = $this->option('product')) {
            $product = Product::query()->findOrFail((int) $id);
            $n = $backdrop->mark($product, 40, (bool) $this->option('fresh'));
            $tagger->apply($product);
            $white = $product->images()->where('white_bg', true)->count();
            $this->info("Looked at {$n} photos; {$white} on white. Hero: ".($tagger->hero($product->fresh(['images', 'colours', 'override'])) ?? 'none'));

            return self::SUCCESS;
        }

        $cursor = (int) Cache::get('backdrops:cursor', 0);
        $started = microtime(true);
        $products = Product::query()->where('tier', Product::TIER_LIVE)->where('id', '>', $cursor)
            ->whereHas('images', fn ($q) => $q->whereNull('white_bg'))->orderBy('id')->limit(100)->get();
        if ($products->isEmpty()) {
            Cache::forget('backdrops:cursor');

            return self::SUCCESS;
        }
        $done = 0;
        foreach ($products as $product) {
            if (microtime(true) - $started > (int) $this->option('seconds')) {
                break;
            }
            $cursor = $product->id;
            try {
                $before = $tagger->hero($product->loadMissing(['images', 'colours', 'override']));
                if ($backdrop->mark($product) > 0) {
                    $tagger->apply($product);
                    if ($tagger->hero($product->fresh(['images', 'colours', 'override'])) !== $before) {
                        $product->forceFill(['range_changed_at' => now()])->save(); // republish with the new hero
                    }
                }
                $done++;
            } catch (Throwable $e) {
                $this->error("Product {$product->id}: {$e->getMessage()}");
            }
        }
        Cache::forever('backdrops:cursor', $cursor);
        $this->line("Checked {$done} products.");

        return self::SUCCESS;
    }
}
