<?php

use Database\Seeders\PublicHolidaySeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Production has no shell, so the holidays the delivery estimate needs are
 * loaded by the deploy's migrate step (it upserts; safe to run again).
 */
return new class extends Migration
{
    public function up(): void
    {
        (new PublicHolidaySeeder)->run();
    }

    public function down(): void
    {
        // Holidays stay; the table itself is dropped by the previous migration's down().
    }
};
