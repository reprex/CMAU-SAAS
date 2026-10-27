<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier standard freight, maintained by admins only. TOD never stored it;
 * quotes add one freight line per supplier per delivery from these values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->decimal('freight_cost', 10, 2)->nullable()->after('sample_available');
            $table->decimal('freight_sell', 10, 2)->nullable()->after('freight_cost');
            $table->string('freight_basis', 16)->default('per_delivery')->after('freight_sell'); // per_delivery | per_line
            $table->decimal('free_freight_over', 10, 2)->nullable()->after('freight_basis');
            $table->boolean('freight_decorator_leg')->default(false)->after('free_freight_over');
            $table->date('freight_effective_from')->nullable()->after('freight_decorator_leg');
            $table->boolean('is_decorator')->default(false)->after('freight_effective_from');
        });

        // Every saved change, so a quote can show which freight applied when.
        Schema::create('supplier_freight_rates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supplier_id')->index();
            $table->decimal('freight_cost', 10, 2)->nullable();
            $table->decimal('freight_sell', 10, 2)->nullable();
            $table->string('freight_basis', 16);
            $table->decimal('free_freight_over', 10, 2)->nullable();
            $table->boolean('freight_decorator_leg')->default(false);
            $table->date('effective_from')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('supplier_id')->references('id')->on('suppliers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_freight_rates');
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn(['freight_cost', 'freight_sell', 'freight_basis', 'free_freight_over', 'freight_decorator_leg', 'freight_effective_from', 'is_decorator']);
        });
    }
};
