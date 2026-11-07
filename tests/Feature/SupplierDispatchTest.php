<?php

namespace Tests\Feature;

use App\Freight\CarrierFreightDriver;
use App\Freight\Consignment;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

/** Delivery priced from the supplier's dispatch address; Promodata never overwrites a SaaS value (Scott, 7 Nov 2026). */
class SupplierDispatchTest extends TestCase
{
    use QuoteFixtures, RefreshDatabase;

    public function test_machship_prices_from_the_supplier_dispatch_address(): void
    {
        config(['freight.machship.token' => 'tok']);
        Http::fake([
            'live.machship.com/apiv2/locations/*' => Http::response(['object' => [['suburb' => 'COLLINGWOOD', 'postcode' => '3066', 'state' => ['code' => 'VIC']]]]),
            'live.machship.com/apiv2/routes/returnroutes' => Http::response(['object' => ['routes' => [
                ['carrier' => ['id' => 11, 'name' => 'TNT'], 'carrierService' => ['id' => 540, 'name' => 'Road Express'], 'consignmentTotal' => ['totalSellBeforeTax' => 20.0], 'despatchOptions' => [['totalBusinessDays' => 2]]],
            ]]]),
        ]);
        $s = $this->supplier(7, 'Sydney Supplier');
        $s->update(['state' => 'NSW', 'dispatch_suburb' => 'Alexandria', 'dispatch_postcode' => '2015']);
        $pack = ['unit_kg' => 0.19, 'units_per_carton' => 72, 'carton_cm' => [60, 45, 35], 'tare_kg' => 0.8];
        $c = Consignment::from([['pack' => $pack, 'units' => 50]])->withOrigin($s->fresh()->dispatchOrigin());

        $this->assertTrue(app(CarrierFreightDriver::class)->quote($c, 'AU', '3066')['ok']);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'returnroutes') && $req['fromLocation'] === ['suburb' => 'ALEXANDRIA', 'postcode' => '2015']);
    }

    public function test_promodata_fills_only_empty_dispatch_addresses(): void
    {
        config(['promodata.token' => 't']);
        $this->supplier(1, 'Empty One');
        $kept = $this->supplier(2, 'Edited By Staff');
        $kept->update(['dispatch_suburb' => 'DERRIMUT', 'dispatch_postcode' => '3026', 'dispatch_source' => 'staff']);
        Http::fake(['*/suppliers' => Http::response(['data' => [
            ['id' => 1, 'name' => 'Empty One', 'address' => ['suburb' => 'Brisbane', 'postcode' => '4000']],
            ['id' => 2, 'name' => 'Edited By Staff', 'address' => ['suburb' => 'Perth', 'postcode' => '6000']],
        ]])]);

        $this->artisan('promodata:supplier-addresses --apply')->expectsOutputToContain('Filled 1.')->assertSuccessful();

        $this->assertSame(['BRISBANE', '4000', 'promodata'], array_values(Supplier::find(1)->only(['dispatch_suburb', 'dispatch_postcode', 'dispatch_source'])));
        $this->assertSame(['DERRIMUT', '3026', 'staff'], array_values(Supplier::find(2)->only(['dispatch_suburb', 'dispatch_postcode', 'dispatch_source'])));
    }
}
