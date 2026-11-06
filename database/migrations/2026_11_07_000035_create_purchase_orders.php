<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier purchase orders (Scott, 7 Nov 2026; docs/purchase-orders.md): from a website order, at supplier
 * cost, blind shipped to the client, with the deadline, the event and a link to the client's artwork.
 * Freight is left out until the supplier gives the actual cost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('orders_contact', 120)->nullable();
            $table->string('orders_email', 190)->nullable();
            $table->string('our_account_number', 60)->nullable();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 24)->unique();               // PO-CM-10001
            $table->foreignId('website_order_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('supplier_id')->index();
            $table->string('status', 10)->default('draft');        // draft | sent
            $table->json('deliver_to');                             // name, organisation, address, phone
            $table->date('need_by')->nullable();                    // the client's date
            $table->date('despatch_by')->nullable();                // when it must leave the supplier
            $table->string('event', 255)->nullable();
            $table->json('lines');                                  // sizes and colours at cost
            $table->json('branding');                               // positions at cost
            $table->json('extras')->nullable();                     // options at cost
            $table->decimal('goods_cost', 12, 2)->default(0);
            $table->decimal('branding_cost', 12, 2)->default(0);
            $table->decimal('setup_cost', 12, 2)->default(0);
            $table->decimal('freight_cost', 12, 2)->nullable();     // the supplier's actual freight, added later
            $table->decimal('total_cost', 12, 2)->default(0);
            $table->boolean('costs_complete')->default(true);       // false: a line had no cost in the catalogue
            $table->text('notes')->nullable();
            $table->string('artwork_token', 48)->unique();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('pending_emails', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')->nullable()->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pending_emails', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_id');
        });
        Schema::dropIfExists('purchase_orders');
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn(['orders_contact', 'orders_email', 'our_account_number']);
        });
    }
};
