<?php

namespace Tests\Feature;

use App\Models\PriceLine;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

/** docs/lead-times.md, step 1: what Promodata says, per supplier and decoration (read-only). */
class LeadTimeReportTest extends TestCase
{
    use QuoteFixtures, RefreshDatabase;

    public function test_the_report_shows_lead_times_per_supplier_and_method(): void
    {
        $this->supplier(5, 'Trends');
        $p = Product::create(['id' => 9001, 'supplier_id' => 5, 'name' => 'Pen', 'code' => 'T1', 'tier' => 'live', 'hydrated_at' => now()]);
        DB::table('product_details')->insert(['product_id' => $p->id, 'details' => json_encode([['name' => 'Production', 'detail' => 'Lead time 10 working days from proof approval']])]);
        $base = PriceLine::create(['product_id' => $p->id, 'description' => 'Unbranded', 'lead_time' => '3 days', 'undecorated' => true]);
        PriceLine::create(['product_id' => $p->id, 'base_price_line_id' => $base->id, 'description' => 'Pad print 1 colour', 'lead_time' => '7-10 working days']);
        PriceLine::create(['product_id' => $p->id, 'base_price_line_id' => $base->id, 'description' => 'Laser engraving', 'lead_time' => '2 weeks']);
        PriceLine::create(['product_id' => $p->id, 'base_price_line_id' => $base->id, 'description' => 'Screen print', 'lead_time' => 'ASAP']);

        $this->artisan('catalogue:lead-time-report', ['--supplier' => 5])
            ->expectsOutputToContain('Trends (5): 1 products · 1 with a lead time (100%) · base 1 · decoration lines 3 of 3 · borrowed 0 · details 1')
            ->expectsOutputToContain('pad 10')
            ->expectsOutputToContain('"ASAP" → NOT READ')
            ->assertSuccessful();
    }
}
