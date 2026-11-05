<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decoration types per supplier (Scott, 5 Nov 2026): the same wording can be branding for one supplier and
 * hidden for another, so a type read from a supplier's own pages or pricing rule belongs to that supplier
 * only. TOD's single shared table (dec_type_codes) stays as the fallback for suppliers whose pages haven't
 * been read yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_dec_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supplier_id');
            $table->string('code', 190);
            $table->unsignedTinyInteger('type_code');
            $table->string('why', 160)->nullable();      // where it came from (supplier pages, pricing rule, wording)
            $table->boolean('staff')->default(false);     // set by staff: the classifier leaves it alone
            $table->timestamps();
            $table->unique(['supplier_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_dec_types');
    }
};
