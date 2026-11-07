<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Flat delivery per supplier for chosen countries (Scott, 7 Nov 2026; docs/freight.md): the supplier
 * builds freight into its prices, so we charge a flat price and expect a flat cost instead of carrier
 * rates. Trends: AU and NZ, cost AUD 15, price AUD 25; other countries go by FedEx.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('flat_freight_countries', 60)->nullable()->after('dispatch_source'); // e.g. "AU,NZ"
            $table->decimal('flat_freight_cost', 8, 2)->nullable()->after('flat_freight_countries');
            $table->decimal('flat_freight_price', 8, 2)->nullable()->after('flat_freight_cost');
            $table->unsignedTinyInteger('flat_freight_days')->nullable()->after('flat_freight_price');
        });
        DB::table('suppliers')->where('name', 'Trends')->whereNull('flat_freight_countries')
            ->update(['flat_freight_countries' => 'AU,NZ', 'flat_freight_cost' => 15, 'flat_freight_price' => 25]);
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn(['flat_freight_countries', 'flat_freight_cost', 'flat_freight_price', 'flat_freight_days']);
        });
    }
};
