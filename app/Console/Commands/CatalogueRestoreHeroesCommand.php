<?php

namespace App\Console\Commands;

use App\Catalogue\ImageTagger;
use App\Models\Product;
use Illuminate\Console\Command;
use Throwable;

/**
 * Undo the white-background hero (Scott, 3 Nov 2026; docs/images.md): re-tag the products that had a
 * style photo on white, so Promodata's hero (or the hero staff picked) is the hero again, and mark the
 * ones whose hero changes back to publish again. Pictures already on Shopify are reordered, not re-uploaded.
 */
class CatalogueRestoreHeroesCommand extends Command
{
    protected $signature = 'catalogue:restore-heroes {--dry-run : Only count what would change}';

    protected $description = "Put Promodata's hero back on products the white-background step changed";

    public function handle(ImageTagger $tagger): int
    {
        $checked = $changed = 0;
        Product::query()->whereHas('images', fn ($q) => $q->where('white_bg', true))
            ->with(['images', 'colours', 'override'])->orderBy('id')
            ->chunkById(200, function ($products) use ($tagger, &$checked, &$changed) {
                foreach ($products as $product) {
                    $checked++;
                    try {
                        $before = $product->images->firstWhere('role', ImageTagger::HERO_STYLE)?->url;
                        $after = $tagger->hero($product);
                        if ($before === $after) {
                            continue;
                        }
                        $changed++;
                        if ($this->option('dry-run')) {
                            continue;
                        }
                        $tagger->apply($product);
                        if ($product->tier === Product::TIER_LIVE) {
                            $product->forceFill(['range_changed_at' => now()])->save(); // republish with the hero restored
                        }
                    } catch (Throwable $e) {
                        $this->error("Product {$product->id}: {$e->getMessage()}");
                    }
                }
            });
        $this->info(($this->option('dry-run') ? 'Would restore' : 'Restored')." the hero on {$changed} of {$checked} products that had a white-background photo."
            .($changed && ! $this->option('dry-run') ? ' Range products are marked to publish again.' : ''));

        return self::SUCCESS;
    }
}
