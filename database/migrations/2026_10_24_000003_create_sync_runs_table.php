<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per supplier-data sync. A run is processed a few pages at a time by
 * the scheduler, so it records where it is up to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32)->default('promodata');
            $table->string('kind', 16);                 // full | changed | supplier
            $table->timestamp('since')->nullable();     // for "changed" runs
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->string('status', 16)->default('pending')->index(); // pending | running | completed | failed
            $table->unsignedInteger('next_page')->default(1);
            $table->unsignedInteger('total_pages')->nullable();
            $table->unsignedInteger('products_seen')->default(0);
            $table->unsignedInteger('products_changed')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
