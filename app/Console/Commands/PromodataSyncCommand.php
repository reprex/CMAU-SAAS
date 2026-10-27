<?php

namespace App\Console\Commands;

use App\Catalogue\SyncRunner;
use App\Models\SyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class PromodataSyncCommand extends Command
{
    protected $signature = 'promodata:sync
        {--full : Sync every product (first load)}
        {--since= : Only products changed since this date/time (e.g. "2026-10-01 00:00")}
        {--supplier= : Limit to one Promodata supplier id}
        {--now : Process the whole run immediately instead of leaving it to the scheduler}';

    protected $description = 'Start a Promodata sync run (processed a few pages at a time by the scheduler)';

    public function handle(SyncRunner $runner): int
    {
        $supplier = $this->option('supplier') !== null ? (int) $this->option('supplier') : null;
        $since = $this->option('since') !== null ? CarbonImmutable::parse($this->option('since')) : null;

        if ($this->option('full') || $since !== null || $supplier !== null) {
            $kind = $since !== null ? SyncRun::KIND_CHANGED : ($supplier !== null ? SyncRun::KIND_SUPPLIER : SyncRun::KIND_FULL);
            $run = $runner->start($kind, $since, $supplier);
        } else {
            $run = $runner->startChangedSinceLastRun();
            if ($run === null) {
                $this->info('A sync run is already in progress; nothing started.');

                return self::SUCCESS;
            }
        }

        $this->info("Started {$run->kind} sync run #{$run->id}.");

        if ($this->option('now')) {
            do {
                $run = $runner->step(config('promodata.pages_per_step'));
                $this->line(sprintf('Run #%d: page %d of %s, %d products seen, %d changed.',
                    $run->id, $run->next_page - 1, $run->total_pages ?? '?', $run->products_seen, $run->products_changed));
            } while (! $run->isFinished());
        }

        return self::SUCCESS;
    }
}
