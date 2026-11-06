<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The website quote's cart as the shopper built it (product, colour, sizes, branding positions and their
 * placement), so the PDF's "complete the online checkout" link rebuilds that cart (Scott, 7 Nov 2026).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->json('cart_state')->nullable();
            // Website quotes (Scott, 7 Nov 2026): the next price breaks up, delivery priced by the carrier
            // ('carrier') or not wanted ('none') instead of the suppliers' standard freight, and exports GST-free.
            $table->json('price_breaks')->nullable();
            $table->string('freight_source', 20)->nullable();
            $table->boolean('gst_free_export')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn(['cart_state', 'price_breaks', 'freight_source', 'gst_free_export']);
        });
    }
};
