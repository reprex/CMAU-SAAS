<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The website's quote and mockup PDF (docs/quote-mockup-pdf.md, Scott 7 Nov 2026): the shopper's mockup
 * pictures (private files), the similar products we showed them and the products they hearted, for the
 * PDF's second page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->json('mockups')->nullable();          // [{path, view}] on the artwork disk
            $table->json('similar_products')->nullable(); // product ids, up to 4
            $table->json('saved_products')->nullable();   // product ids the shopper hearted
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn(['mockups', 'similar_products', 'saved_products']);
        });
    }
};
