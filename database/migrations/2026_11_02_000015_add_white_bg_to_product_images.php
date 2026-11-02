<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Scott, 2 Nov 2026: a style photo on a white background is the hero everywhere;
 * Promodata's hero drops to other style. white_bg: null = not looked at yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->boolean('white_bg')->nullable()->after('hide_quote');
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->dropColumn('white_bg');
        });
    }
};
