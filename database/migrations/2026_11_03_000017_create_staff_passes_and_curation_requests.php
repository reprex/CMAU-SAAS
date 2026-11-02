<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Staff tools on the website (Scott, 3 Nov 2026; docs/website-staff.md).
 * website_staff_passes: short-lived passes the website gets after a SaaS sign-in (with the
 * two-step code) in a pop-up; stored as a hash. curation_requests: range changes asked for
 * from the product page; an admin's apply straight away, others wait for approval.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_staff_passes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->json('scopes');
            $table->string('origin', 190);
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('curation_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('edits');                            // field => new value (null = back to automatic)
            $table->json('before')->nullable();               // the values when asked, for the record
            $table->text('note')->nullable();
            $table->string('status', 16)->default('pending')->index(); // pending, approved, applied, rejected, failed
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->string('publish_result', 300)->nullable();
            $table->string('error', 500)->nullable();
            $table->string('source_url', 300)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curation_requests');
        Schema::dropIfExists('website_staff_passes');
    }
};
