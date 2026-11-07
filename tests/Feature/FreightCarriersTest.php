<?php

namespace Tests\Feature;

use App\Freight\CarrierFreightDriver;
use App\Freight\Consignment;
use App\Freight\EstimateFreightDriver;
use App\Freight\FedexFreightDriver;
use App\Freight\MachshipFreightDriver;
use App\Freight\PackData;
use App\Models\Product;
use App\Models\ProductDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

/** Live freight: MachShip in Australia, FedEx overseas, estimates otherwise (docs/freight.md; Scott, 7 Nov 2026). */
class FreightCarriersTest extends TestCase
{
    use QuoteFixtures, RefreshDatabase;

    private function consignment(bool $estimated = false): Consignment
    {
        $pack = ['unit_kg' => 0.19, 'units_per_carton' => 72, 'carton_cm' => [60, 45, 35], 'tare_kg' => 0.8, 'estimated' => $estimated];

        return Consignment::from([['pack' => $pack, 'units' => 110]])->withValue(1710);
    }

    public function test_cartons_are_kept_as_items_for_the_carriers(): void
    {
        $c = $this->consignment();

        $this->assertSame(2, $c->cartons);
        $this->assertSame([['quantity' => 1, 'length' => 60.0, 'width' => 45.0, 'height' => 35.0, 'weight' => 14.48], ['quantity' => 1, 'length' => 60.0, 'width' => 45.0, 'height' => 35.0, 'weight' => 8.02]], $c->items);
    }

