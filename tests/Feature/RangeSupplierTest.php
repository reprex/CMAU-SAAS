<?php

namespace Tests\Feature;

use App\Catalogue\SyncRunner;
use App\Jobs\RangeBulkAction;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\SyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PromodataFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class RangeSupplierTest extends TestCase
{
    use QuoteFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['promodata.token' => 'test-token', 'promodata.base_url' => 'https://api.promodata.test', 'app.admin_allowed_ips' => []]);

        // Three summary products from supplier 3: two current, one discontinued.
        Http::fake(['api.promodata.test/products?page=1' => Http::response(PromodataFixtures::page([
            PromodataFixtures::product(2001),
            PromodataFixtures::product(2002),
            PromodataFixtures::product(2003, ['meta' => ['discontinued' => true]]),
        ]))]);
        $runner = app(SyncRunner::class);
        $runner->start(SyncRun::KIND_FULL);
        $runner->step(5);
    }

    public function test_the_screen_counts_what_would_be_added(): void
    {
        $this->staff('admin');
        Product::query()->whereKey(2002)->update(['tier' => Product::TIER_LIVE]);

        $this->get(route('admin.range-supplier.index', ['supplier' => 3]))->assertOk()
            ->assertSee('Add a whole supplier')
            ->assertSee('Already on range')
            ->assertSeeInOrder(['Total', '1', '1']);
    }

    public function test_adding_queues_only_current_products_not_already_live(): void
    {
        Queue::fake();
        $this->staff('admin');
        Product::query()->whereKey(2002)->update(['tier' => Product::TIER_LIVE]);
        $type = (string) Product::query()->whereKey(2001)->value('promodata_type_name');

        $this->post(route('admin.range-supplier.store'), ['supplier_id' => 3, 'types' => [$type]])
            ->assertRedirect(route('admin.range-supplier.index', ['supplier' => 3]))
            ->assertSessionHas('status');

        Queue::assertPushed(RangeBulkAction::class, fn ($job) => $job->action === 'add' && $job->productIds === [2001]);
        Queue::assertPushed(RangeBulkAction::class, 1);
        $this->assertTrue(AuditLog::where('action', 'range.supplier_added')->exists());
    }

    public function test_leaving_out_a_type_adds_nothing_of_that_type(): void
    {
        Queue::fake();
        $this->staff('admin');

        $this->post(route('admin.range-supplier.store'), ['supplier_id' => 3, 'types' => ['Some other type']])->assertRedirect();

        Queue::assertNothingPushed();
    }

    public function test_sales_staff_cannot_add_a_whole_supplier(): void
    {
        $this->staff('sales');

        $this->get(route('admin.range-supplier.index'))->assertForbidden();
        $this->post(route('admin.range-supplier.store'), ['supplier_id' => 3, 'types' => ['x']])->assertForbidden();
    }
}
