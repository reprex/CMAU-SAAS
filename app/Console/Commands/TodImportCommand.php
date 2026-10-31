<?php

namespace App\Console\Commands;

use App\Catalogue\RangeManager;
use App\Jobs\RangeBulkAction;
use App\Models\AuditLog;
use App\Models\Product;
use App\Tod\TodPricingImporter;
use Illuminate\Console\Command;

/**
 * One-off migration from TOD (stack principle 2: only what the module needs):
 * suppliers and markups, decoration type classifications, the live web range,
 * staff price settings and product overrides. Exports come from TOD's
 * ExportPricingTables VBA; they hold no customer data, but are never put in Git.
 */
class TodImportCommand extends Command
{
    protected $signature = 'tod:import
        {folder : Folder holding the TOD .tsv exports}
        {--refresh : Afterwards, queue a Promodata refresh of every live product (replaces TOD\'s cost snapshot with current supplier data)}';

    protected $description = 'Import the live range, markups, decoration types and overrides from TOD exports';

    public function handle(RangeManager $range): int
    {
        $counts = (new TodPricingImporter((string) $this->argument('folder')))->import();
        foreach ($counts as $what => $n) {
            $this->line(sprintf('  %-22s %8d', $what, $n));
        }

        // Decoration products other products borrow from go first.
        $ids = Product::query()->fullDetail()->orderByRaw("CASE tier WHEN 'active' THEN 0 ELSE 1 END")->orderBy('id')->pluck('id');
        $failed = 0;
        foreach ($ids->chunk(200) as $chunk) {
            foreach (Product::query()->whereKey($chunk->all())->get() as $product) {
                if ($range->reprice($product) !== null) {
                    $failed++;
                }
            }
        }
        Product::query()->where('tier', Product::TIER_LIVE)->update(['range_changed_at' => now()]);
        $this->info("Priced {$ids->count()} products".($failed ? ", {$failed} with errors (see the Range screen)." : '.'));

        if ($this->option('refresh')) {
            $live = Product::query()->where('tier', Product::TIER_LIVE)->where('source', 'promodata')->pluck('id');
            foreach ($live->chunk(25) as $chunk) {
                RangeBulkAction::dispatch('refresh', $chunk->values()->all());
            }
            $this->info("Queued a Promodata refresh of {$live->count()} live products (runs in the background).");
        }

        AuditLog::record('tod.imported', null, $counts);

        return self::SUCCESS;
    }
}
