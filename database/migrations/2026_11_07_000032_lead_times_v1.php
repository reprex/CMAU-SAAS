<?php

use Database\Seeders\PublicHolidaySeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lead times v1 (docs/lead-times.md): suppliers that ship from overseas (Trends from New Zealand,
 * 4 working days to the client), each supplier's own closures (the Christmas shutdown), and New
 * Zealand's public holidays.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->unsignedTinyInteger('transit_days')->nullable()->after('stock_lead_days');   // overseas to the client, working days
            $table->string('ships_from', 2)->nullable()->after('transit_days');                 // e.g. NZ
        });
        Schema::create('supplier_closures', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supplier_id')->index();
            $table->date('closed_from');
            $table->date('closed_to');                 // last day closed
            $table->string('note', 255)->nullable();   // "Christmas shutdown"
            $table->string('source', 255)->nullable(); // where the dates came from
            $table->timestamps();
        });

        (new PublicHolidaySeeder)->run();

        // Trends (Promodata supplier 3) makes in Auckland and ships to the client from New Zealand (Scott, 5 Nov 2026).
        DB::table('suppliers')->where('id', 3)->where('name', 'like', '%trends%')
            ->update(['state' => 'NZ', 'transit_days' => 4, 'ships_from' => 'NZ', 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_closures');
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn(['transit_days', 'ships_from']);
        });
    }
};
