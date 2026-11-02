<?php

namespace App\Console\Commands;

use App\Catalogue\PositionResolver;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * How each live product's branding positions were worked out, so staff curate
 * only the ones that need it: a staff-placed area, the supplier's template, the
 * Promodata decoration data, the garment extras, or the product-type default
 * (the guess). Read-only.
 */
class PositionsReportCommand extends Command
{
    protected $signature = 'catalogue:positions-report {--types=25 : How many product types to list for the type-default guesses}';

    protected $description = 'Report where the live range\'s branding positions come from (read-only)';

    public function handle(PositionResolver $resolver): int
    {
        $bySource = [];
        $guessTypes = [];
        $positions = 0;
        $n = 0;
        Product::query()->where('tier', Product::TIER_LIVE)->orderBy('id')->chunkById(200, function ($chunk) use ($resolver, &$bySource, &$guessTypes, &$positions, &$n) {
            foreach ($chunk as $p) {
                $list = $resolver->forShopify($p);
                $n++;
                $positions += count($list);
                $src = $list === [] ? 'none (not brandable)' : (string) ($list[0]['source'] ?? 'staff');
                $bySource[$src] = ($bySource[$src] ?? 0) + 1;
                if ($src === 'default') {
                    $t = $p->promodata_type_name ?: '(no type)';
                    $guessTypes[$t] = ($guessTypes[$t] ?? 0) + 1;
                }
            }
        });

        arsort($bySource);
        arsort($guessTypes);
        // For Range › Positions (plain arrays only: the cache refuses objects).
        Cache::forever('positions:report', ['at' => now()->toIso8601String(), 'products' => $n, 'sources' => $bySource, 'guess_types' => $guessTypes]);
        $this->info("{$n} live products, {$positions} positions");
        $this->table(['Positions from', 'Products'], collect($bySource)->map(fn ($c, $s) => [$s, $c])->values()->all());
        arsort($guessTypes);
        $this->line('Product types whose position is the type-default guess (curate these first):');
        $this->table(['Product type', 'Products'], collect($guessTypes)->take((int) $this->option('types'))->map(fn ($c, $t) => [$t, $c])->values()->all());

        return self::SUCCESS;
    }
}
