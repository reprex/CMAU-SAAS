<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Each method's own maximum size on a template position (e.g. a pen barrel: pad print 60 × 7, screen print 55 × 12). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branding_positions', function (Blueprint $table) {
            $table->json('method_sizes')->nullable()->after('methods');
            $table->string('confidence', 10)->nullable()->after('method_sizes');
        });
    }

    public function down(): void
    {
        Schema::table('branding_positions', function (Blueprint $table) {
            $table->dropColumn(['method_sizes', 'confidence']);
        });
    }
};
