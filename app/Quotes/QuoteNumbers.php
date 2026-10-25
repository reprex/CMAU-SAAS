<?php

namespace App\Quotes;

use Illuminate\Support\Facades\DB;

/**
 * Quote numbers continue TOD's series. Quotes print as MR<n>, product
 * comparisons for the same quote as PS<n>.
 */
class QuoteNumbers
{
    public function next(): string
    {
        return DB::transaction(function () {
            $row = DB::table('counters')->where('name', 'quote')->lockForUpdate()->first();
            $value = $row ? $row->value + 1 : (int) config('quotes.number_start');

            DB::table('counters')->updateOrInsert(['name' => 'quote'], ['value' => $value]);

            return config('quotes.number_prefix').$value;
        });
    }

    public static function comparisonNumber(string $quoteNumber): string
    {
        return preg_replace('/^[A-Z]+/', config('quotes.comparison_prefix'), $quoteNumber);
    }
}
