<?php

namespace App\Console\Commands;

use App\Catalogue\ProductOptions;
use App\Catalogue\RangeManager;
use App\Models\Product;
use Illuminate\Console\Command;

/**
 * Product options coverage (docs/product-options.md): per supplier with an options template, how many
 * range products get options, the groups they get, and the lines no rule covers (to add to the template).
 */
class CatalogueOptionsReportCommand extends Command
{
    protected $signature = 'catalogue:options-report {--supplier= : Promodata supplier id} {--product= : One product: print its custom.options} {--mark : Mark range products that get options to publish again} {--reprice : Reprice them first (when a template claims branding lines, e.g. Logo Line packaging)}';

    protected $description = 'Report which range products get product options, and lines no template rule covers';

    public function handle(ProductOptions $options): int
    {
        if ($id = $this->option('product')) {
            $p = Product::query()->with('priceOptions')->findOrFail((int) $id);
            $this->line(json_encode($options->forProduct($p), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'null');
            foreach ($options->unmatched($p) as $u) {
                $this->warn('Not covered: '.$u);
            }

            return self::SUCCESS;
        }
        $n = $with = 0;
        $groups = $unmatched = [];
        Product::query()->with('priceOptions')->where('tier', Product::TIER_LIVE)->where('discontinued', false)
            ->when($this->option('supplier'), fn ($q, $s) => $q->where('supplier_id', (int) $s))
            ->orderBy('id')->chunkById(200, function ($products) use ($options, &$n, &$with, &$groups, &$unmatched) {
                foreach ($products as $p) {
                    if (! $options->templateFor((int) $p->supplier_id)) {
                        continue;
                    }
                    $n++;
                    if ($this->option('reprice') && $options->claimed((int) $p->supplier_id, $p->priceOptions) !== []) {
                        app(RangeManager::class)->reprice($p); // DecorationJSON without the claimed packaging
                    }
                    $o = $options->forProduct($p);
                    if ($o) {
                        $with++;
                        if ($this->option('mark')) {
                            $p->forceFill(['range_changed_at' => now()])->save();
                        }
                        foreach ($o['groups'] as $g) {
                            $groups[$g['key']] = ($groups[$g['key']] ?? 0) + 1;
                        }
                    }
                    foreach ($options->unmatched($p) as $u) {
                        $k = preg_replace('/\d[\d,.]*/', '#', $u);
                        $unmatched[$k] = ($unmatched[$k] ?? 0) + 1;
                    }
                }
            });
        $this->info("{$with} of {$n} range products get options.".($this->option('mark') && $with ? " {$with} marked to publish again." : ''));
        arsort($groups);
        $this->table(['Group', 'Products'], collect($groups)->map(fn ($c, $g) => [$g, $c])->values()->all());
        arsort($unmatched);
        if ($unmatched) {
            $this->warn('Lines no rule covers (not branding, hidden or unbranded):');
            $this->table(['Line', 'Products'], collect($unmatched)->take(60)->map(fn ($c, $l) => [$l, $c])->values()->all());
        }

        return self::SUCCESS;
    }
}
