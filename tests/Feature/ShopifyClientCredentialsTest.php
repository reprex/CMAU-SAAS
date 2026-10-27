<?php

namespace Tests\Feature;

use App\Shopify\ShopifyAdmin;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ShopifyClientCredentialsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['shopify.store' => 'cmau-dev.myshopify.com', 'shopify.token' => '', 'shopify.client_id' => 'cid', 'shopify.client_secret' => 'csecret']);
    }

    public function test_token_is_fetched_once_and_cached(): void
    {
        Http::fake([
            'cmau-dev.myshopify.com/admin/oauth/access_token' => Http::response(['access_token' => 'shpat_test', 'scope' => 'read_draft_orders', 'expires_in' => 86399]),
            'cmau-dev.myshopify.com/admin/api/*' => Http::response(['draft_orders' => []]),
        ]);

        $shopify = app(ShopifyAdmin::class);
        $this->assertTrue($shopify->configured());
        $shopify->draftOrdersUpdatedSince(null);
        $shopify->draftOrdersUpdatedSince(null);

        Http::assertSentCount(3); // one token request, two API calls
        Http::assertSent(fn ($r) => str_contains($r->url(), '/admin/oauth/access_token')
            && $r['grant_type'] === 'client_credentials' && $r['client_id'] === 'cid');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/admin/api/') && $r->hasHeader('X-Shopify-Access-Token', 'shpat_test'));
    }

    public function test_refused_token_gives_a_clear_error(): void
    {
        Http::fake(['*' => Http::response(['error' => 'shop_not_permitted'], 400)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('shop_not_permitted');
        app(ShopifyAdmin::class)->accessToken();
    }

    public function test_fixed_token_still_wins(): void
    {
        config(['shopify.token' => 'shpat_fixed']);
        Http::fake();

        $this->assertSame('shpat_fixed', app(ShopifyAdmin::class)->accessToken());
        Http::assertNothingSent();
    }
}
