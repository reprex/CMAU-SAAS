<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class ShopifyPublishHealthTest extends TestCase
{
    use QuoteFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.admin_allowed_ips' => [], 'shopify.store' => 'cmau-dev.myshopify.com']);
        $this->supplier(53, 'Headwear Co');
        Cache::forever('scheduler:last_run', now()->toIso8601String());
        Cache::forever('shopify:auto_publish', true);
    }

    private function log(int $id, string $action, bool $ok, int $minutesAgo, string $message = 'ok'): void
    {
        DB::table('shopify_publish_log')->insert(['product_id' => $id, 'store' => 'cmau-dev.myshopify.com', 'action' => $action, 'ok' => $ok, 'message' => $message, 'created_at' => now()->subMinutes($minutesAgo)]);
    }

    public function test_shows_the_rate_waiting_failures_and_an_estimate(): void
    {
        foreach (range(1, 4) as $i) {
            $this->catalogueProduct(100 + $i, 53, 'Cap '.$i, 'C'.$i); // live, not on Shopify: new to upload
        }
        foreach (range(1, 30) as $i) {
            $this->log(900 + $i, 'create', true, $i % 10);
        }
        $this->log(104, 'create', false, 3, 'File with id 5 is in the FAILED state');

        $this->staff('admin');
        $this->get(route('admin.shopify-publishing.index'))->assertOk()
            ->assertSee('Healthy')->assertSee('Uploading: 2.0 products a minute')
            ->assertSee('New to upload')->assertSee('2 min')
            ->assertSee('Cap 4')->assertSee('FAILED state');
    }

    public function test_a_stall_is_flagged_on_every_admin_screen(): void
    {
        $this->catalogueProduct(201, 53, 'Cap', 'C1');
        $this->log(999, 'create', true, 30);

        $this->staff('admin');
        $this->get(route('admin.shopify-publishing.index'))->assertOk()->assertSee('Problem')->assertSee('Stalled: nothing uploaded for 30 minutes');
        $this->get(route('quotes.index'))->assertOk()->assertSee('Shopify upload:')->assertSee('Stalled');
    }

    public function test_the_switch_turns_publishing_off_and_a_stopped_cron_is_a_problem(): void
    {
        $this->staff('admin');
        $this->post(route('admin.shopify-publishing.toggle'), ['on' => 0])->assertRedirect();
        $this->assertFalse((bool) Cache::get('shopify:auto_publish'));

        Cache::forever('scheduler:last_run', now()->subMinutes(20)->toIso8601String());
        $this->get(route('admin.shopify-publishing.index'))->assertOk()->assertSee('The minute cron isn&#039;t running', false);
    }
}
