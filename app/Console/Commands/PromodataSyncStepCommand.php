<?php

namespace App\Console\Commands;

use App\Catalogue\SyncRunner;
use Illuminate\Console\Command;

class PromodataSyncStepCommand extends Command
{
    protected $signature = 'promodata:sync-step {--pages= : Pages to process in this step}';

    protected $description = 'Process the next few pages of the open supplier sync run (run by the scheduler)';

    public function handle(SyncRunner $runner): int
    {
        $pages = (int) ($this->option('pages') ?? config('promodata.pages_per_step'));
        $run = $runner->step(max(1, $pages));

        if ($run !== null) {
            $this->line(sprintf('Run #%d %s: next page %d of %s.', $run->id, $run->status, $run->next_page, $run->total_pages ?? '?'));
        }

        return self::SUCCESS;
    }
}
