<?php

namespace Tests\Feature;

use App\Catalogue\SupplierCodes;
use App\Models\Product;
use App\Models\ProductOverride;
use App\Models\ProductSku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class SupplierCodesTest extends TestCase
{
    use QuoteFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['promodata.token' => 'test-token', 'promodata.base_url' => 'https://api.promodata.test', 'quotes.stock_relay.allowed_origins' => []]);
        $this->supplier(88, 'AS Colour');
    }

    private function tee(): Product
    {
        return Product::create(['id' => 43446, 'supplier_id' => 88, 'name' => 'Staple Tee', 'code' => '5001', 'tier' => 'live', 'can_check_stock' => true, 'hydrated_at' => now()]);
    }

    private function fakeStock(array $rows): void
    {
        Http::fake(['api.promodata.test/products/*/check-stock-levels' => Http::response(['data' => ['stock' => $rows]])]);
    }

    public function test_stock_checks_store_the_skus_and_mark_the_product_for_publishing(): void
    {
        $p = $this->tee();
        $this->fakeStock([['Black', 'M', 3980, '5001-BLK-M'], ['Black', 'L', 4120, '5001-BLK-L'], ['Navy', 'M', 0, '5001-NVY-M']]);

        $this->get('/stock.php?id=43446')->assertOk();

        $this->assertSame(['5001-BLK-M', '5001-BLK-L', '5001-NVY-M'], ProductSku::query()->orderBy('position')->pluck('sku')->all());
        $p->refresh();
        $this->assertNotNull($p->skus_checked_at);
        $this->assertNotNull($p->range_changed_at);

        ProductOverride::query()->create(['product_id' => $p->id, 'data' => ['hidden_colours' => ['Navy']]]);
        $this->assertSame([
            'supplier_code' => 'AS Colour 5001',
            'skus' => [['colour' => 'Black', 'size' => 'M', 'sku' => '5001-BLK-M'], ['colour' => 'Black', 'size' => 'L', 'sku' => '5001-BLK-L']],
        ], app(SupplierCodes::class)->forShopify($p->fresh(['override'])));
    }

    public function test_quantity_changes_alone_do_not_mark_for_publishing(): void
    {
        $p = $this->tee();
        app(SupplierCodes::class)->record($p->id, [['colour' => 'Black', 'size' => 'M', 'qty' => 5, 'sku' => '5001-BLK-M']]);
        $p->forceFill(['range_changed_at' => null])->save();
        app(SupplierCodes::class)->record($p->id, [['colour' => 'Black', 'size' => 'M', 'qty' => 9, 'sku' => '5001-BLK-M']]);
        $this->assertNull($p->fresh()->range_changed_at);
    }

    public function test_range_screen_shows_live_stock_with_skus(): void
    {
        $p = $this->tee();
        $this->fakeStock([['Black', 'M', 3980, '5001-BLK-M'], ['Black', 'L', 4120, '5001-BLK-L']]);
        $this->staff('sales');
        $this->get(route('range.show', $p))->assertOk()->assertSee('Live stock');
        $this->get(route('range.stock.live', $p))->assertOk()->assertSee('5001-BLK-L')->assertSee('3,980')->assertSee('8,100');
        $this->assertNull(ProductSku::query()->value('qty')); // stock levels are never stored
    }
}
