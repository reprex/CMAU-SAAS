<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sign-in (Scott, 6 Nov 2026): a passkey (Face ID) on a phone instead of password and code; on a computer,
 * password and code, with "trust this device for 30 days" so the code is asked once a month.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passkeys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('credential_id', 512)->unique();   // base64url
            $table->text('public_key');                        // PEM
            $table->unsignedBigInteger('sign_count')->default(0);
            $table->string('name', 120)->nullable();           // the device it was made on
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
        Schema::create('trusted_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();          // sha256 of the cookie token
            $table->string('name', 120)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trusted_devices');
        Schema::dropIfExists('passkeys');
    }
};
