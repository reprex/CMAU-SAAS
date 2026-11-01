<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Samples, automatic branding positions and our codes (Scott, 1 Nov 2026;
 * docs/website-checkout.md, docs/logo-preview.md, docs/supplier-codes.md).
 *
 *  - suppliers.offers_samples: the website's "Order sample" (off by default).
 *    suppliers.sample_freight already exists (from TOD) and is the sample
 *    freight per consignment, ex GST. sample_lead_days: empty = stock lead time.
 *  - website_orders.kind: "order" or "samples"; samples have no need-by date.
 *  - website_order_lines: our code and our SKU (what the client sees), and for
 *    samples the factory group and its supplier (staff only).
 *  - print_areas.source gets room for "decoration" (Promodata's stated print area).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->boolean('offers_samples')->default(false)->after('sample_available');
            $table->unsignedTinyInteger('sample_lead_days')->nullable()->after('stock_lead_days');
        });

        Schema::table('website_orders', function (Blueprint $table) {
            $table->string('kind', 10)->default('order')->index()->after('number'); // order | samples
        });
        Schema::table('website_orders', function (Blueprint $table) {
            $table->date('need_by')->nullable()->change();
        });

        Schema::table('website_order_lines', function (Blueprint $table) {
            $table->string('our_code', 60)->nullable()->after('product_code'); // 3-104743
            $table->string('sku', 140)->nullable()->after('supplier_sku');     // our SKU, e.g. 7-5001-BLAC-M
            $table->unsignedTinyInteger('factory')->nullable()->after('sku');  // samples: "From factory n"
            $table->unsignedBigInteger('supplier_id')->nullable()->after('factory');
        });

        Schema::table('print_areas', function (Blueprint $table) {
            $table->string('source', 12)->default('default')->change(); // default | ai | staff | learned | template | decoration
        });
    }

    public function down(): void
    {
        Schema::table('print_areas', function (Blueprint $table) {
            $table->string('source', 8)->default('default')->change();
        });
        Schema::table('website_order_lines', function (Blueprint $table) {
            $table->dropColumn(['our_code', 'sku', 'factory', 'supplier_id']);
        });
        Schema::table('website_orders', function (Blueprint $table) {
            $table->dropIndex(['kind']);
            $table->dropColumn('kind');
        });
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn(['offers_samples', 'sample_lead_days']);
        });
    }
};
