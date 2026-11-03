<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Product options chosen with a website order (docs/product-options.md): JSON, as priced by the SaaS. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_orders', function (Blueprint $table) {
            $table->text('options')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('website_orders', function (Blueprint $table) {
            $table->dropColumn('options');
        });
    }
};
