<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suppliers and their markups (TOD: dbo_PDS_Supplier).
 * Defaults match TOD's "update Dec and Acc markups if null" query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            // Promodata supplier_id (TOD: APPASupplierID); not auto-incrementing.
            $table->unsignedBigInteger('id')->primary();
            $table->string('name');
            $table->string('country', 64)->nullable();
            $table->string('appa_member_number', 64)->nullable();

            $table->boolean('web_flag')->default(false);
            $table->unsignedInteger('rank')->nullable();

            // Web pricing markups by price-line type code (10 dec, 11 acc, 12 undec).
            $table->decimal('markup_undecorated', 6, 4)->default(1.45);
            $table->decimal('markup_decoration', 6, 4)->default(1.50);
            $table->decimal('markup_accessory', 6, 4)->default(1.45);
            $table->decimal('markup_setup', 6, 4)->default(1.00);

            // Quote pricing markups, tiered by line value.
            $table->decimal('quote_markup_to_300', 6, 4)->nullable();
            $table->decimal('quote_markup_to_1000', 6, 4)->nullable();
            $table->decimal('quote_markup_over_1000', 6, 4)->nullable();
            $table->decimal('markup_sample', 6, 4)->nullable();
            $table->decimal('sample_freight', 10, 2)->nullable();
            $table->boolean('sample_available')->default(false);

            $table->string('stock_columns')->nullable(); // Shopify metafield custom.stockcolumns
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
