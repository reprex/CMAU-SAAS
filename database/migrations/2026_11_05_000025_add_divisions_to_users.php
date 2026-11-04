<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Access per business division (Scott, 5 Nov 2026; docs/divisions.md): {"replicat": "admin",
 * "crowdmerch": "staff", "crowdlink": null, "shared": "view"}. Existing users keep what they had:
 * admins get admin everywhere; sales and read-only get CrowdMerch and the shared modules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('divisions')->nullable()->after('role');
        });
        foreach (DB::table('users')->get(['id', 'role']) as $u) {
            $level = ['admin' => 'admin', 'sales' => 'staff'][$u->role] ?? 'view';
            $all = $u->role === 'admin';
            DB::table('users')->where('id', $u->id)->update(['divisions' => json_encode([
                'replicat' => $all ? 'admin' : null, 'crowdmerch' => $level, 'crowdlink' => $all ? 'admin' : null, 'shared' => $level,
            ])]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('divisions');
        });
    }
};
