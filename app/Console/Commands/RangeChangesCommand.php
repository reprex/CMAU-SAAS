<?php

namespace App\Console\Commands;

use App\Catalogue\RangeChanges;
use App\Models\RangeSuggestion;
use Illuminate\Console\Command;

/**
 * Range › Changes to vet (docs/range.md): find suppliers' new products and discontinued products,
 * then fetch, price and check a few a run. --recheck re-runs the checks on everything waiting.
 */
class RangeChangesCommand extends Command
{
    protected $signature = 'range:changes {--limit=10 : Products to fetch and check this run} {--recheck : Check every waiting product again}';

    protected $description = 'Find new and discontinued products for Range › Changes to vet, and check them';

    public function handle(RangeChanges $changes): int
    {
        $found = $changes->find();
        if ($this->option('recheck')) {
            RangeSuggestion::query()->where('status', RangeSuggestion::PENDING)->orderBy('id')
                ->each(fn ($s) => $changes->recheck($s));
        }
        $done = $changes->prepare((int) $this->option('limit'));
        $this->info("Found {$found['new']} new and {$found['discontinued']} discontinued; checked {$done}.");

        return self::SUCCESS;
    }
}
