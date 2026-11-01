<?php

namespace Database\Seeders;

use App\Models\PublicHoliday;
use Illuminate\Database\Seeder;

/**
 * Australian public holidays, November 2026 to December 2027, from the Fair Work
 * Ombudsman's 2026 and 2027 lists and Business Victoria (checked 1 Nov 2026).
 * Whole-day, state-wide holidays only: part-day ones (Christmas Eve and New
 * Year's Eve evenings in NT, QLD, SA) and area-only ones (TAS Regatta and
 * Recreation Day, Royal Hobart Show, Royal Queensland Show) are left out.
 * Weekend holidays are kept for the record; business days skip weekends anyway.
 * Re-run any time (it upserts). Later years: php artisan holidays:import file.csv
 */
class PublicHolidaySeeder extends Seeder
{
    public const HOLIDAYS = [
        'VIC' => [
            '2026-11-03' => 'Melbourne Cup',
            '2026-12-25' => 'Christmas Day',
            '2026-12-26' => 'Boxing Day',
            '2026-12-28' => 'Boxing Day (additional day)',
            '2027-01-01' => "New Year's Day",
            '2027-01-26' => 'Australia Day',
            '2027-03-08' => 'Labour Day',
            '2027-03-26' => 'Good Friday',
            '2027-03-27' => 'Saturday before Easter Sunday',
            '2027-03-28' => 'Easter Sunday',
            '2027-03-29' => 'Easter Monday',
            // Anzac Day falls on Sunday 25 April 2027. Victoria has NO Monday substitute
            // (Business Victoria and Fair Work, checked 1 Nov 2026), unlike NSW, WA, ACT, NT and QLD.
            '2027-04-25' => 'Anzac Day',
            '2027-06-14' => "King's Birthday",
            // TODO: Friday before the AFL Grand Final 2027 (normally the last Friday in
            // September). Not set yet: add it with holidays:import once the AFL announces the date.
            '2027-11-02' => 'Melbourne Cup',
            '2027-12-25' => 'Christmas Day',
            '2027-12-26' => 'Boxing Day',
            '2027-12-27' => 'Christmas Day (additional day)',
            '2027-12-28' => 'Boxing Day (additional day)',
        ],
        'NSW' => [
            '2026-12-25' => 'Christmas Day', '2026-12-26' => 'Boxing Day', '2026-12-28' => 'Boxing Day (additional day)',
            '2027-01-01' => "New Year's Day", '2027-01-26' => 'Australia Day',
            '2027-03-26' => 'Good Friday', '2027-03-27' => 'Easter Saturday', '2027-03-28' => 'Easter Sunday', '2027-03-29' => 'Easter Monday',
            '2027-04-25' => 'Anzac Day', '2027-04-26' => 'Anzac Day (additional day)',
            '2027-06-14' => "King's Birthday", '2027-10-04' => 'Labour Day',
            '2027-12-25' => 'Christmas Day', '2027-12-26' => 'Boxing Day', '2027-12-27' => 'Christmas Day (additional day)', '2027-12-28' => 'Boxing Day (additional day)',
        ],
        'ACT' => [
            '2026-12-25' => 'Christmas Day', '2026-12-26' => 'Boxing Day', '2026-12-28' => 'Boxing Day (additional day)',
            '2027-01-01' => "New Year's Day", '2027-01-26' => 'Australia Day', '2027-03-08' => 'Canberra Day',
            '2027-03-26' => 'Good Friday', '2027-03-27' => 'Easter Saturday', '2027-03-28' => 'Easter Sunday', '2027-03-29' => 'Easter Monday',
            '2027-04-26' => 'Anzac Day (observed)', '2027-05-31' => 'Reconciliation Day',
            '2027-06-14' => "King's Birthday", '2027-10-04' => 'Labour Day',
            '2027-12-25' => 'Christmas Day', '2027-12-26' => 'Boxing Day', '2027-12-27' => 'Christmas Day (additional day)', '2027-12-28' => 'Boxing Day (additional day)',
        ],
        'QLD' => [
            '2026-12-25' => 'Christmas Day', '2026-12-26' => 'Boxing Day', '2026-12-28' => 'Boxing Day (additional day)',
            '2027-01-01' => "New Year's Day", '2027-01-26' => 'Australia Day',
            '2027-03-26' => 'Good Friday', '2027-03-27' => 'The day after Good Friday', '2027-03-28' => 'Easter Sunday', '2027-03-29' => 'Easter Monday',
            '2027-04-26' => 'Anzac Day (observed)', '2027-05-03' => 'Labour Day', '2027-10-04' => "King's Birthday",
            '2027-12-25' => 'Christmas Day', '2027-12-26' => 'Boxing Day', '2027-12-27' => 'Christmas Day (additional day)', '2027-12-28' => 'Boxing Day (additional day)',
        ],
        'SA' => [
            '2026-12-25' => 'Christmas Day', '2026-12-26' => 'Proclamation Day', '2026-12-28' => 'Proclamation Day (additional day)',
            '2027-01-01' => "New Year's Day", '2027-01-26' => 'Australia Day', '2027-03-08' => 'Adelaide Cup Day',
            '2027-03-26' => 'Good Friday', '2027-03-27' => 'Easter Saturday', '2027-03-28' => 'Easter Sunday', '2027-03-29' => 'Easter Monday',
            '2027-04-25' => 'Anzac Day', '2027-06-14' => "King's Birthday", '2027-10-04' => 'Labour Day',
            '2027-12-25' => 'Christmas Day', '2027-12-26' => 'Proclamation Day', '2027-12-27' => 'Christmas Day (additional day)', '2027-12-28' => 'Proclamation Day (additional day)',
        ],
        'WA' => [
            '2026-12-25' => 'Christmas Day', '2026-12-26' => 'Boxing Day', '2026-12-28' => 'Boxing Day (additional day)',
            '2027-01-01' => "New Year's Day", '2027-01-26' => 'Australia Day', '2027-03-01' => 'Labour Day',
            '2027-03-26' => 'Good Friday', '2027-03-28' => 'Easter Sunday', '2027-03-29' => 'Easter Monday',
            '2027-04-25' => 'Anzac Day', '2027-04-26' => 'Anzac Day (additional day)',
            '2027-06-07' => 'Western Australia Day', '2027-09-27' => "King's Birthday",
            '2027-12-25' => 'Christmas Day', '2027-12-26' => 'Boxing Day', '2027-12-27' => 'Christmas Day (additional day)', '2027-12-28' => 'Boxing Day (additional day)',
        ],
        'TAS' => [
            '2026-12-25' => 'Christmas Day', '2026-12-28' => 'Boxing Day',
            '2027-01-01' => "New Year's Day", '2027-01-26' => 'Australia Day', '2027-03-08' => 'Eight Hours Day',
            '2027-03-26' => 'Good Friday', '2027-03-29' => 'Easter Monday',
            '2027-04-25' => 'Anzac Day', '2027-06-14' => "King's Birthday",
            '2027-12-25' => 'Christmas Day', '2027-12-27' => 'Christmas Day (additional day)', '2027-12-28' => 'Boxing Day',
        ],
        'NT' => [
            '2026-12-25' => 'Christmas Day', '2026-12-26' => 'Boxing Day', '2026-12-28' => 'Boxing Day (additional day)',
            '2027-01-01' => "New Year's Day", '2027-01-26' => 'Australia Day',
            '2027-03-26' => 'Good Friday', '2027-03-27' => 'Easter Saturday', '2027-03-28' => 'Easter Sunday', '2027-03-29' => 'Easter Monday',
            '2027-04-26' => 'Anzac Day (observed)', '2027-05-03' => 'May Day', '2027-06-14' => "King's Birthday", '2027-08-02' => 'Picnic Day',
            '2027-12-25' => 'Christmas Day', '2027-12-26' => 'Boxing Day', '2027-12-27' => 'Christmas Day (additional day)', '2027-12-28' => 'Boxing Day (additional day)',
        ],
    ];

    public function run(): void
    {
        $now = now();
        $rows = [];
        foreach (self::HOLIDAYS as $state => $days) {
            foreach ($days as $date => $name) {
                $rows[] = ['state' => $state, 'date' => $date, 'name' => $name, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        // Plain Y-m-d strings (not the model's date cast), so the unique key matches on every database.
        PublicHoliday::query()->toBase()->upsert($rows, ['state', 'date'], ['name', 'updated_at']);
    }
}
