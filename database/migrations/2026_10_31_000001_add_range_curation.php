<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Range curation: when a product's web-facing data last changed (added,
 * removed, overrides, repriced after a supplier change), so the Shopify
 * publisher can send changed products only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->timestamp('range_changed_at')->nullable()->after('last_used_at');
            $table->timestamp('published_at')->nullable()->after('range_changed_at');
            $table->string('reprice_error', 500)->nullable()->after('published_at');
            $table->index(['tier', 'range_changed_at']);
            $table->index(['supplier_id', 'tier']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['tier', 'range_changed_at']);
            $table->dropIndex(['supplier_id', 'tier']);
            $table->dropColumn(['range_changed_at', 'published_at', 'reprice_error']);
        });
    }
};
