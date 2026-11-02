<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Website artwork check (Scott, 2 Nov 2026): nothing is rejected; each position's
 * logo is flagged print ready or not, with what the shopper's browser found.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_order_positions', function (Blueprint $table) {
            $table->string('preflight', 10)->nullable()->after('logo_mime'); // ready | help
            $table->json('preflight_notes')->nullable()->after('preflight');
        });
    }

    public function down(): void
    {
        Schema::table('website_order_positions', function (Blueprint $table) {
            $table->dropColumn(['preflight', 'preflight_notes']);
        });
    }
};
