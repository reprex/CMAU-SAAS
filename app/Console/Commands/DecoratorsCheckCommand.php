<?php

namespace App\Console\Commands;

use App\Models\PriceOption;
use App\Models\Product;
use App\Models\ProductWebPrice;
use Illuminate\Console\Command;

/**
 * Which decorations AS Colour, JB's Wear and Shiny products are priced with (Scott, 7 Nov 2026): they should
 * borrow the decorator products of the "Decorators" tab in EXT_RANGES_FOR_TOD_SAAS.XLSX (Melbourne Merch
 * 10000999 / 10001999 / 10002000 / 10002036, Shiny 10000053 / 10001053 / 10002037), not their own.
 */
class DecoratorsCheckCommand extends Command
{
    protected $signature = 'pricing:decorators-check {--suppliers=88,68,53}';

    protected $description = 'Show which decoration products AS Colour, JB\'s and Shiny products use, and those products\' prices';

    public function handle(): int
    {
        $ids = array_map('intval', explode(',', (string) $this->option('suppliers')));
        $live = Product::query()->whereIn('supplier_id', $ids)->where('tier', Product::TIER_LIVE)->where('discontinued', false)->get(['id', 'supplier_id', 'name']);
        $web = ProductWebPrice::query()->whereIn('product_id', $live->pluck('id'))->get()->keyBy('product_id');
        $rows = [];
        $used = [];
        foreach ($live->groupBy('supplier_id') as $sid => $products) {
            $by = $products->groupBy(fn ($p) => ($d = (int) ($web[$p->id]->decoration_product_id ?? $p->id)) === $p->id ? 'own' : (string) $d);
            foreach ($by as $deco => $ps) {
                $rows[] = [$sid, $products->count(), $deco === 'own' ? 'own decorations' : $deco.' '.(Product::query()->find((int) $deco)?->name ?? '(missing)'), $ps->count(), $ps->take(3)->pluck('id')->implode(', ')];
                if ($deco !== 'own') {
                    $used[(int) $deco] = true;
                }
            }
        }
        $this->table(['Supplier', 'Live', 'Decorations from', 'Products', 'e.g.'], $rows);

        foreach (array_keys($used) as $pid) {
            $this->line('');
            $this->info("Decoration product {$pid}");
            $opts = PriceOption::query()->where('product_id', $pid)->orderBy('position')->get();
            $this->table(['Kind', 'Description', 'Cost', 'Price', 'Setup cost'], $opts->map(fn ($o) => [$o->kind, $o->description, $o->cost_string, $o->price_string, $o->setup_cost])->all());
        }

        return self::SUCCESS;
    }
}
