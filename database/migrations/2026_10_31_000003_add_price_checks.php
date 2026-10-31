<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Price check (docs/price-check.md): every full-detail product is grouped into
 * a price pattern (supplier + how its Promodata base price lines are worded and
 * built). Staff verify a pattern from a screengrab of the supplier's own
 * distributor prices for one or two example products; the verdict covers the
 * whole pattern. Per-product flags catch the products that don't fit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('price_pattern', 40)->nullable()->index();
            $table->json('price_flags')->nullable();
        });

        Schema::create('price_patterns', function (Blueprint $table) {
            $table->string('signature', 40)->primary();
            $table->unsignedBigInteger('supplier_id')->index();
            $table->string('label', 1024);
            $table->json('structure');
            $table->unsignedInteger('product_count')->default(0);
            $table->unsignedInteger('live_count')->default(0);
            $table->unsignedInteger('flagged_count')->default(0);
            $table->json('examples')->nullable();          // product ids to screengrab
            $table->decimal('median_ratio', 8, 4)->nullable(); // branded base ÷ unbranded
            $table->timestamps();
        });

        Schema::create('price_checks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->index();
            $table->string('price_pattern', 40)->nullable()->index();
            $table->string('verdict', 24);                 // base_branded | base_unbranded | differs
            $table->text('notes')->nullable();
            $table->string('evidence')->nullable();        // screengrab file name
            $table->timestamp('prices_changed_at')->nullable(); // product's at the time of the check
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_checks');
        Schema::dropIfExists('price_patterns');
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['price_pattern']);
            $table->dropColumn(['price_pattern', 'price_flags']);
        });
    }
};
