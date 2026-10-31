<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where branding goes on each hero image (docs/logo-preview.md), as a share of
 * the picture, so the website can show the CrowdMerch logo there and swap in
 * the shopper's own logo. Keyed by image URL so it survives Promodata refreshes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_areas', function (Blueprint $table) {
            $table->char('url_hash', 40)->primary();            // sha1(hero image url)
            $table->string('url', 1024);
            $table->unsignedBigInteger('product_id')->index();
            $table->decimal('left', 5, 2);                       // % of image width
            $table->decimal('top', 5, 2);                        // % of image height
            $table->decimal('width', 5, 2);                      // % of image width
            $table->string('source', 8)->default('default');     // default | ai | staff
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_areas');
    }
};
