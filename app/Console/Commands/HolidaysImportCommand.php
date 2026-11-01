<?php

namespace App\Console\Commands;

use App\Models\PublicHoliday;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Loads public holidays from a CSV with a header row: date,state,name
 * (date as YYYY-MM-DD, state VIC/NSW/QLD/SA/WA/TAS/NT/ACT). Existing
 * date + state rows are updated. E.g. data.gov.au's Australian public holidays
 * file after mapping its columns, or a hand-made file for the AFL Grand Final Friday.
 */
class HolidaysImportCommand extends Command
{
    protected $signature = 'holidays:import {file : CSV with date,state,name columns} {--dry-run : Show what would change}';

    protected $description = 'Import public holidays (date, state, name) from a CSV file';

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        $fh = is_readable($path) ? fopen($path, 'r') : false;
        if ($fh === false) {
            $this->error("Cannot read {$path}");

            return self::FAILURE;
        }
        $header = array_map(fn ($h) => mb_strtolower(trim((string) $h, " \t\n\r\0\x0B\xEF\xBB\xBF")), (array) fgetcsv($fh, escape: '\\'));
        $col = array_flip($header);
        if (! isset($col['date'], $col['state'], $col['name'])) {
            $this->error('The first row must name the columns: date,state,name');

            return self::FAILURE;
        }

        $added = $updated = $skipped = 0;
        $line = 1;
        while (($row = fgetcsv($fh, escape: '\\')) !== false) {
            $line++;
            if ($row === [null]) {
                continue;
            }
            $state = mb_strtoupper(trim((string) ($row[$col['state']] ?? '')));
            $name = trim((string) ($row[$col['name']] ?? ''));
            try {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', trim((string) ($row[$col['date']] ?? '')));
            } catch (Throwable) {
                $date = false;
            }
            if (! $date || ! in_array($state, PublicHoliday::STATES, true) || $name === '') {
                $this->warn("Line {$line} skipped: needs a YYYY-MM-DD date, an Australian state and a name.");
                $skipped++;

                continue;
            }
            $existing = PublicHoliday::query()->where('state', $state)->whereDate('date', $date->toDateString())->exists();
            if (! $this->option('dry-run')) {
                PublicHoliday::query()->toBase()->upsert(
                    [['state' => $state, 'date' => $date->toDateString(), 'name' => mb_substr($name, 0, 120), 'created_at' => now(), 'updated_at' => now()]],
                    ['state', 'date'], ['name', 'updated_at'],
                );
            }
            $existing ? $updated++ : $added++;
        }
        fclose($fh);

        $this->info(($this->option('dry-run') ? 'Dry run: ' : '')."{$added} added, {$updated} updated, {$skipped} skipped.");

        return self::SUCCESS;
    }
}
