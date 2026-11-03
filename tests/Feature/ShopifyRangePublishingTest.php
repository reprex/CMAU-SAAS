<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class ShopifyRangePublishingTest extends TestCase
{
    use QuoteFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shopify.store' => 'cmau-dev.myshopify.com', 'shopify.dev_store' => 'cmau-dev.myshopify.com', 'shopify.token' => 'test', 'shopify.allow_live' => false]);
        $this->supplier(53, 'Headwear Co');
        foreach ([101, 102, 103] as $id) {
            $p = $this->catalogueProduct($id, 53, "Cap {$id}", "C{$id}");
            foreach (['Black', 'Navy'] as $c) {
                $p->colours()->create(['name' => $c, 'image_url' => "https://example.com/{$id}-{$c}.jpg", 'position' => 0]);
            }
        }
    }

    public function test_auto_publish_switch(): void
    {
        $this->artisan('shopify:auto-publish')->expectsOutputToContain('OFF')->assertSuccessful();
        $this->artisan('shopify:auto-publish on')->expectsOutputToContain('ON')->assertSuccessful();
        $this->assertTrue(Cache::get('shopify:auto_publish'));
        $this->artisan('shopify:auto-publish off')->assertSuccessful();
        $this->assertFalse(Cache::get('shopify:auto_publish'));
    }

    public function test_recent_failures_wait_and_the_report_counts(): void
    {
        DB::table('shopify_publish_log')->insert(['product_id' => 102, 'store' => 'cmau-dev.myshopify.com', 'action' => 'create', 'ok' => false, 'message' => 'Bad image', 'created_at' => now()->subMinutes(10)]);
        $this->artisan('shopify:publish --preview --retry-after=60')
            ->expectsOutputToContain('101')->doesntExpectOutputToContain('102 ')->assertSuccessful();

        $this->artisan('shopify:range-report')
            ->expectsOutputToContain('Variants for the whole range')
            ->expectsOutputToContain('Failed: 102')
            ->assertSuccessful();
    }

    public function test_new_products_publish_before_republishes(): void
    {
        DB::table('products')->where('id', 101)->update(['shopify_product_id' => 9001, 'published_at' => now()->subHour(), 'range_changed_at' => now()]);
        $this->artisan('shopify:publish --preview --limit=2')
            ->expectsOutputToContain('102')->expectsOutputToContain('103')->doesntExpectOutputToContain('101 ')->assertSuccessful();
    }

    public function test_discontinued_products_never_on_the_store_are_not_picked(): void
    {
        DB::table('products')->where('id', 101)->update(['discontinued' => true]);
        $this->artisan('shopify:publish --preview --limit=1')->expectsOutputToContain('102')->doesntExpectOutputToContain('101 ')->assertSuccessful();
    }
}
