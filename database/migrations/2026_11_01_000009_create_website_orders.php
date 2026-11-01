<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website checkout (CMAUDEV-CHECKOUT, docs/website-checkout.md): direct orders of
 * one product and its related styles, My Orders sign-in codes, change requests,
 * client emails waiting for staff, and the public holidays business days skip.
 * Plain types only (no JSON defaults, no generated columns) so it runs on
 * MariaDB 10.11 as well as SQLite in the tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            // State whose public holidays the supplier (and our team) skip; stock lead time in business days.
            $table->string('state', 3)->default('VIC')->after('country');
            $table->unsignedTinyInteger('stock_lead_days')->nullable()->after('state');
            // How website orders from this supplier are paid: "full" now; deposit terms later (e.g. Trends).
            $table->string('payment_terms', 16)->default('full')->after('stock_lead_days');
        });

        Schema::create('public_holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('state', 3); // VIC, NSW, QLD, SA, WA, TAS, NT, ACT
            $table->string('name', 120);
            $table->timestamps();
            $table->unique(['state', 'date']);
        });

        // Staff-vetted related styles: a womens or kids version of the same product, same supplier.
        Schema::create('related_styles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->index();
            $table->unsignedBigInteger('related_product_id');
            $table->string('label', 60)->nullable(); // "Womens fit", "Kids sizes"
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'related_product_id']);
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('related_product_id')->references('id')->on('products')->cascadeOnDelete();
        });

        Schema::create('website_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 16)->unique(); // CM-xxxxx
            $table->string('email', 190)->index();  // lower case; My Orders is scoped by it
            $table->string('contact_name', 120);
            $table->string('organisation', 160)->nullable();
            $table->string('phone', 40);
            $table->string('address_unit', 60)->nullable();
            $table->string('address_street', 190);
            $table->string('address_suburb', 120);
            $table->string('address_state', 60)->nullable();
            $table->string('address_postcode', 16);
            $table->char('address_country', 2)->default('AU');
            $table->date('need_by');
            $table->string('event_name', 160)->nullable();
            $table->date('event_date')->nullable();
            $table->string('event_place', 160)->nullable();

            $table->unsignedBigInteger('product_id')->index();   // the main product
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->string('payment_terms', 16)->default('full');

            $table->string('freight_service', 32);  // tnt_road, tnt_overnight, fedex_ip, fedex_ie
            $table->string('freight_name', 80);
            $table->text('freight_breakdown')->nullable(); // JSON: cartons, kg, m3, chargeable kg
            $table->date('eta');
            $table->text('eta_breakdown')->nullable();     // JSON: stages and holidays skipped
            $table->boolean('export')->default(false);     // delivered outside Australia: GST-free

            $table->decimal('goods', 12, 2);
            $table->decimal('setup', 12, 2);
            $table->decimal('freight', 12, 2);
            $table->decimal('subtotal', 12, 2);
            $table->decimal('gst', 12, 2);
            $table->decimal('total', 12, 2);
            $table->text('alerts')->nullable();            // JSON: pre-payment alerts on the invoice

            $table->unsignedTinyInteger('stage')->default(0); // index into WebsiteOrder::STAGES
            $table->timestamp('stage_changed_at')->nullable();
            $table->string('carrier', 16)->nullable();     // TNT | FedEx
            $table->string('consignment', 60)->nullable();
            $table->date('delivered_on')->nullable();

            $table->string('invoice_path', 255)->nullable(); // private disk
            $table->timestamp('invoice_sent_at')->nullable();
            $table->timestamp('proof_acknowledged_at')->nullable();
            $table->char('ip_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['stage', 'need_by']);
        });

        Schema::create('website_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_order_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->string('product_code', 60)->nullable();
            $table->string('product_name', 255);
            $table->string('colour', 120);
            $table->string('size', 60);
            $table->string('supplier_sku', 120)->nullable();
            $table->unsignedInteger('qty');
            $table->decimal('unit_ex_gst', 12, 2);
            $table->decimal('total_ex_gst', 12, 2);
            $table->integer('stock_at_order')->nullable(); // what the live check said, for the record
            $table->timestamps();
        });

        Schema::create('website_order_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_order_id')->constrained()->cascadeOnDelete();
            $table->string('position', 60);   // Front centre, Back...
            $table->string('method', 20);     // dtf, dtf_oversize, screen, pad, embroidery
            $table->string('decoration_option_id')->nullable();
            $table->string('decoration_name', 255)->nullable();
            $table->unsignedTinyInteger('colours')->nullable();
            $table->unsignedSmallInteger('width_mm')->nullable();
            $table->decimal('unit_ex_gst', 12, 2);
            $table->decimal('setup_ex_gst', 12, 2);
            $table->string('logo_path', 255)->nullable(); // private artwork disk, never a public URL
            $table->string('logo_name', 190)->nullable();
            $table->string('logo_mime', 100)->nullable();
            $table->timestamps();
        });

        Schema::create('website_order_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_order_id')->constrained()->cascadeOnDelete();
            $table->text('message');
            $table->unsignedTinyInteger('stage_at_request');
            $table->timestamp('handled_at')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // My Orders one-time codes: hashed, 10 minutes, 5 attempts.
        Schema::create('account_codes', function (Blueprint $table) {
            $table->id();
            $table->string('email', 190)->index();
            $table->char('code_hash', 64);
            $table->string('via', 8); // email | sms
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        // Client emails prepared by the app. REPSTACK principle 6: staff send them
        // (one click) unless website.orders.auto_send_invoice is switched on.
        Schema::create('pending_emails', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 24); // invoice | change_request
            $table->foreignId('website_order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('website_order_change_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('recipient', 190);
            $table->string('subject', 190);
            $table->string('status', 10)->default('draft'); // draft | queued | sent | failed
            $table->string('error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_emails');
        Schema::dropIfExists('account_codes');
        Schema::dropIfExists('website_order_changes');
        Schema::dropIfExists('website_order_positions');
        Schema::dropIfExists('website_order_lines');
        Schema::dropIfExists('website_orders');
        Schema::dropIfExists('related_styles');
        Schema::dropIfExists('public_holidays');
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn(['state', 'stock_lead_days', 'payment_terms']);
        });
    }
};
