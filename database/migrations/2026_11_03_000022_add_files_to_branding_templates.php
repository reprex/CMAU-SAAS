<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branding templates kept as private files (docs/branding-templates.md): the supplier PDFs staff
 * reviewed and imported, or a copy fetched from the template's address the first time an email
 * needs it. They are attached to quote and invoice emails and never linked from the product page.
 * Quote emails are drafts staff send from the quote page, like the website invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branding_templates', function (Blueprint $table) {
            $table->string('file_path', 500)->nullable()->after('url'); // on the "templates" disk
            $table->string('filename', 190)->nullable()->after('file_path');
            $table->unsignedInteger('bytes')->nullable()->after('filename');
        });
        Schema::table('pending_emails', function (Blueprint $table) {
            $table->foreignId('quote_id')->nullable()->after('website_order_change_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pending_emails', function (Blueprint $table) {
            $table->dropConstrainedForeignId('quote_id');
        });
        Schema::table('branding_templates', function (Blueprint $table) {
            $table->dropColumn(['file_path', 'filename', 'bytes']);
        });
    }
};
