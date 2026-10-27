<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The quote desk: Shopify draft orders as received, and the quotes built from
 * them (items, size x qty rows, extra lines). See docs/quotes.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Simple named counters (quote numbers continue from TOD's MR series).
        Schema::create('counters', function (Blueprint $table) {
            $table->string('name', 32)->primary();
            $table->unsignedBigInteger('value');
        });

        // Draft orders exactly as Shopify (or the website quote API) sent them.
        Schema::create('shopify_draft_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary(); // Shopify draft order id
            $table->string('name', 32)->nullable();       // "#D4021"
            $table->string('status', 16)->nullable();
            $table->string('email')->nullable();
            $table->boolean('anonymous')->default(true)->index(); // no email: a wish list
            $table->unsignedInteger('line_count')->default(0);
            $table->longText('payload');
            $table->timestamp('shopify_created_at')->nullable();
            $table->timestamp('shopify_updated_at')->nullable()->index();
            $table->unsignedBigInteger('quote_id')->nullable()->index();
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->string('number', 24)->unique();        // MR11775
            $table->unsignedInteger('revision')->default(1);
            $table->string('kind', 16)->default('quote');  // quote | wishlist
            $table->string('status', 16)->default('NEW')->index();
            $table->string('source', 16)->default('manual'); // shopify | website | manual
            $table->string('title')->nullable();

            $table->unsignedBigInteger('shopify_draft_order_id')->nullable()->unique();
            $table->string('shopify_draft_name', 32)->nullable();
            $table->string('shopify_invoice_url', 1024)->nullable();

            $table->unsignedBigInteger('rod_contact_id')->nullable();
            $table->string('client_company')->nullable();
            $table->string('client_name')->nullable();
            $table->string('client_email')->nullable()->index();
            $table->string('client_phone', 64)->nullable();

            $table->string('delivery_name')->nullable();
            $table->string('delivery_address1')->nullable();
            $table->string('delivery_address2')->nullable();
            $table->string('delivery_city')->nullable();
            $table->string('delivery_state', 32)->nullable();
            $table->string('delivery_postcode', 16)->nullable();
            $table->string('delivery_country', 2)->default('AU');

            $table->string('urgency')->nullable();
            $table->text('client_notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->date('deadline')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('lead_time')->nullable();       // "About 20 working days plus delivery"
            $table->string('decoration_display', 16)->default('combined'); // combined | separate

            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('gst', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('cost_total', 12, 2)->nullable();

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('public_token', 48)->nullable()->unique(); // website wish lists (ActiveWL)
            $table->timestamp('edited_at')->nullable(); // first staff change; Shopify updates stop overwriting items after this
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('won_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // One product as quoted, with its decoration. "stage" records how far it has
        // been split: combined (as received) -> separated -> sku (size x qty entered).
        Schema::create('quote_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained('quotes')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('stage', 16)->default('combined');

            $table->unsignedBigInteger('product_id')->nullable()->index(); // APPAID, null for custom
            $table->unsignedBigInteger('supplier_id')->nullable()->index();
            $table->string('supplier_name')->nullable();
            $table->string('product_code', 64)->nullable();  // supplier code, e.g. CH35
            $table->string('name');
            $table->string('colour')->nullable();
            $table->string('image_url', 1024)->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('quantity');

            $table->unsignedBigInteger('shopify_variant_id')->nullable();
            $table->string('shopify_sku', 128)->nullable();
            $table->decimal('received_unit_price', 12, 4)->nullable(); // the combined price as received

            $table->string('product_option_id')->nullable();
            $table->decimal('product_unit_price', 12, 4)->default(0);
            $table->decimal('product_unit_cost', 12, 4)->nullable();
            $table->decimal('product_setup_price', 12, 4)->default(0);
            $table->decimal('product_setup_cost', 12, 4)->nullable();

            $table->string('decoration_option_id')->nullable();
            $table->unsignedBigInteger('decoration_product_id')->nullable();
            $table->string('decoration_name')->nullable();
            $table->decimal('decoration_unit_price', 12, 4)->default(0);
            $table->decimal('decoration_unit_cost', 12, 4)->nullable();
            $table->decimal('decoration_setup_price', 12, 4)->default(0);
            $table->decimal('decoration_setup_cost', 12, 4)->nullable();

            $table->string('sku_mode', 8)->default('manual');   // list | manual
            $table->json('stock_columns')->nullable();
            $table->timestamp('stock_checked_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('quote_item_skus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_item_id')->constrained('quote_items')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('colour')->nullable();
            $table->string('size', 32)->nullable();
            $table->unsignedInteger('quantity')->default(0);
            $table->string('supplier_sku', 128)->nullable();
            $table->integer('stock_on_hand')->nullable();
            $table->string('source', 8)->default('manual'); // list | manual
            $table->timestamps();
        });

        // Lines that are not catalogue products: custom products, services,
        // freight (auto from supplier settings), information lines.
        Schema::create('quote_extras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained('quotes')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('kind', 16);  // custom | service | freight | info
            $table->string('description', 1024);
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('unit_price', 12, 4)->default(0);
            $table->decimal('unit_cost', 12, 4)->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->string('image_url', 1024)->nullable();
            $table->boolean('auto')->default(false);      // freight from supplier settings
            $table->string('auto_key', 64)->nullable();   // which freight leg an auto line is for
            $table->boolean('overridden')->default(false); // staff changed an auto line's price
            $table->boolean('tbc')->default(false);        // freight not set for that supplier: "to be confirmed"
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_extras');
        Schema::dropIfExists('quote_item_skus');
        Schema::dropIfExists('quote_items');
        Schema::dropIfExists('quotes');
        Schema::dropIfExists('shopify_draft_orders');
        Schema::dropIfExists('counters');
    }
};
