<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compact comparison facts on every product's summary row, so the substitute
 * finder can compare across the whole catalogue without full detail:
 * material, dimensions (mm, largest first), fastest lead time, and the
 * supplier's unbranded minimum order and cost at that quantity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('material', 255)->nullable()->after('promodata_type_name');
            $table->decimal('dim_1', 10, 1)->nullable()->after('material');
            $table->decimal('dim_2', 10, 1)->nullable()->after('dim_1');
            $table->decimal('dim_3', 10, 1)->nullable()->after('dim_2');
            $table->unsignedSmallInteger('lead_time_days')->nullable()->after('dim_3');
            $table->unsignedInteger('undecorated_moq')->nullable()->after('min_qty');
            $table->decimal('undecorated_cost', 12, 4)->nullable()->after('undecorated_moq');
            $table->unsignedTinyInteger('summary_version')->default(0)->after('content_hash');
            $table->index(['promodata_type_id', 'discontinued', 'undecorated_cost'], 'products_substitutes_idx');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_substitutes_idx');
            $table->dropColumn(['material', 'dim_1', 'dim_2', 'dim_3', 'lead_time_days', 'undecorated_moq', 'undecorated_cost', 'summary_version']);
        });
    }
};
