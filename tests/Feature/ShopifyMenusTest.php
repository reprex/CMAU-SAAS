<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyMenusTest extends TestCase
{
    use RefreshDatabase;

    private array $writes = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['shopify.store' => 'cmau-dev.myshopify.com', 'shopify.dev_store' => 'cmau-dev.myshopify.com', 'shopify.token' => 'test', 'shopify.allow_live' => false,
            'shopify.client_id' => 'id', 'shopify.client_secret' => 'secret']);
        DB::table('shopify_collections')->insert(['product_type' => 'Clothing > T-Shirts', 'tag' => 'tshirts', 'handle' => 'custom-printed-tshirts', 'created_by_saas' => false, 'created_at' => now(), 'updated_at' => now()]);
        Http::fake(function ($req) {
            $body = json_decode($req->body(), true);
            $q = $body['query'] ?? '';
            if (str_contains($q, 'access_token') || str_contains((string) $req->url(), 'oauth')) {
                return Http::response(['access_token' => 't', 'expires_in' => 86399]);
            }
            if (str_contains($q, 'menus(first')) {
                return Http::response(['data' => ['menus' => ['nodes' => [['id' => 'gid://shopify/Menu/1', 'handle' => 'cm-main'], ['id' => 'gid://shopify/Menu/9', 'handle' => 'main-menu']]]]]);
            }
            if (str_starts_with(ltrim($q), 'mutation')) {
                $this->writes[] = $body;
                $key = str_contains($q, 'menuUpdate') ? 'menuUpdate' : 'menuCreate';

                return Http::response(['data' => [$key => ['menu' => ['id' => 'gid://shopify/Menu/2'], 'userErrors' => []]]]);
            }
            $search = $body['variables']['q'] ?? '';
            $field = str_contains($q, 'pages(') ? 'pages' : 'collections';
            // Everything exists except the sample page; no collection is titled "Lanyards".
            if ($search === 'handle:how-do-i-order-a-sample' || str_starts_with($search, 'title:')) {
                return Http::response(['data' => [$field => ['nodes' => []]]]);
            }
            $handle = str_replace('handle:', '', $search);

            return Http::response(['data' => [$field => ['nodes' => [['id' => 'gid://shopify/'.($field === 'pages' ? 'Page' : 'Collection').'/'.crc32($handle), 'handle' => $handle, 'title' => $handle]]]]]);
        });
    }

    public function test_report_writes_nothing_and_lists_what_is_missing(): void
    {
        $this->artisan('shopify:menus')
            ->expectsOutputToContain('T-shirts -> /collections/custom-printed-tshirts')
            ->expectsOutputToContain('Samples" left out')
            ->expectsOutputToContain('lanyards: no collection found, linked to page:custom-branded-event-merch|collection:events')
            ->assertSuccessful();
        $this->assertSame([], $this->writes);
    }

    public function test_apply_replaces_existing_menus_and_creates_the_rest_only_for_our_handles(): void
    {
        $this->artisan('shopify:menus --apply')->assertSuccessful();
        $handles = collect($this->writes)->map(fn ($w) => $w['variables']['handle'])->all();
        $this->assertSame(['cm-main', 'cm-popular', 'cm-foot-clothing', 'cm-foot-merch', 'cm-foot-crowdmerch', 'cm-foot-help'], $handles);
        $this->assertStringContainsString('menuUpdate', $this->writes[0]['query']);
        $this->assertSame('gid://shopify/Menu/1', $this->writes[0]['variables']['id']);
        $this->assertStringContainsString('menuCreate', $this->writes[1]['query']);
        $main = $this->writes[0]['variables']['items'];
        $this->assertSame(['Find products', 'Get started', 'Case studies', 'Services', 'Help'], array_column($main, 'title'));
        $this->assertCount(14, $main[0]['items']);
        $this->assertSame('PAGE', $main[0]['items'][1]['type']);
        $this->assertSame('PAGE', $main[0]['type']);
        $this->assertArrayHasKey('resourceId', $main[0]);
        $this->assertSame(['type' => 'HTTP', 'url' => '/#cases'], ['type' => $main[2]['type'], 'url' => $main[2]['url']]);
        $help = $this->writes[5]['variables']['items'];
        $this->assertNotContains('Samples', array_column($help, 'title'));
    }

    public function test_live_store_is_refused_without_allow_live(): void
    {
        config(['shopify.store' => 'crowdmerch.myshopify.com']);
        $this->artisan('shopify:menus --apply')->assertFailed();
    }
}
