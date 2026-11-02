<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Staff-curated branding positions (Scott, 2 Nov 2026): a rule for a product type,
 * or for one product, beats the positions worked out automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('position_rules', function (Blueprint $table) {
            $table->id();
            $table->string('product_type', 191)->nullable()->unique();   // Promodata type name; null for a product rule
            $table->unsignedBigInteger('product_id')->nullable()->unique();
            $table->json('positions');                                   // [{name, view, width_mm, height_mm}]
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('position_rules');
    }
};
