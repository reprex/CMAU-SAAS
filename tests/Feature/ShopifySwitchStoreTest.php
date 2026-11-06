<?php

namespace Tests\Feature;

use App\Shopify\ShopifyAdmin;
use App\Shopify\StoreBinding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class ShopifySwitchStoreTest extends TestCase
{
    use QuoteFixtures, RefreshDatabase;

    private const DEV = 'cmau-dev.myshopify.com';

    private const LIVE = 'crowdmerch.myshopify.com';

    protected function setUp(): void
    {
        parent::setUp();
        config(['shopify.store' => self::DEV, 'shopify.dev_store' => self::DEV, 'shopify.token' => 'test', 'shopify.allow_live' => false]);
        $this->supplier(88, 'AS Colour');
        $p = $this->catalogueProduct(43446, 88, 'Staple Tee', '5001');
        DB::table('products')->where('id', $p->id)->update(['shopify_product_id' => 777, 'publish_hash' => str_repeat('a', 40), 'published_at' => now()]);
        DB::table('shopify_media')->insert(['product_id' => $p->id, 'url_hash' => sha1('u'), 'url' => 'u', 'media_gid' => 'gid://shopify/MediaImage/1', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('shopify_collections')->insert(['product_type' => 'T-Shirts', 'tag' => 'tshirts', 'title' => 'T-Shirts', 'handle' => 'custom-printed-tshirts', 'shopify_gid' => 'gid://shopify/Collection/5', 'created_by_saas' => true, 'created_at' => now(), 'updated_at' => now()]);
        app(StoreBinding::class)->bindIfUnset();
    }

    private function goLive(): void
    {
        config(['shopify.store' => self::LIVE, 'shopify.allow_live' => true]);
    }

    public function test_writes_to_another_store_are_refused_until_switched(): void
    {
        Http::fake();
        $this->goLive();

        $this->assertFalse(app(StoreBinding::class)->matches());
        try {
            app(ShopifyAdmin::class)->graphql('mutation { x }');
            $this->fail('expected a refusal');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('shopify:switch-store', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_report_changes_nothing(): void
    {
        $this->goLive();

        $this->artisan('shopify:switch-store')->expectsOutputToContain('Report only')->assertSuccessful();

        $this->assertSame(self::DEV, app(StoreBinding::class)->bound());
        $this->assertSame(777, (int) DB::table('products')->value('shopify_product_id'));
    }

    public function test_switch_clears_ids_and_switching_back_restores_them(): void
    {
        Cache::forever('shopify:auto_publish', true);
        Http::fake();
        $this->goLive();

        $this->artisan('shopify:switch-store --apply')->expectsOutputToContain('auto-publish off')->assertFailed();
        $this->assertSame(self::DEV, app(StoreBinding::class)->bound());
        Cache::forever('shopify:auto_publish', false);
        $this->artisan('shopify:switch-store --apply')->assertSuccessful();
        $this->assertTrue(StoreBinding::adopting());

        Http::assertNothingSent();
        $b = app(StoreBinding::class);
        $this->assertSame(self::LIVE, $b->bound());
        $this->assertTrue($b->matches());
        $this->assertSame(['products' => 0, 'media' => 0, 'collections' => 0], $b->counts());
        $this->assertNull(DB::table('products')->value('published_at'));
        $this->assertSame('tshirts', DB::table('shopify_collections')->value('tag'), 'tags stay until the new store is synced');
        $this->assertFalse((bool) Cache::get('shopify:auto_publish'));

        // Back to CMAU_DEV: its ids come back.
        config(['shopify.store' => self::DEV, 'shopify.allow_live' => false]);
        $this->artisan('shopify:switch-store --apply')->assertSuccessful();
        $this->assertSame(777, (int) DB::table('products')->value('shopify_product_id'));
        $this->assertSame('gid://shopify/MediaImage/1', DB::table('shopify_media')->value('media_gid'));
        $this->assertSame('gid://shopify/Collection/5', DB::table('shopify_collections')->value('shopify_gid'));
        $this->assertSame(['products' => 0, 'media' => 0, 'collections' => 0], $b->archived(self::DEV));
        $this->assertFalse(StoreBinding::adopting(), 'switching back restores ids: no lookups');
        $this->assertSame(0, $b->archived(self::LIVE)['products'], 'nothing was published to live');
    }

    public function test_nothing_to_switch_on_the_same_store(): void
    {
        $this->artisan('shopify:switch-store --apply')->expectsOutputToContain('Nothing to switch')->assertSuccessful();
    }
}
