<?php

namespace Tests\Feature;

use App\Catalogue\ProductHydrator;
use App\Catalogue\TierManager;
use App\Models\PriceLine;
use App\Models\Product;
use App\Models\ProductOverride;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\PromodataFixtures;
use Tests\TestCase;

class CatalogueTierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['promodata.token' => 'test-token', 'promodata.base_url' => 'https://api.promodata.test']);
    }

    public function test_hydrating_a_summary_product_fetches_full_detail_and_makes_it_active(): void
    {
        Http::fake(['api.promodata.test/products/1001' => Http::response(['data' => PromodataFixtures::product(1001)])]);
        Supplier::create(['id' => 3, 'name' => 'Test Supplier Pty Ltd']);
        Product::create(['id' => 1001, 'supplier_id' => 3, 'name' => 'Summary only', 'tier' => Product::TIER_SUMMARY]);

        $product = app(ProductHydrator::class)->hydrate(1001);

        $this->assertSame(Product::TIER_ACTIVE, $product->tier);
        $this->assertNotNull($product->last_used_at);
        $this->assertNotNull($product->hydrated_at);
        $this->assertSame(2, $product->colours()->count());
        $this->assertSame(2, PriceLine::count());
        $this->assertSame('TESTBRAND', $product->detail->supplier_brand);
    }

    public function test_hydrating_an_unknown_product_returns_null(): void
    {
        Http::fake(['api.promodata.test/products/999' => Http::response(['message' => 'Not found'], 404)]);

        $this->assertNull(app(ProductHydrator::class)->hydrate(999));
    }

    public function test_hydrating_a_live_product_keeps_it_live(): void
    {
        Http::fake(['api.promodata.test/products/1001' => Http::response(['data' => PromodataFixtures::product(1001)])]);
        Supplier::create(['id' => 3, 'name' => 'Test Supplier Pty Ltd']);
        Product::create(['id' => 1001, 'supplier_id' => 3, 'name' => 'Live', 'tier' => Product::TIER_LIVE]);

        $this->assertSame(Product::TIER_LIVE, app(ProductHydrator::class)->hydrate(1001)->tier);
    }

    public function test_stale_active_products_drop_to_summary_but_keep_overrides(): void
    {
        Http::fake(['api.promodata.test/products/*' => Http::response(['data' => PromodataFixtures::product(1001)])]);
        Supplier::create(['id' => 3, 'name' => 'Test Supplier Pty Ltd']);
        Product::create(['id' => 1001, 'supplier_id' => 3, 'name' => 'x']);
        app(ProductHydrator::class)->hydrate(1001);
        ProductOverride::create(['product_id' => 1001, 'data' => ['move_subcategory' => 'plasticpens']]);

        // Recently used: stays active.
        $this->assertSame(0, app(TierManager::class)->demoteStale());

        // Unused for longer than the window: demoted.
        Product::whereKey(1001)->update(['last_used_at' => now()->subMonths(13)]);
        $this->assertSame(1, app(TierManager::class)->demoteStale());

        $product = Product::find(1001);
        $this->assertSame(Product::TIER_SUMMARY, $product->tier);
        $this->assertNull($product->hydrated_at);
        $this->assertSame(0, $product->colours()->count());
        $this->assertSame(0, PriceLine::count());
        $this->assertNull($product->detail);
        $this->assertSame('plasticpens', $product->override->data['move_subcategory']);
        $this->assertSame('TP1001', $product->code, 'The summary row is kept.');
    }

    public function test_live_products_are_never_demoted(): void
    {
        Supplier::create(['id' => 3, 'name' => 'Test Supplier Pty Ltd']);
        Product::create(['id' => 1001, 'supplier_id' => 3, 'name' => 'x', 'tier' => Product::TIER_LIVE, 'last_used_at' => now()->subYears(3)]);

        $this->assertSame(0, app(TierManager::class)->demoteStale());
        $this->assertSame(Product::TIER_LIVE, Product::find(1001)->tier);
    }
}
