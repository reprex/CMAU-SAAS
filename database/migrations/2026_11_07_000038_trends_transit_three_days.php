<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Trends transit NZ -> Australian client from two real FedEx shipments (Scott, 7 Nov 2026): label Thu,
 * pickup Fri Christchurch, delivered Mon in Dandenong South (2 working days) and Tue in Lismore Heights
 * (3). 3 working days from dispatch covers metro and regional. Only changes the earlier default of 4.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('suppliers')->where('name', 'Trends')->where('transit_days', 4)->update(['transit_days' => 3, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('suppliers')->where('name', 'Trends')->where('transit_days', 3)->update(['transit_days' => 4]);
    }
};
