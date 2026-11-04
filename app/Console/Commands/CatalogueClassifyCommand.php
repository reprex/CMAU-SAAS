<?php

namespace App\Console\Commands;

use App\Jobs\RepriceProduct;
use App\Models\AuditLog;
use App\Models\DecTypeCode;
use App\Models\PriceOption;
use App\Models\Product;
use App\Models\Supplier;
use App\Pricing\AutoClassifier;
use Illuminate\Console\Command;

/**
 * Fill in decoration types (10/11/12/23) from the supplier rules and pages (AutoClassifier), so they
 * are never assigned by hand. Nightly with --apply; without it, shows what it would do.
 */
class CatalogueClassifyCommand extends Command
{
    protected $signature = 'catalogue:classify
        {--supplier=* : Only these suppliers (default: every supplier whose pages we have read)}
        {--apply : Save the types and reprice the products that use them}
        {--show=25 : List at most this many descriptions per supplier that are still to classify}';

    protected $description = 'Set decoration types from the supplier rules and pages; list what disagrees and what is left';

    public function handle(AutoClassifier $classifier): int
    {
        $suppliers = array_map('intval', $this->option('supplier') ?: $classifier->suppliers());
        $names = Supplier::query()->whereIn('id', $suppliers)->pluck('name', 'id');
        $sorted = $classifier->sort($classifier->plan($suppliers));
        $types = [10 => 'decoration', 11 => 'option', 12 => 'undecorated', 23 => 'hidden'];

        $byType = collect($sorted['fill'])->countBy('type')->sortKeys()->map(fn ($n, $t) => "{$n} {$types[$t]}")->implode(', ');
        $this->info(count($sorted['fill']).' descriptions to fill in'.($byType ? " ({$byType})" : '').'.');

        $repriced = 0;
        if ($this->option('apply') && $sorted['fill'] !== []) {
            foreach ($sorted['fill'] as $code => $f) {
                $row = DecTypeCode::query()->whereKey($code)->whereNull('type_code')->first();
                if (! $row) {
                    continue;
                }
                $row->update(['type_code' => $f['type']]);
                AuditLog::record('decoration_type.classified', $row, ['before' => ['type_code' => null], 'after' => ['type_code' => $f['type']], 'why' => $f['why']]);
            }
            $ids = PriceOption::query()->whereIn('code', array_keys($sorted['fill']))
                ->whereIn('product_id', Product::query()->fullDetail()->select('id'))->distinct()->pluck('product_id');
            $ids->each(fn ($id) => RepriceProduct::dispatch((int) $id));
            $repriced = $ids->count();
            $this->info("Saved. {$repriced} products using them are being repriced (the minute cron works through them).");
        } elseif ($sorted['fill'] !== []) {
            foreach (array_slice($sorted['fill'], 0, 15, true) as $code => $f) {
                $this->line("  {$types[$f['type']]}: {$f['description']}  ({$f['why']})");
            }
            $this->line('Run again with --apply to save them.');
        }

        if ($sorted['disagree'] !== []) {
            $this->newLine();
            $this->warn(count($sorted['disagree']).' descriptions where the type already set disagrees with the supplier (left as they are; change them in Decoration types if the supplier is right):');
            foreach ($sorted['disagree'] as $d) {
                $this->line("  {$d['description']}: set {$d['current']} ({$types[$d['current']]}), supplier says {$d['derived']} ({$types[$d['derived']]}) · {$d['why']} · ".($names[$d['supplier_id']] ?? $d['supplier_id']).", {$d['on_range']} on the range");
            }
        }
        if ($sorted['mixed'] !== []) {
            $this->newLine();
            $this->warn(count($sorted['mixed']).' descriptions two suppliers would type differently (left for staff): '.implode(', ', array_slice($sorted['mixed'], 0, 20)));
        }

        $this->newLine();
        $left = collect($sorted['left']);
        $this->info($left->pluck('code')->unique()->count().' descriptions still to classify'.($left->isEmpty() ? '.' : ', by supplier (most used on the range first):'));
        foreach ($left->groupBy('supplier_id') as $sid => $rows) {
            $rows = $rows->sortByDesc('on_range')->values();
            $this->line(($names[$sid] ?? "Supplier {$sid}").': '.$rows->count());
            foreach ($rows->take((int) $this->option('show')) as $r) {
                $this->line("  {$r['description']}  ({$r['on_range']} on the range, {$r['products']} in all)");
            }
        }

        return self::SUCCESS;
    }
}
