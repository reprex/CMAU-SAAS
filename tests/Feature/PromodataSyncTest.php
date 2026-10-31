<?php

namespace Tests\Feature;

use App\Catalogue\SyncRunner;
use App\Models\PriceLine;
use App\Models\Product;
use App\Models\ProductColour;
use App\Models\ProductImage;
use App\Models\Supplier;
use App\Models\SyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\PromodataFixtures;
use Tests\TestCase;

class PromodataSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['promodata.token' => 'test-token', 'promodata.base_url' => 'https://api.promodata.test']);
    }

    private function fakeTwoPages(): void
    {
        Http::fake([
            'api.promodata.test/products?page=1' => Http::response(PromodataFixtures::page([
                PromodataFixtures::product(1001), PromodataFixtures::product(1002),
            ], totalPages: 2)),
            'api.promodata.test/products?page=2' => Http::response(PromodataFixtures::page([
                PromodataFixtures::product(1003),
            ], totalPages: 2)),
        ]);
    }

    public function test_a_full_sync_stores_compact_summary_rows_only(): void
    {
        $this->fakeTwoPages();
        $runner = app(SyncRunner::class);
        $runner->start(SyncRun::KIND_FULL);

        $run = $runner->step(maxPages: 5);

        $this->assertSame(SyncRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(3, $run->products_seen);
        $this->assertSame(3, $run->products_changed);
        $this->assertSame(3, Product::count());

        $product = Product::find(1001);
        $this->assertSame(Product::TIER_SUMMARY, $product->tier);
        $this->assertSame('TP1001', $product->code);
        $this->assertSame('0.4100', $product->min_unit_cost);
        $this->assertSame(250, $product->min_qty);
        $this->assertSame(2, $product->colour_count);
        $this->assertStringStartsWith('https://', $product->hero_image_url);
        $this->assertNull($product->hydrated_at);

        // No detail rows for summary-tier products.
        $this->assertSame(0, ProductColour::count());
        $this->assertSame(0, ProductImage::count());
        $this->assertSame(0, PriceLine::count());
    }

    public function test_sync_runs_in_small_steps_and_remembers_its_page(): void
    {
        $this->fakeTwoPages();
        $runner = app(SyncRunner::class);
        $runner->start(SyncRun::KIND_FULL);

        $run = $runner->step(maxPages: 1);
        $this->assertSame(SyncRun::STATUS_RUNNING, $run->status);
        $this->assertSame(2, $run->next_page);
        $this->assertSame(2, Product::count());

        $run = $runner->step(maxPages: 1);
        $this->assertSame(SyncRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(3, Product::count());

        $this->assertNull($runner->step(maxPages: 1), 'No open run left to process.');
    }

    public function test_new_suppliers_get_default_markups_and_existing_markups_are_kept(): void
    {
        Supplier::create(['id' => 3, 'name' => 'Old name', 'markup_decoration' => 1.65]);
        $this->fakeTwoPages();
        $runner = app(SyncRunner::class);
        $runner->start(SyncRun::KIND_FULL);
        $runner->step(5);

        $supplier = Supplier::find(3);
        $this->assertSame('Test Supplier Pty Ltd', $supplier->name);
        $this->assertSame('1.6500', $supplier->markup_decoration, 'Staff markups must not be overwritten.');
    }

    public function test_unchanged_products_are_skipped_on_the_next_sync(): void
    {
        $this->fakeTwoPages();
        $runner = app(SyncRunner::class);
        $runner->start(SyncRun::KIND_FULL);
        $runner->step(5);

        $runner->start(SyncRun::KIND_FULL);
        $run = $runner->step(5);

        $this->assertSame(3, $run->products_seen);
        $this->assertSame(0, $run->products_changed);
    }

    public function test_live_and_active_products_keep_full_detail_on_sync(): void
    {
        $this->fakeTwoPages();
        Supplier::create(['id' => 3, 'name' => 'Test Supplier Pty Ltd']);
        Product::create(['id' => 1001, 'supplier_id' => 3, 'name' => 'Old', 'tier' => Product::TIER_LIVE]);

        $runner = app(SyncRunner::class);
        $runner->start(SyncRun::KIND_FULL);
        $runner->step(5);

        $product = Product::find(1001);
        $this->assertSame(Product::TIER_LIVE, $product->tier);
        $this->assertNotNull($product->hydrated_at);
        $this->assertSame(2, $product->colours()->count());
        $this->assertSame(['hero_style', 'other_style', 'colour', 'colour'], $product->images()->pluck('role')->all());
        $this->assertSame(2, $product->priceLines()->count());

        $base = $product->priceLines()->whereNull('base_price_line_id')->first();
        $this->assertSame([250, 500, 1000], $base->breaks()->pluck('qty')->all());
        $addition = $product->priceLines()->whereNotNull('base_price_line_id')->first();
        $this->assertSame($base->id, $addition->base_price_line_id, 'Add-on lines link to their base line.');

        // Images are stored as public URLs only.
        foreach (ProductImage::pluck('url') as $url) {
            $this->assertStringStartsWith('https://res.cloudinary.com/', $url);
        }

        // Only product 1001 has detail rows.
        $this->assertSame(0, ProductColour::where('product_id', '!=', 1001)->count());
    }

    public function test_changed_sync_posts_since_and_sends_the_auth_token(): void
    {
        Http::fake([
            'api.promodata.test/products/changed*' => Http::response(PromodataFixtures::page([PromodataFixtures::product(1001)])),
        ]);

        $runner = app(SyncRunner::class);
        $runner->start(SyncRun::KIND_CHANGED, now()->setDate(2026, 10, 1)->setTime(0, 0));
        $runner->step(5);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && str_contains(urldecode($request->url()), '/products/changed?since=2026-10-01 00:00&page=1')
                && $request->hasHeader('x-auth-token', 'test-token');
        });
    }

    public function test_the_next_nightly_run_starts_from_the_last_completed_run(): void
    {
        $runner = app(SyncRunner::class);

        $first = $runner->startChangedSinceLastRun();
        $this->assertSame(SyncRun::KIND_FULL, $first->kind, 'With no history, start a full sync.');
        $this->assertNull($runner->startChangedSinceLastRun(), 'Do not start a second run while one is open.');

        $first->forceFill(['status' => SyncRun::STATUS_COMPLETED, 'started_at' => now()->setDate(2026, 10, 20)->setTime(1, 30)])->save();

        $next = $runner->startChangedSinceLastRun();
        $this->assertSame(SyncRun::KIND_CHANGED, $next->kind);
        $this->assertSame('2026-10-20 00:30', $next->since->format('Y-m-d H:i'));
    }

    public function test_a_failing_page_is_retried_then_marked_failed(): void
    {
        Http::fake(['api.promodata.test/*' => Http::response('error', 500)]);
        Sleep::fake(); // skip the client's retry back-off

        $runner = app(SyncRunner::class);
        $runner->start(SyncRun::KIND_FULL);

        for ($i = 1; $i <= 4; $i++) {
            $run = $runner->step(1);
            $this->assertSame(SyncRun::STATUS_RUNNING, $run->status);
            $this->assertSame(1, $run->next_page);
        }

        $run = $runner->step(1);
        $this->assertSame(SyncRun::STATUS_FAILED, $run->status);
        $this->assertNotNull($run->error);
    }
}
