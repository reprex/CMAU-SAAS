<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Every step 1 search: what was asked, which checks passed, what was shown. No names, emails or raw IPs. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_logs', function (Blueprint $table) {
            $table->id();
            $table->char('visitor', 16)->index();          // hashed IP, for counting only
            $table->string('query', 400);
            $table->string('mode', 16)->index();            // keyword | ai | cached | blocked
            $table->string('reason', 40)->nullable();       // why AI was not used, or why blocked
            $table->boolean('agent')->default(false);       // Cloudflare-verified bot or signed agent
            $table->json('checks')->nullable();
            $table->json('result_ids')->nullable();
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->unsignedInteger('ms')->default(0);
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_logs');
    }
};
