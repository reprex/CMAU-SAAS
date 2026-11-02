<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Range collections (Scott, 3 Nov 2026; docs/publisher.md): one smart collection per product
 * subcategory, matched to the store's existing "TAG EQUALS <tag>" collections or created, and
 * the tag every product of that subcategory carries. Sorted by the SaaS (manual order).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_collections', function (Blueprint $table) {
            $table->id();
            $table->string('product_type', 191)->unique();   // Promodata type, e.g. "Drinkware > Drink Bottles"
            $table->string('tag', 120)->index();             // the collection's rule tag, e.g. "drinkbottles"
            $table->string('title', 191)->nullable();
            $table->string('handle', 191)->nullable();
            $table->string('shopify_gid', 120)->nullable();
            $table->boolean('created_by_saas')->default(false);
            $table->timestamp('sorted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_collections');
    }
};
