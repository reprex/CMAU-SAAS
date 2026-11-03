<?php

namespace Tests\Feature;

use App\Catalogue\RangeChanges;
use App\Catalogue\ReadinessCheck;
use App\Jobs\RangeBulkAction;
use App\Models\Product;
use App\Models\ProductWebPrice;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\RangeSuggestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class RangeChangesTest extends TestCase
{
    use QuoteFixtures, RefreshDatabase;

    private const TYPE = 'Headwear > Caps';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.admin_allowed_ips' => []]);
        $this->supplier(53, 'Headwear Co');
        DB::table('shopify_collections')->insert(['product_type' => self::TYPE, 'tag' => 'caps', 'created_at' => now(), 'updated_at' => now()]);
        // Three caps on the range at about $8 each, cost about $4.
        foreach ([101 => 7.80, 102 => 8.00, 103 => 8.40] as $id => $price) {
            $this->cap($id, Product::TIER_LIVE, $price, 4.0);
        }
    }

    private function cap(int $id, string $tier, float $price, float $cost): Product
    {
        $p = $this->catalogueProduct($id, 53, 'Cap '.$id, 'C'.$id);
        $p->forceFill(['tier' => $tier, 'promodata_type_name' => self::TYPE, 'min_unit_cost' => $cost, 'hydrated_at' => now()])->save();
        ProductWebPrice::query()->whereKey($id)->update(['collection_variant_price' => $price, 'collection_moq' => 50, 'decoration_available' => true]);

        return $p->fresh();
    }

    public function test_a_new_product_priced_like_the_range_is_ready_and_one_off_price_needs_a_look(): void
    {
        $ok = $this->cap(201, Product::TIER_ACTIVE, 8.10, 4.1);
        $odd = $this->cap(202, Product::TIER_ACTIVE, 25.00, 4.0);
        $check = app(ReadinessCheck::class);

        $a = collect($check->check($ok)['checks'])->keyBy('key');
        $this->assertTrue($a['priced']['ok']);
        $this->assertTrue($a['price']['ok']);
        $this->assertTrue($a['markup']['ok']);
        $this->assertTrue($a['category']['ok']);
        $this->assertStringContainsString('3 similar from this supplier', $a['price']['detail']);

        $b = collect($check->check($odd)['checks'])->keyBy('key');
        $this->assertFalse($b['price']['ok']);
        $this->assertSame('Price high', $b['price']['label']);
        $this->assertFalse($b['markup']['ok']);
    }

    public function test_only_products_new_since_the_supplier_was_watched_are_suggested_and_approval_adds_them(): void
    {
        Queue::fake();
        $old = $this->cap(301, Product::TIER_SUMMARY, 8, 4);
        $this->travel(1)->minutes();
        $this->staff('admin');
        $this->post(route('admin.range-supplier.watch'), ['supplier_id' => 53, 'suggest_new' => 1])->assertRedirect();
        $this->travel(1)->minutes();
        $new = $this->cap(302, Product::TIER_ACTIVE, 8.2, 4.1);

        $changes = app(RangeChanges::class);
        $this->assertSame(1, $changes->find()['new']);
        $changes->prepare();
        $s = RangeSuggestion::query()->where('product_id', 302)->firstOrFail();
        $this->assertSame('pending', $s->status);
        $this->assertNotNull($s->checks);
        $this->assertFalse(RangeSuggestion::query()->where('product_id', $old->id)->exists());
        $this->assertSame(Product::TIER_ACTIVE, $new->fresh()->tier); // not on the range yet

        $s->update(['ready' => true]); // fixtures have no pictures; the screen approves what passed
        $this->get(route('range.changes'))->assertOk()->assertSee('Cap 302')->assertSee('Approve all 1 ready');
        $this->post(route('range.changes.decide'), ['action' => 'approve_ready'])->assertRedirect();
        Queue::assertPushed(RangeBulkAction::class, fn ($job) => $job->action === 'add' && $job->productIds === [302]);
        $this->assertSame('approved', $s->fresh()->status);
        $this->assertSame(0, $changes->find()['new']); // never suggested twice
    }

    public function test_a_discontinued_product_lists_open_recent_quotes_but_not_ordered_or_old_ones(): void
    {
        $gone = $this->cap(401, Product::TIER_LIVE, 8, 4);
        $gone->forceFill(['discontinued' => true, 'discontinued_at' => now()])->save();
        $open = Quote::create(['number' => 'MR500', 'status' => 'QUOTE', 'client_company' => 'Harbourside RC', 'client_email' => 'pat@example.com']);
        $won = Quote::create(['number' => 'MR501', 'status' => 'ORDER']);
        $old = Quote::create(['number' => 'MR502', 'status' => 'QUOTE']);
        $old->forceFill(['created_at' => now()->subMonths(3)])->save();
        foreach ([$open, $won, $old] as $q) {
            QuoteItem::create(['quote_id' => $q->id, 'product_id' => 401, 'name' => 'Cap 401', 'quantity' => 100]);
        }

        $changes = app(RangeChanges::class);
        $this->assertSame(1, $changes->find()['discontinued']);
        $changes->prepare();
        $this->staff('admin');
        $this->get(route('range.changes', ['view' => 'discontinued']))->assertOk()
            ->assertSee('Cap 401')->assertSee('1 open quote')->assertSee('MR500')->assertSee('Harbourside RC')
            ->assertDontSee('MR501')->assertDontSee('MR502')->assertSee('Find substitutes');
    }

    public function test_a_discontinued_product_only_in_an_open_quote_is_listed_too(): void
    {
        $p = $this->cap(402, Product::TIER_SUMMARY, 8, 4);
        $p->forceFill(['discontinued' => true])->save();
        $q = Quote::create(['number' => 'MR600', 'status' => 'NEW']);
        QuoteItem::create(['quote_id' => $q->id, 'product_id' => 402, 'name' => 'Cap 402', 'quantity' => 50]);

        $this->assertSame(1, app(RangeChanges::class)->find()['discontinued']);
    }
}
