<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Example branding on the colour photos a mockup could be drawn on (Scott, 5 Nov 2026;
 * docs/branding-templates.md, "Photos with example branding"). Keyed by image URL, checked once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photo_brandings', function (Blueprint $table) {
            $table->char('url_hash', 40)->primary();          // sha1(url)
            $table->string('url', 1024);
            $table->unsignedBigInteger('product_id')->index();
            $table->string('branding', 8)->nullable();        // none | small | large (null = could not check)
            $table->json('box')->nullable();                  // [left, top, width, height] percent of the photo
            $table->boolean('plain')->nullable();             // the surface under the branding is plain (can be painted over)
            $table->string('note', 255)->nullable();
            $table->string('model', 64)->nullable();
            $table->timestamp('checked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photo_brandings');
    }
};
