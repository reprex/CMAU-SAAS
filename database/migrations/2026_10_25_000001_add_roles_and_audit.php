<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff roles (admin, sales, read-only), two-factor sign-in and an audit log
 * of every change (docs/stack-and-security.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 16)->default('sales')->after('password');
            $table->boolean('active')->default(true)->after('role');
            $table->text('two_factor_secret')->nullable()->after('active'); // encrypted
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_secret');
            $table->timestamp('last_login_at')->nullable()->after('two_factor_confirmed_at');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->string('subject_type', 64)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->json('changes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'active', 'two_factor_secret', 'two_factor_confirmed_at', 'last_login_at']);
        });
    }
};
