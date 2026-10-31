<?php

namespace Tests\Feature;

use App\Catalogue\SyncRunner;
use App\Models\AuditLog;
use App\Models\DecTypeCode;
use App\Models\Product;
use App\Models\SyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\PromodataFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class RangeCurationTest extends TestCase
{
    use QuoteFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['promodata.token' => 'test-token', 'promodata.base_url' => 'https://api.promodata.test', 'app.admin_allowed_ips' => []]);
    }

    /** A summary-tier product, as a full sync leaves it. */
    private function summaryProduct(int $id = 1001): Product
    {
        Http::fake(['api.promodata.test/products?page=1' => Http::response(PromodataFixtures::page([PromodataFixtures::product($id)]))]);
        $runner = app(SyncRunner::class);
        $runner->start(SyncRun::KIND_FULL);
        $runner->step(5);

        return Product::findOrFail($id);
    }

    private function fakeProductEndpoint(int $id = 1001): void
    {
        Http::fake(["api.promodata.test/products/{$id}" => Http::response(['data' => PromodataFixtures::product($id)])]);
    }

    public function test_adding_a_summary_product_fetches_it_prices_it_and_marks_it_to_publish(): void
    {
        $product = $this->summaryProduct();
        $this->assertSame(Product::TIER_SUMMARY, $product->tier);
        $this->fakeProductEndpoint();
        $this->staff('admin');

        $this->post(route('range.action', $product), ['action' => 'add'])->assertRedirect(route('range.show', $product));

        $product->refresh();
        $this->assertSame(Product::TIER_LIVE, $product->tier);
        $this->assertNotNull($product->hydrated_at);
        $this->assertNotNull($product->range_changed_at);
        $this->assertGreaterThan(0, $product->priceOptions()->count());
        $this->assertNotNull($product->webPrice);
        $this->assertTrue(AuditLog::where('action', 'range.added')->where('subject_id', '1001')->exists());

        $this->get(route('range.index'))->assertOk()->assertSee($product->name)->assertSee('to publish');
        $this->get(route('range.show', $product))->assertOk()->assertSee('Price options');
    }

    public function test_removing_keeps_detail_and_overrides(): void
    {
        $product = $this->summaryProduct();
        $this->fakeProductEndpoint();
        $this->staff('admin');
        $this->post(route('range.action', $product), ['action' => 'add']);
        $this->put(route('range.update', $product), ['title' => 'Embroidered cap', 'actual_moq' => 50]);

        $this->post(route('range.bulk'), ['action' => 'remove', 'ids' => [$product->id]])->assertRedirect();

        $product->refresh();
        $this->assertSame(Product::TIER_ACTIVE, $product->tier);
        $this->assertNotNull($product->hydrated_at);
        $this->assertSame('Embroidered cap', $product->overrideValue('title'));
    }

    public function test_curation_overrides_feed_the_price_and_unset_when_blank(): void
    {
        $product = $this->summaryProduct();
        $this->fakeProductEndpoint();
        $this->staff('admin');
        $this->post(route('range.action', $product), ['action' => 'add']);

        $this->put(route('range.update', $product), ['actual_moq' => 500, 'collection_moq' => 500, 'notes' => 'Check with supplier'])
            ->assertRedirect(route('range.show', $product));
        $product->refresh();
        $this->assertSame(500, $product->webPrice->actual_moq);
        $this->assertSame(500, $product->webPrice->collection_moq);

        $this->put(route('range.update', $product), ['actual_moq' => '', 'collection_moq' => '', 'notes' => '']);
        $product->refresh();
        $this->assertNull($product->overrideValue('actual_moq'));
        $this->assertNull($product->override);
    }

    public function test_hero_image_must_be_one_of_the_products_images(): void
    {
        $product = $this->summaryProduct();
        $this->fakeProductEndpoint();
        $this->staff('admin');
        $this->post(route('range.action', $product), ['action' => 'add']);

        $this->put(route('range.update', $product), ['hero_image_url' => 'https://evil.example/x.png'])->assertSessionHasErrors('hero_image_url');
    }

    public function test_bulk_add_queues_and_processes_in_background(): void
    {
        Http::fake([
            'api.promodata.test/products?page=1' => Http::response(PromodataFixtures::page([PromodataFixtures::product(1001), PromodataFixtures::product(1002)])),
            'api.promodata.test/products/1001' => Http::response(['data' => PromodataFixtures::product(1001)]),
            'api.promodata.test/products/1002' => Http::response(['data' => PromodataFixtures::product(1002)]),
        ]);
        $runner = app(SyncRunner::class);
        $runner->start(SyncRun::KIND_FULL);
        $runner->step(5);
        $this->staff('admin');

        // Tests run the queue synchronously.
        $this->post(route('range.bulk'), ['action' => 'add', 'ids' => [1001, 1002]])->assertSessionHas('status');

        $this->assertSame(2, Product::where('tier', Product::TIER_LIVE)->count());
        $this->assertTrue(AuditLog::where('action', 'range.bulk_add')->exists());
    }

    public function test_sales_can_view_but_not_change_the_range_and_admin_screens_are_admin_only(): void
    {
        $product = $this->summaryProduct();
        $user = $this->staff('readonly');
        $this->get(route('range.index', ['view' => 'all']))->assertOk()->assertSee($product->name);
        $this->post(route('range.action', $product), ['action' => 'add'])->assertForbidden();
        $this->get(route('admin.sync.index'))->assertForbidden();
        $this->get(route('admin.dectypes.index'))->assertForbidden();
    }

    public function test_sync_screen_starts_and_stops_runs_and_warns_when_the_scheduler_is_stopped(): void
    {
        $this->staff('admin');
        $this->get(route('admin.sync.index'))->assertOk()->assertSee('The scheduler isn', false);

        $this->post(route('admin.sync.start'), ['kind' => 'full'])->assertSessionHas('status');
        $run = SyncRun::latest('id')->first();
        $this->assertSame(SyncRun::KIND_FULL, $run->kind);

        cache()->forever('scheduler:last_run', now()->toIso8601String());
        $this->get(route('admin.sync.index'))->assertOk()->assertDontSee('The scheduler isn', false)->assertSee('Sync #'.$run->id);

        $this->post(route('admin.sync.cancel', $run))->assertSessionHas('status');
        $this->assertSame(SyncRun::STATUS_FAILED, $run->refresh()->status);
    }

    public function test_classifying_a_decoration_type_reprices_products_using_it(): void
    {
        $product = $this->summaryProduct();
        $this->fakeProductEndpoint();
        $this->staff('admin');
        $this->post(route('range.action', $product), ['action' => 'add']);

        $todo = DecTypeCode::query()->whereNull('type_code')->first();
        $this->assertNotNull($todo, 'Fixture descriptions arrive unclassified.');
        $this->get(route('admin.dectypes.index'))->assertOk()->assertSee($todo->code);

        $this->put(route('admin.dectypes.update', $todo), ['type_code' => 10, 'overwrite_description' => 'Embroidery'])->assertSessionHas('status');

        $this->assertSame(10, $todo->refresh()->type_code);
        $this->assertSame(10, $product->priceOptions()->where('code', $todo->code)->value('type_code'));
    }

    public function test_a_supplier_change_to_a_live_product_reprices_it(): void
    {
        $product = $this->summaryProduct();
        $this->fakeProductEndpoint();
        $this->staff('admin');
        $this->post(route('range.action', $product), ['action' => 'add']);
        $computed = $product->refresh()->webPrice->computed_at;

        $this->travel(5)->minutes();
        $changed = PromodataFixtures::product(1001);
        $changed['meta']['last_changed_at'] = now()->toIso8601String();
        $changed['product']['name'] = 'Renamed by supplier';
        Http::fake(['api.promodata.test/products/changed*' => Http::response(PromodataFixtures::page([$changed]))]);
        $runner = app(SyncRunner::class);
        $runner->start(SyncRun::KIND_CHANGED, now()->subDay());
        $runner->step(5);

        $product->refresh();
        $this->assertSame('Renamed by supplier', $product->name);
        $this->assertTrue($product->webPrice->computed_at->gt($computed));
    }
}
