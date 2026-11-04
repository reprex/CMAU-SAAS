<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a print area really sits on our photo (Scott, 5 Nov 2026): its four corners, so a logo follows a
 * rotated, skewed or perspective surface, and the surface's shape, so it curves round a bottle or a barrel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branding_positions', function (Blueprint $table) {
            $table->json('quad')->nullable()->after('width');
            $table->string('surface', 12)->nullable()->after('quad');
        });
    }

    public function down(): void
    {
        Schema::table('branding_positions', function (Blueprint $table) {
            $table->dropColumn(['quad', 'surface']);
        });
    }
};
