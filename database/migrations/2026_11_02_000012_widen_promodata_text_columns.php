<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Promodata text has no fixed length: some products send categories as long
 * comma-joined lists, which overflowed VARCHAR(255) on MariaDB and stalled the
 * full sync at page 36. Free-text product fields become TEXT so nothing is cut
 * off. Indexed or key columns keep a VARCHAR, sized generously.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->text('code')->nullable()->change();
            $table->text('name')->change();
            $table->string('country', 64)->nullable()->change();
            $table->text('supplier_category')->nullable()->change();
            $table->text('supplier_subcategory')->nullable()->change();
            $table->string('promodata_type_id', 191)->nullable()->change();
            $table->text('promodata_type_name')->nullable()->change();
            $table->text('hero_image_url')->nullable()->change();
            $table->text('material')->nullable()->change();
        });

        Schema::table('product_details', function (Blueprint $table) {
            $table->text('supplier_brand')->nullable()->change();
            $table->text('supplier_label')->nullable()->change();
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->text('name')->change();
        });
    }

    public function down(): void
    {
        // Narrowing again could truncate stored data, so this is one-way.
    }
};
