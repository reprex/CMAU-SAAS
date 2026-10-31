<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Image tags (docs/images.md): hero_style | other_style | colour | other_colour,
 * with the colour a colour image belongs to. Run `catalogue:tag-images` after.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->string('colour_name')->nullable()->after('role');
            $table->string('tagged_by', 8)->default('auto')->after('colour_name'); // auto | staff
        });
        DB::table('product_images')->where('role', 'hero')->update(['role' => 'hero_style']);
        DB::table('product_images')->where('role', 'style')->update(['role' => 'other_style']);
        DB::table('product_images')->where('role', 'alternate')->update(['role' => 'other_colour']);
    }

    public function down(): void
    {
        DB::table('product_images')->where('role', 'hero_style')->update(['role' => 'hero']);
        DB::table('product_images')->where('role', 'other_style')->update(['role' => 'style']);
        DB::table('product_images')->where('role', 'other_colour')->update(['role' => 'alternate']);
        Schema::table('product_images', function (Blueprint $table) {
            $table->dropColumn(['colour_name', 'tagged_by']);
        });
    }
};
