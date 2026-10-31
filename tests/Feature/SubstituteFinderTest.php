<?php

namespace Tests\Feature;

use App\Catalogue\SubstituteFinder;
use App\Catalogue\SyncRunner;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\PromodataFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class SubstituteFinderTest extends TestCase
{
    use QuoteFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['promodata.token' => 't', 'promodata.base_url' => 'https://api.promodata.test']);
    }

    private function product(int $id, array $facts, string $type = 'plasticpens'): Product
    {
        Supplier::query()->firstOrCreate(['id' => 3], ['name' => 'Test Supplier']);

        return Product::create($facts + ['id' => $id, 'supplier_id' => 3, 'name' => "Pen {$id}", 'code' => "P{$id}", 'tier' => 'summary',
            'promodata_type_id' => $type, 'promodata_type_name' => 'Plastic Pens', 'summary_version' => 1, 'can_check_stock' => true]);
    }

    public function test_sync_stores_comparison_facts_on_summary_rows(): void
    {
        $pen = PromodataFixtures::product(1001);
        $pen['product']['prices']['price_groups'][] = ['base_price' => [
            'key' => 'b2', 'type' => 'Unbranded', 'setup' => 0, 'currency' => 'AUD', 'lead_time' => '3-5 days', 'description' => 'Unbranded',
            'undecorated' => true, 'indent' => false, 'promodata_decoration' => null, 'tags' => [],
            'price_breaks' => [['qty' => 100, 'price' => 0.30], ['qty' => 500, 'price' => 0.25]],
        ], 'additions' => []];
        Http::fake(['api.promodata.test/products?page=1' => Http::response(PromodataFixtures::page([$pen]))]);
        $runner = app(SyncRunner::class);
        $runner->start(SyncRun::KIND_FULL);
        $runner->step(5);

        $p = Product::find(1001);
        $this->assertSame('plastic', $p->material);
        $this->assertSame(140.0, $p->dim_1);
        $this->assertSame(10.0, $p->dim_2);
        $this->assertSame(5, $p->lead_time_days);
        $this->assertSame(100, $p->undecorated_moq);
        $this->assertSame('0.3000', $p->undecorated_cost);
        $this->assertSame(1, $p->summary_version);
    }

    public function test_an_older_summary_version_is_refilled_even_when_promodata_is_unchanged(): void
    {
        Http::fake(['api.promodata.test/products?page=1' => Http::response(PromodataFixtures::page([PromodataFixtures::product(1001)]))]);
        $runner = app(SyncRunner::class);
        $runner->start(SyncRun::KIND_FULL);
        $runner->step(5);
        Product::query()->update(['summary_version' => 0, 'material' => null]);

        $runner->start(SyncRun::KIND_FULL);
        $run = $runner->step(5);

        $this->assertSame(1, $run->products_changed);
        $this->assertSame('plastic', Product::find(1001)->material);
    }

    public function test_ranking_prefers_closest_material_size_cost_moq_and_lead_time(): void
    {
        $ref = $this->product(1, ['material' => 'aluminium', 'dim_1' => 140, 'dim_2' => 10, 'undecorated_cost' => 1.00, 'undecorated_moq' => 100, 'lead_time_days' => 5]);
        $twin = $this->product(2, ['material' => 'aluminium', 'dim_1' => 142, 'dim_2' => 10, 'undecorated_cost' => 1.05, 'undecorated_moq' => 100, 'lead_time_days' => 5]);
        $plastic = $this->product(3, ['material' => 'plastic', 'dim_1' => 140, 'dim_2' => 10, 'undecorated_cost' => 1.00, 'undecorated_moq' => 100, 'lead_time_days' => 5]);
        $slow = $this->product(4, ['material' => 'aluminium', 'dim_1' => 140, 'dim_2' => 10, 'undecorated_cost' => 1.60, 'undecorated_moq' => 500, 'lead_time_days' => 20]);
        $this->product(5, ['material' => 'aluminium', 'undecorated_cost' => 1.00], type: 'mugs');
        $this->product(6, ['material' => 'aluminium', 'undecorated_cost' => 1.00, 'discontinued' => true]);

        $ids = app(SubstituteFinder::class)->find($ref)->pluck('product.id')->all();

        $this->assertSame([2, 3, 4], $ids, 'Same type only, no discontinued, best match first');
    }

    public function test_hard_limits_filter_candidates(): void
    {
        $ref = $this->product(1, ['undecorated_cost' => 1.00, 'undecorated_moq' => 100, 'lead_time_days' => 5]);
        $this->product(2, ['undecorated_cost' => 2.00, 'undecorated_moq' => 100, 'lead_time_days' => 5]);
        $this->product(3, ['undecorated_cost' => 0.90, 'undecorated_moq' => 1000, 'lead_time_days' => 5]);
        $this->product(4, ['undecorated_cost' => 0.90, 'undecorated_moq' => 50, 'lead_time_days' => 30]);
        $this->product(5, ['undecorated_cost' => 0.95, 'undecorated_moq' => 50, 'lead_time_days' => 4]);

        $ids = app(SubstituteFinder::class)->find($ref, ['max_cost' => 1.5, 'max_moq' => 250, 'max_lead' => 10])->pluck('product.id')->all();

        $this->assertSame([5], $ids);
    }

    public function test_screen_lookup_and_live_stock(): void
    {
        $ref = $this->product(1, ['material' => 'aluminium', 'undecorated_cost' => 1.00, 'undecorated_moq' => 100]);
        $this->product(2, ['material' => 'aluminium', 'undecorated_cost' => 1.10, 'undecorated_moq' => 100]);
        $this->staff('readonly');

        $this->get(route('substitutes.lookup', ['product' => 1]))->assertRedirect(route('range.substitutes', 1));
        $this->get(route('range.substitutes', $ref))->assertOk()->assertSee('Substitutes for Pen 1')->assertSee('Pen 2')->assertSee('+10%');

        Http::fake(['api.promodata.test/products/2/check-stock-levels' => Http::response(['data' => ['stock' => [
            ['Black', null, 300, 'P2-BLK'], ['Red', null, 120, 'P2-RED'], ['Black', 'Refill', 50, 'P2-BLK-R'],
        ]]])]);
        $this->getJson(route('range.stock', 2))->assertOk()
            ->assertJson(['ok' => true, 'total' => 470, 'best_colour' => 'Black', 'best_qty' => 350, 'colours' => 2]);
    }
}
