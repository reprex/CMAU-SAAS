<?php

namespace App\Console\Commands;

use App\Catalogue\ProductOptions;
use App\Jobs\RepriceProduct;
use App\Models\AuditLog;
use App\Models\PriceOption;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierDecType;
use App\Pricing\AutoClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Each supplier's decoration types (10/11/12/23) from its own pages and rules (AutoClassifier), saved per
 * supplier so they are never assigned by hand and one supplier never changes another's. Nightly with
 * --apply; without it, shows what it would do.
 */
class CatalogueClassifyCommand extends Command
{
    protected $signature = 'catalogue:classify
        {--supplier=* : Only these suppliers (default: every supplier whose pages we have read)}
        {--apply : Save the types for each supplier and reprice the products whose type changes}
        {--show=10 : List at most this many changes and this many untyped descriptions per supplier}';

    protected $description = 'Set each supplier\'s decoration types from its own pages and rules; list what changes and what is left';

    public function handle(AutoClassifier $classifier): int
    {
        $suppliers = array_map('intval', $this->option('supplier') ?: $classifier->suppliers());
        $names = Supplier::query()->whereIn('id', $suppliers)->pluck('name', 'id');
        $sorted = $classifier->sort($classifier->plan($suppliers));
        $types = [10 => 'decoration', 11 => 'option', 12 => 'undecorated', 23 => 'hidden'];
        $show = (int) $this->option('show');

        $set = collect($sorted['set']);
        $changes = collect($sorted['changes']);
        $this->info(number_format($set->count()).' descriptions typed from the suppliers\' own pages and rules ('
            .$set->countBy('derived')->sortKeys()->map(fn ($n, $t) => "{$n} {$types[$t]}")->implode(', ').'), saved per supplier.');
        $this->info(number_format($changes->count()).' of them change what pricing uses now (from a type TOD set, or none), by supplier and reason:');
        foreach ($changes->groupBy(fn ($c) => ($names[$c['supplier_id']] ?? $c['supplier_id']).' · '.($c['current'] === null ? 'none' : $c['current']).' → '.$c['derived'].' · '.preg_replace('/^supplier pages: .*option$/', 'supplier pages: option', (string) $c['why']))
            ->sortByDesc(fn ($g) => $g->count()) as $label => $g) {
            $this->line(sprintf('  %4d  %s  (e.g. %s)', $g->count(), $label, $g->sortByDesc('on_range')->first()['description']));
        }
        foreach ($changes->sortByDesc('on_range')->take($show) as $c) {
            $this->line("    {$c['description']}: ".($c['current'] === null ? 'none' : $types[$c['current']])." → {$types[$c['derived']]} · {$c['why']} · ".($names[$c['supplier_id']] ?? '').", {$c['on_range']} on the range");
        }

        if ($this->option('apply')) {
            $now = now();
            foreach ($set->chunk(500) as $chunk) {
                SupplierDecType::query()->upsert($chunk->map(fn ($r) => ['supplier_id' => $r['supplier_id'], 'code' => $r['code'], 'type_code' => $r['derived'],
                    'why' => mb_substr((string) $r['why'], 0, 160), 'staff' => false, 'created_at' => $now, 'updated_at' => $now])->values()->all(),
                    ['supplier_id', 'code'], ['type_code', 'why', 'updated_at']);
            }
            $ids = collect();
            foreach ($changes->groupBy('supplier_id') as $sid => $rows) {
                $ids = $ids->merge(PriceOption::query()->whereIn('code', $rows->pluck('code')->all())
                    ->whereIn('product_id', Product::query()->fullDetail()->where('supplier_id', (int) $sid)->select('id'))->distinct()->pluck('product_id'));
            }
            $ids->unique()->each(fn ($id) => RepriceProduct::dispatch((int) $id));
            AuditLog::record('decoration_type.classified', null, ['suppliers' => $suppliers, 'typed' => $set->count(), 'changed' => $changes->count(), 'repriced' => $ids->unique()->count()]);
            $this->info("Saved. {$ids->unique()->count()} products whose type changed are being repriced (the minute cron works through them).");
        } elseif ($changes->isNotEmpty()) {
            $this->line('Run again with --apply to save them.');
        }

        // For the Vet screen (docs/vet.md): what is left, plus option lines no rule covers.
        Cache::forever('vet:classify', ['at' => now()->toIso8601String(), 'left' => collect($sorted['left'])->sortByDesc('on_range')->take(300)->values()->all()]);
        Cache::forever('vet:options', ['at' => now()->toIso8601String(), 'lines' => $this->unmatchedOptions($suppliers)]);

        $this->newLine();
        $left = collect($sorted['left']);
        $this->info($left->count().' descriptions no reading covers yet'.($left->isEmpty() ? '.' : ', by supplier (most used on the range first):'));
        foreach ($left->groupBy('supplier_id') as $sid => $rows) {
            $rows = $rows->sortByDesc('on_range')->values();
            $this->line(($names[$sid] ?? "Supplier {$sid}").': '.$rows->count());
            foreach ($rows->take($show) as $r) {
                $this->line("  {$r['description']}  ({$r['on_range']} on the range, {$r['products']} in all)");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Add-on lines on the range that no supplier-page rule groups (shown as Promodata words them).
     *
     * @param  list<int>  $suppliers
     * @return list<array{supplier_id: int, description: string, products: int}>
     */
    private function unmatchedOptions(array $suppliers): array
    {
        $count = [];
        $options = app(ProductOptions::class);
        Product::query()->with('priceOptions')->where('tier', Product::TIER_LIVE)->where('discontinued', false)->whereIn('supplier_id', $suppliers)
            ->chunkById(200, function ($chunk) use ($options, &$count) {
                foreach ($chunk as $p) {
                    foreach (array_unique($options->unmatched($p)) as $d) {
                        $k = $p->supplier_id.'|'.$d;
                        $count[$k] = ($count[$k] ?? 0) + 1;
                    }
                }
            });
        arsort($count);
        $out = [];
        foreach (array_slice($count, 0, 300, true) as $k => $n) {
            [$sid, $d] = explode('|', $k, 2);
            $out[] = ['supplier_id' => (int) $sid, 'description' => $d, 'products' => $n];
        }

        return $out;
    }
}
