<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shopify publisher (docs/publisher.md): the Shopify ids of each published
 * product and its images, a hash of what was last sent (so unchanged products
 * are skipped), and a log of every publish.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('shopify_product_id')->nullable()->index()->after('published_at');
            $table->char('publish_hash', 40)->nullable()->after('shopify_product_id');
        });
        Schema::create('shopify_media', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->index();
            $table->char('url_hash', 40);
            $table->string('url', 1024);
            $table->string('media_gid', 120);
            $table->timestamps();
            $table->unique(['product_id', 'url_hash']);
        });
        Schema::create('shopify_publish_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->index();
            $table->string('store', 120);
            $table->string('action', 12);            // create | update | archive | skip | preview
            $table->boolean('ok');
            $table->text('message')->nullable();     // Shopify's error text, or a summary
            $table->char('payload_hash', 40)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_publish_log');
        Schema::dropIfExists('shopify_media');
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['shopify_product_id', 'publish_hash']);
        });
    }
};
