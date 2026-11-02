<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Quotes and orders made for a client from the website's cart page by staff (Scott, 3 Nov 2026;
 * docs/website-staff.md): logo files kept with the quote, and who placed a website order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->json('artwork')->nullable()->after('internal_notes'); // [{position, path, name, size}] on the private artwork disk
        });
        Schema::table('website_orders', function (Blueprint $table) {
            $table->foreignId('placed_by')->nullable()->after('email')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('website_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('placed_by');
        });
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn('artwork');
        });
    }
};
