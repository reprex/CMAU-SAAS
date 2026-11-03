<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Range changes to vet (Scott, 3 Nov 2026; docs/range.md), on one list (Range › Changes to vet):
 *  - new: a supplier set to suggest its new Promodata products (optionally only some product types).
 *    They are fetched and priced, checked against similar products on the range, and wait for staff
 *    to approve or decline them. Nothing reaches Shopify until approved.
 *  - discontinued: products the supplier discontinued that are on the range or in recent quotes not
 *    yet ordered, so staff can offer those clients a substitute.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->boolean('suggest_new')->default(false);
            $table->json('suggest_types')->nullable();      // null: every product type
            $table->timestamp('suggest_since')->nullable(); // products first seen after this
        });

        Schema::create('range_suggestions', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16)->default('new'); // new | discontinued
            $table->unsignedBigInteger('product_id')->index();
            $table->unsignedBigInteger('supplier_id')->index();
            $table->string('status', 16)->default('preparing')->index(); // preparing | pending | approved | declined (discontinued: pending | approved = dealt with)
            $table->boolean('ready')->default(false);
            $table->json('checks')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('range_suggestions');
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn(['suggest_new', 'suggest_types', 'suggest_since']);
        });
    }
};
