<?php

namespace App\Console\Commands;

use App\Catalogue\LogoAreaLearner;
use App\Catalogue\PrintAreas;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Logo areas for the whole web range (docs/logo-preview.md):
 *   --predict  give every live product a prediction (type default until Claude's suggestion arrives)
 *   --sample=N pick N products for staff to vet
 *   (always)   learn from the vetted samples and apply the corrections to the rest
 * Scheduled every 10 minutes; it only re-learns when something was approved.
 * Optional since 1 Nov 2026: positions and logo areas come automatically from
 * templates and Promodata decoration print areas (PositionResolver), so the
 * --sample step is never scheduled; staff run it from Range › Logo areas if wanted.
 */
class CatalogueLogoAreasCommand extends Command
{
    protected $signature = 'catalogue:logo-areas {--predict} {--sample=} {--force : Re-learn even if nothing changed}';

    protected $description = 'Predict, sample and learn logo areas for the web range';

    public function handle(PrintAreas $areas, LogoAreaLearner $learner): int
    {
        if ($this->option('predict')) {
            $n = 0;
            Product::query()->where('tier', Product::TIER_LIVE)->whereNotNull('hero_image_url')->with(['images', 'colours', 'override'])
                ->chunkById(200, function ($products) use ($areas, &$n) {
                    foreach ($products as $p) {
                        $n += $areas->ensure($p) ? 1 : 0;
                    }
                });
            $this->info("Predictions in place for {$n} products.");
            Cache::put('logo-areas:dirty', true);
        }
        if ($size = (int) $this->option('sample')) {
            $this->info('Added '.$learner->pickSample($size).' products to the sample to vet.');
        }
        if ($this->option('force') || Cache::pull('logo-areas:dirty')) {
            $r = $learner->learn();
            $this->info("Applied learned areas to {$r['applied']} products.");
        }

        return self::SUCCESS;
    }
}
