<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Website project requests (Scott, 3 Nov 2026): the "Design something" survey, rebuilt from
 * forms.app. The client gets a confirmation email with their request and a reply-by date
 * one working day out; staff see them under Project requests (docs/website-project-requests.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_requests', function (Blueprint $table) {
            $table->id();
            $table->string('number', 16)->unique();            // PR-10001
            $table->text('brief');
            $table->json('kinds')->nullable();                 // what they're after: garments, giftware…
            $table->string('quantity', 120);
            $table->string('budget', 60)->nullable();
            $table->date('need_by')->nullable();
            $table->string('event', 160)->nullable();
            $table->string('first_name', 80);
            $table->string('last_name', 80)->nullable();
            $table->string('organisation', 160)->nullable();
            $table->string('email', 190)->index();
            $table->string('phone', 40);
            $table->string('contact_by', 16)->nullable();      // email or phone
            $table->json('files')->nullable();                 // [{path, name, size}] on the private artwork disk
            $table->date('reply_by');
            $table->string('status', 16)->default('new')->index(); // new, replied, closed
            $table->text('staff_notes')->nullable();
            $table->timestamp('confirmation_sent_at')->nullable();
            $table->timestamp('staff_notified_at')->nullable();
            $table->string('email_error', 500)->nullable();
            $table->string('source_url', 300)->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_requests');
    }
};
