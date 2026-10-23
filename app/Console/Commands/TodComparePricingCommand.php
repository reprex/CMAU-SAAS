<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Pricing\PriceOptionBuilder;
use App\Pricing\WebPriceCalculator;
use App\Tod\TodPricingComparer;
use App\Tod\TodPricingImporter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Verification tool: load TOD's pricing exports, run the pricing engine over
 * the live range and report every difference from TOD's stored results.
 * Not scheduled; run by hand against a local or staging database.
 */
class TodComparePricingCommand extends Command
{
    protected $signature = 'tod:compare-pricing
        {folder : Folder holding the TOD .tsv exports}
        {--skip-import : Re-use data already imported}
        {--tod-defaults : Pin default decorations to the TOD picks, to check everything downstream}
        {--product=* : Limit to these product ids}
        {--report= : Folder for the CSV reports (default storage/app/tod-compare)}';

    protected $description = 'Compare the pricing engine with TOD for the live range (verification only)';

    public function handle(PriceOptionBuilder $builder, WebPriceCalculator $calculator): int
    {
        $folder = rtrim((string) $this->argument('folder'), '/');

        if (! $this->option('skip-import')) {
            $this->info('Importing TOD exports...');
            $counts = (new TodPricingImporter($folder, useTodDefaults: (bool) $this->option('tod-defaults')))->import();
            foreach ($counts as $what => $n) {
                $this->line(sprintf('  %-22s %8d', $what, $n));
            }
        }

        $query = Product::query()->where('tier', Product::TIER_LIVE);
        if ($ids = array_map('intval', (array) $this->option('product'))) {
            $query->whereKey($ids);
        }
        $live = $query->pluck('id')->all();

        // External decoration products first, so products that borrow from them see fresh options.
        $order = Product::query()->where('tier', Product::TIER_ACTIVE)->pluck('id')->merge($live)->unique()->all();

        $this->info('Pricing '.count($order).' products...');
        $failed = 0;
        foreach (Product::query()->whereKey($order)->orderByRaw('id > 999999 desc')->orderBy('id')->lazyById(200) as $product) {
            try {
                $builder->build($product);
                $calculator->calculate($product);
            } catch (Throwable $e) {
                $failed++;
                $this->warn("  {$product->id}: {$e->getMessage()}");
            }
        }

        $this->info('Comparing with TOD...');
        $result = (new TodPricingComparer($folder))->compare($live);

        $rows = [];
        foreach ($result['summary'] as $key => $n) {
            [$level, $field, $state] = explode('.', $key);
            $rows["{$level}.{$field}"][$state] = $n;
        }
        $this->table(['field', 'match', 'differ'], collect($rows)->map(fn ($v, $k) => [$k, $v['match'] ?? 0, $v['differ'] ?? 0])->values()->all());

        $dir = $this->option('report') ?: storage_path('app/tod-compare');
        @mkdir($dir, 0775, true);
        $file = $dir.'/differences-'.now()->format('Ymd-His').'.csv';
        $out = fopen($file, 'w');
        fputcsv($out, ['level', 'field', 'product_id', 'option_id', 'tod', 'engine'], escape: '');
        foreach ($result['differences'] as $d) {
            fputcsv($out, array_values($d), escape: '');
        }
        fclose($out);
        $this->info("Differences written to {$file}".($failed ? " ({$failed} products failed to price)" : ''));

        return self::SUCCESS;
    }
}
