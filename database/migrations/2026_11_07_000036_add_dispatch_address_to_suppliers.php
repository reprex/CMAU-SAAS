<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Where each supplier dispatches from, so delivery is priced from there (Scott, 7 Nov 2026; docs/freight.md). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('dispatch_suburb', 80)->nullable()->after('state');
            $table->string('dispatch_postcode', 10)->nullable()->after('dispatch_suburb');
            $table->string('dispatch_source', 40)->nullable()->after('dispatch_postcode'); // promodata | staff
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn(['dispatch_suburb', 'dispatch_postcode', 'dispatch_source']);
        });
    }
};
