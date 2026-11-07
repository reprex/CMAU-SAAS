<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase orders go to another supplier (Scott, 7 Nov 2026; docs/purchase-orders.md): e.g. AS Colour and
 * JB's Wear are ordered and decorated through Melbourne Merch, so their POs go there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->unsignedBigInteger('po_supplier_id')->nullable()->after('our_account_number');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('po_supplier_id');
        });
    }
};
