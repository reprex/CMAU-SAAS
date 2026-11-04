<?php

namespace App\Console\Commands;

use App\Catalogue\GarmentSizes;
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
        $mock = ['mockup' => 0, 'garment' => 0, 'guide' => 0, 'guide_by_supplier' => [], 'garment_no_chart' => []];
        Product::query()->where('tier', Product::TIER_LIVE)->orderBy('id')->chunkById(200, function ($chunk) use ($resolver, &$bySource, &$guessTypes, &$positions, &$n, &$mock) {
            foreach ($chunk as $p) {
                $list = $resolver->forShopify($p);
                // Mockup or guide card (the theme's rule, Scott 4 Nov 2026): a template or staff position with a box,
                // or a garment with the supplier's size chart.
                if ($list !== []) {
                    $trusted = collect($list)->contains(fn ($x) => in_array($x['source'] ?? null, ['template', 'staff'], true) && isset($x['width']));
                    $garment = (bool) preg_match(PositionResolver::GARMENT, $p->promodata_type_name.' '.$p->name);
                    $chart = $garment && rescue(fn () => GarmentSizes::metafield($p), null, false);
                    $kind = $trusted ? 'mockup' : ($chart ? 'garment' : 'guide');
                    $mock[$kind]++;
                    if ($kind === 'guide') {
                        $mock['guide_by_supplier'][$p->supplier_id] = ($mock['guide_by_supplier'][$p->supplier_id] ?? 0) + 1;
                        if ($garment && count($mock['garment_no_chart']) < 100) {
                            $mock['garment_no_chart'][] = $p->id;
                        }
                    }
                }
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
        Cache::forever('positions:report', ['at' => now()->toIso8601String(), 'products' => $n, 'sources' => $bySource, 'guess_types' => $guessTypes, 'mockups' => $mock]);
        $this->info("{$n} live products, {$positions} positions");
        $this->table(['Positions from', 'Products'], collect($bySource)->map(fn ($c, $s) => [$s, $c])->values()->all());
        arsort($guessTypes);
        $this->line('Product types whose position is the type-default guess (curate these first):');
        $this->table(['Product type', 'Products'], collect($guessTypes)->take((int) $this->option('types'))->map(fn ($c, $t) => [$t, $c])->values()->all());

        return self::SUCCESS;
    }
}
