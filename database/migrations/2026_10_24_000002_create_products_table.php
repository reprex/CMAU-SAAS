<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per supplier product, in every tier. This is the compact "summary"
 * record kept for all ~64k Promodata products. Full detail (colours, images,
 * price lines) lives in child tables and is kept only for live/active products.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            // APPAID / Promodata product id (also the Shopify handle). External
            // (non-Promodata) products use ids above 10,000,000, as in TOD.
            $table->unsignedBigInteger('id')->primary();
            $table->string('source', 32)->default('promodata');
            $table->unsignedBigInteger('supplier_id')->index();

            $table->string('code')->nullable();
            $table->string('name');
            $table->string('tier', 16)->default('summary')->index(); // summary | active | live

            $table->boolean('discontinued')->default(false)->index();
            $table->boolean('can_check_stock')->default(false);
            $table->string('country', 8)->nullable();

            $table->string('supplier_category')->nullable();
            $table->string('supplier_subcategory')->nullable();
            $table->string('promodata_type_id', 32)->nullable()->index();
            $table->string('promodata_type_name')->nullable();

            $table->string('hero_image_url', 1024)->nullable(); // public URL only, never a file
            $table->unsignedInteger('colour_count')->default(0);
            $table->decimal('min_unit_cost', 12, 4)->nullable();
            $table->unsignedInteger('min_qty')->nullable();

            $table->timestamp('first_listed_at')->nullable();
            $table->timestamp('last_changed_at')->nullable()->index();
            $table->timestamp('prices_changed_at')->nullable();
            $table->timestamp('discontinued_at')->nullable();

            // sha1 of the source payload, so unchanged products are skipped.
            $table->char('content_hash', 40)->nullable();
            $table->timestamp('hydrated_at')->nullable();   // full detail last stored
            $table->timestamp('last_used_at')->nullable();  // quoted / opened / added
            $table->timestamps();

            $table->index(['tier', 'last_used_at']);
        });

        Schema::create('product_details', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->primary();
            $table->longText('description')->nullable();
            $table->string('supplier_brand')->nullable();
            $table->string('supplier_label')->nullable();
            $table->text('supplier_catalogue')->nullable();
            $table->string('supplier_website_page', 1024)->nullable();
            $table->json('details')->nullable();              // [{name, detail}]
            $table->json('promodata_attributes')->nullable(); // [{name, value}]
            $table->json('appa_product_types')->nullable();
            $table->json('colour_notes')->nullable();         // colours.supplier_text
            $table->json('price_tags')->nullable();
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });

        Schema::create('product_colours', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->index();
            $table->unsignedInteger('position')->default(0);
            $table->string('applies_to')->nullable();          // colours.list[].for
            $table->string('name');
            $table->string('image_url', 1024)->nullable();
            $table->json('swatch_urls')->nullable();
            $table->json('supplier_colours')->nullable();
            $table->json('appa_colours')->nullable();
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->index();
            $table->string('url', 1024);                      // public URL only
            $table->string('role', 16)->default('style');     // hero | style | colour | alternate | line_art | video
            $table->unsignedInteger('position')->default(0);
            $table->boolean('hide_web')->default(false);
            $table->boolean('hide_quote')->default(false);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });

        // Supplier price lines (Promodata price_groups: one base price plus additions).
        Schema::create('price_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->index();
            $table->foreignId('base_price_line_id')->nullable()->constrained('price_lines')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('source_key')->nullable();
            $table->string('type', 64)->nullable();
            $table->string('description', 1024)->nullable();
            $table->decimal('setup_cost', 12, 4)->default(0);
            $table->string('currency', 8)->default('AUD');
            $table->string('lead_time')->nullable();
            $table->boolean('undecorated')->default(false);
            $table->boolean('indent')->default(false);
            $table->string('promodata_decoration')->nullable();
            $table->json('tags')->nullable();
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });

        Schema::create('price_breaks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_line_id')->constrained('price_lines')->cascadeOnDelete();
            $table->unsignedInteger('qty');
            $table->decimal('unit_cost', 12, 4);

            $table->index(['price_line_id', 'qty']);
        });

        // Staff overrides survive a product dropping back to the summary tier.
        Schema::create('product_overrides', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->primary();
            $table->json('data');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_overrides');
        Schema::dropIfExists('price_breaks');
        Schema::dropIfExists('price_lines');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('product_colours');
        Schema::dropIfExists('product_details');
        Schema::dropIfExists('products');
    }
};
