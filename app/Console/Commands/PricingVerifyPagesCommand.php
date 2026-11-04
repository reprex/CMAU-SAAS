<?php

namespace App\Console\Commands;

use App\Models\PriceCheck;
use App\Models\PriceLine;
use App\Models\Product;
use App\Pricing\PricePatterns;
use Illuminate\Console\Command;

/**
 * Price check from the suppliers' own pages instead of screengrabs (Scott, 5 Nov 2026; docs/price-check.md):
 * the blank price a supplier's product page shows is compared with Promodata's base lines. The base that
 * matches the page's blank price is the blank price (base_unbranded); a branded base priced above it is
 * base_branded; no match is "differs". Recorded as checks with the page as evidence, so the patterns verify
 * themselves. Safe to run again: a product's earlier page checks are replaced.
 */
class PricingVerifyPagesCommand extends Command
{
    /** Supplier id => data file under database/data/supplier-pages (code => [first qty, blank price]). */
    public const PAGES = [19 => 'dex-blank.json', 57 => 'legendlife-blank.json'];

    protected $signature = 'pricing:verify-pages {--supplier= : One supplier}';

    protected $description = 'Record price checks from the blank prices on the suppliers\' own product pages';

    public function handle(PricePatterns $patterns): int
    {
        foreach (self::PAGES as $supplierId => $file) {
            if ($this->option('supplier') && (int) $this->option('supplier') !== $supplierId) {
                continue;
            }
            $data = json_decode((string) @file_get_contents(database_path('data/supplier-pages/'.$file)), true);
            $pages = $data['products'] ?? [];
            $evidence = 'Supplier page '.($data['read'] ?? '');
            $n = ['base_unbranded' => 0, 'base_branded' => 0, 'differs' => 0, 'no page' => 0];
            Product::query()->where('supplier_id', $supplierId)->whereIn('tier', [Product::TIER_LIVE, Product::TIER_ACTIVE])->whereNotNull('hydrated_at')
                ->orderBy('id')->chunkById(200, function ($chunk) use ($pages, $evidence, $patterns, &$n) {
                    foreach ($chunk as $product) {
                        $page = $pages[(string) $product->code] ?? null;
                        if (! $page) {
                            $n['no page']++;

                            continue;
                        }
                        [$verdict, $notes] = $this->verdict($product, (int) $page[0], (float) $page[1]);
                        if ($verdict === null) {
                            continue;
                        }
                        if ($product->price_pattern === null) {
                            $patterns->assign($product);
                            $product->refresh();
                        }
                        PriceCheck::query()->where('product_id', $product->id)->where('evidence', 'like', 'Supplier page%')->delete();
                        PriceCheck::query()->create(['product_id' => $product->id, 'price_pattern' => $product->price_pattern, 'verdict' => $verdict,
                            'notes' => $notes, 'evidence' => $evidence, 'prices_changed_at' => $product->prices_changed_at ?? null]);
                        $n[$verdict]++;
                    }
                });
            $this->info("Supplier {$supplierId}: ".collect($n)->map(fn ($c, $k) => "{$c} {$k}")->implode(', ').'.');
        }

        return self::SUCCESS;
    }

    /** @return array{0: ?string, 1: string} */
    public function verdict(Product $product, int $qty, float $blank): array
    {
        $bases = $product->priceLines()->with('breaks')->whereNull('base_price_line_id')->get()->filter(fn (PriceLine $l) => $l->breaks->isNotEmpty());
        if ($bases->isEmpty() || $blank <= 0) {
            return [null, ''];
        }
        $at = function (PriceLine $l) use ($qty): float {
            $b = $l->breaks->sortBy('qty')->filter(fn ($b) => (int) $b->qty <= $qty)->last() ?? $l->breaks->sortBy('qty')->first();

            return (float) $b->unit_cost;
        };
        $page = '$'.number_format($blank, 2).' at '.$qty;
        $match = $bases->first(fn (PriceLine $l) => abs($at($l) - $blank) <= 0.011);
        if ($match) {
            return ['base_unbranded', "Blank price on the supplier's page {$page} = Promodata \"{$match->description}\"."];
        }
        $branded = $bases->first(fn (PriceLine $l) => ! $l->undecorated && $at($l) > $blank + 0.011);
        if ($branded && ! $bases->contains(fn (PriceLine $l) => $l->undecorated)) {
            return ['base_branded', "Supplier's blank price {$page}; Promodata's only base \"{$branded->description}\" is \$".number_format($at($branded), 2).', so it includes branding.'];
        }

        return ['differs', "Supplier's blank price {$page}; Promodata's base lines: ".$bases->map(fn ($l) => '"'.$l->description.'" $'.number_format($at($l), 2))->implode(', ').'.'];
    }
}
