<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What Claude saw in each image (docs/images.md), keyed by image URL so a
 * Promodata refresh doesn't pay for the same image twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('image_checks', function (Blueprint $table) {
            $table->char('url_hash', 40)->primary();             // sha1(url)
            $table->string('url', 1024);
            $table->unsignedBigInteger('product_id')->index();
            $table->string('tag', 16)->nullable();               // other_style | other_colour (null = could not load)
            $table->string('colour_name')->nullable();           // colour shown, from the product's colour list
            $table->string('shows', 24)->nullable();             // whole_product | angle | detail | in_use | packaging | decoration | other
            $table->decimal('confidence', 3, 2)->nullable();
            $table->string('note', 255)->nullable();
            $table->string('model', 64)->nullable();
            $table->timestamp('checked_at');
        });
        Schema::table('product_images', function (Blueprint $table) {
            $table->string('review', 160)->nullable()->after('tagged_by'); // why staff should look (null = fine)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_checks');
        Schema::table('product_images', function (Blueprint $table) {
            $table->dropColumn('review');
        });
    }
};