    public function test_machship_prices_tnt_for_the_postcode(): void
    {
        config(['freight.machship.token' => 'tok', 'freight.machship.markup_percent' => 10]);
        Http::fake([
            'live.machship.com/apiv2/locations/*' => Http::response(['object' => [['suburb' => 'COLLINGWOOD', 'postcode' => '3066', 'state' => ['code' => 'VIC']]]]),
            'live.machship.com/apiv2/routes/returnroutes' => Http::response(['object' => ['routes' => [
                ['carrier' => ['id' => 11, 'name' => 'TNT'], 'carrierService' => ['id' => 538, 'name' => 'Overnight Express'], 'consignmentTotal' => ['totalSellPrice' => 44.0, 'totalTaxSellPrice' => 4.0, 'totalCostPrice' => 33.0, 'totalTaxCostPrice' => 3.0], 'despatchOptions' => [['totalBusinessDays' => 1]]],
                ['carrier' => ['id' => 11, 'name' => 'TNT'], 'carrierService' => ['id' => 540, 'name' => 'Road Express'], 'consignmentTotal' => ['totalSellBeforeTax' => 20.0, 'totalCostBeforeTax' => 15.0], 'despatchOptions' => [['totalBusinessDays' => 2]]],
                ['carrier' => ['id' => 11, 'name' => 'TNT'], 'carrierService' => ['id' => 999, 'name' => 'Something else'], 'consignmentTotal' => ['totalSellBeforeTax' => 5.0], 'despatchOptions' => [['totalBusinessDays' => 9]]],
                ['carrier' => ['id' => 77, 'name' => 'Capital Transport'], 'carrierService' => ['id' => 701, 'name' => 'Station Wagon'], 'consignmentTotal' => ['totalSellBeforeTax' => 60.0, 'fuelLevySellPrice' => 6.0], 'despatchOptions' => [['totalBusinessDays' => 1]]],
            ]]]),
        ]);

        $r = app(CarrierFreightDriver::class)->quote($this->consignment(), 'AU', '3066');

        $this->assertTrue($r['ok']);
        $this->assertSame('machship', $r['source']);
        $this->assertSame(['tnt_road', 'tnt_overnight', 'capital_wagon'], array_column($r['options'], 'id'));
        $this->assertStringContainsString('fuel', (string) $r['options'][2]['includes']);
        // Cost × 1.10: Road cost 15 → 16.50; Overnight cost 33 - 3 tax = 30 → 33.00.
        $this->assertEquals([16.5, 33.0, 66.0], array_column($r['options'], 'price'), 'cost ex GST + 10%');
        $this->assertEquals(15.0, $r['options'][0]['cost']);
        $this->assertSame([2, 2], $r['options'][0]['days']);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'returnroutes') && $req->header('token')[0] === 'tok'
            && $req['toLocation'] === ['suburb' => 'COLLINGWOOD', 'postcode' => '3066'] && count($req['items']) === 2 && ! isset($req['carrierIds']));
    }

    public function test_machship_down_means_delivery_to_be_confirmed(): void
    {
        config(['freight.machship.token' => 'tok']);
        Http::fake(['live.machship.com/*' => Http::response(['object' => null, 'errors' => [['errorMessage' => 'nope']]], 500)]);

        $r = app(MachshipFreightDriver::class)->quote($this->consignment(), 'AU', '3066');

        $this->assertFalse($r['ok']);
        $this->assertSame([], $r['options']);
    }

    public function test_fedex_prices_priority_and_economy_with_account_rates(): void
    {
        config(['freight.fedex.client_id' => 'id', 'freight.fedex.client_secret' => 'secret', 'freight.fedex.account_number' => '123456789']);
        Http::fake([
            'apis.fedex.com/oauth/token' => Http::response(['access_token' => 'T', 'expires_in' => 3600]),
            'apis.fedex.com/rate/v1/rates/quotes' => Http::response(['output' => ['rateReplyDetails' => [
                ['serviceType' => 'INTERNATIONAL_ECONOMY', 'ratedShipmentDetails' => [['rateType' => 'LIST', 'totalNetCharge' => 300, 'currency' => 'AUD'], ['rateType' => 'ACCOUNT', 'totalNetCharge' => 180.5, 'currency' => 'AUD']], 'commit' => ['transitDays' => ['minimumTransitTime' => 'FIVE_DAYS']]],
                ['serviceType' => 'FEDEX_INTERNATIONAL_PRIORITY', 'ratedShipmentDetails' => [['rateType' => 'ACCOUNT', 'totalNetCharge' => 240, 'currency' => 'AUD']], 'operationalDetail' => ['transitTime' => 'THREE_DAYS']],
                ['serviceType' => 'FEDEX_INTERNATIONAL_CONNECT_PLUS', 'ratedShipmentDetails' => [['rateType' => 'ACCOUNT', 'totalNetCharge' => 100, 'currency' => 'AUD']]],
            ]]]),
        ]);

        $r = app(CarrierFreightDriver::class)->quote($this->consignment(), 'NZ', '1010');

        $this->assertTrue($r['ok']);
        $this->assertTrue($r['export']);
        $this->assertSame('New Zealand', $r['zone']);
        $this->assertSame([['fedex_ie', 180.5, [5, 5]], ['fedex_ip', 240.0, [3, 3]]], array_map(fn ($o) => [$o['id'], $o['price'], $o['days']], $r['options']));
        Http::assertSent(fn ($req) => str_contains($req->url(), 'rates/quotes') && $req['accountNumber']['value'] === '123456789'
            && $req['requestedShipment']['recipient']['address'] === ['postalCode' => '1010', 'countryCode' => 'NZ']
            && $req['requestedShipment']['customsClearanceDetail']['commodities'][0]['customsValue'] == ['amount' => 1710, 'currency' => 'AUD']
            && count($req['requestedShipment']['requestedPackageLineItems']) === 2
            && $req['requestedShipment']['requestedPackageLineItems'][0]['packageSpecialServices']['signatureOptionType'] === 'ADULT');
    }

    public function test_without_keys_the_estimate_is_used(): void
    {
        config(['freight.machship.token' => null, 'freight.fedex.client_id' => null]);
        Http::fake();

        $this->assertSame('estimate', app(CarrierFreightDriver::class)->quote($this->consignment(), 'AU', '3066')['source']);
        $this->assertSame('estimate', app(CarrierFreightDriver::class)->quote($this->consignment(), 'NZ', '1010')['source']);
        Http::assertNothingSent();
    }

    public function test_a_weight_missing_from_promodata_comes_from_similar_products_and_is_an_estimate(): void
    {
        $this->supplier(7, 'AS Colour');
        $mk = function (int $id, ?string $weight) {
            $p = $this->catalogueProduct($id, 7, "Tee {$id}", "T{$id}");
            $p->update(['promodata_type_name' => 'Shirts > T-Shirts']);
            ProductDetail::query()->updateOrCreate(['product_id' => $id], ['details' => $weight ? [['name' => 'Item Weight', 'detail' => $weight]] : [['name' => 'Carton Quantity', 'detail' => '50']]]);

            return $p->fresh();
        };
        $mk(1, '200g');
        $mk(2, '180g');
        $mk(3, '220g');
        $none = $mk(4, null);

        $pack = app(PackData::class)->for($none);

        $this->assertTrue($pack['estimated']);
        $this->assertSame('similar', $pack['source']);
        $this->assertEquals(0.2, $pack['unit_kg']);
        $this->assertSame(50, $pack['units_per_carton']);
        $this->assertFalse(app(PackData::class)->for(Product::find(1))['estimated']);
        $this->assertTrue(Consignment::from([['pack' => $pack, 'units' => 10]])->estimated);
    }

    public function test_estimate_driver_flags_estimated_weights(): void
    {
        $this->assertTrue((new EstimateFreightDriver)->quote($this->consignment(true), 'AU', '3066')['estimated']);
        $this->assertFalse((new FedexFreightDriver)->configured());
    }
}
