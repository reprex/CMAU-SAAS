<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The supplier's own SKU for every colour and size (docs/supplier-codes.md),
 * captured from Promodata stock checks so the product page can list them all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_skus', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->index();
            $table->unsignedInteger('position')->default(0);
            $table->string('colour')->nullable();
            $table->string('size', 60)->nullable();
            $table->string('sku', 120)->nullable();
            $table->integer('qty')->nullable();
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });
        Schema::table('products', function (Blueprint $table) {
            $table->timestamp('skus_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_skus');
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('skus_checked_at');
        });
    }
};
