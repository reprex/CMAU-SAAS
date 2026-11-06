<?php

namespace Tests\Feature;

use App\Catalogue\ImageTagger;
use App\Models\Product;
use App\Shopify\ProductPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

/** After shopify:switch-store, a product already on the live store is taken over by its handle (docs/publisher.md). */
class ShopifyAdoptTest extends TestCase
{
    use QuoteFixtures, RefreshDatabase;

    private const LIVE = 'crowdmerch.myshopify.com';

    private const CDN = 'https://res.cloudinary.com/promodata/as-colour/';

    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['shopify.store' => self::LIVE, 'shopify.dev_store' => 'cmau-dev.myshopify.com', 'shopify.token' => 'test', 'shopify.allow_live' => true]);
        DB::table('app_settings')->insert([['key' => 'shopify.ids_store', 'value' => self::LIVE], ['key' => 'shopify.adopt_by_handle', 'value' => '1']]);
        $this->supplier(88, 'AS Colour');
    }

    private function tee(): Product
    {
        $p = $this->catalogueProduct(43446, 88, 'Staple Tee', '5001');
        $p->update(['promodata_type_name' => 'T-Shirts', 'hero_image_url' => self::CDN.'5001_main.jpg', 'hydrated_at' => now(), 'range_changed_at' => now()]);
        $p->images()->delete();
        $p->images()->create(['url' => self::CDN.'5001_main.jpg', 'role' => 'hero_style', 'position' => 0]);
        $p->colours()->create(['name' => 'Black', 'image_url' => self::CDN.'black.jpg', 'appa_colours' => ['Black'], 'position' => 0]);
        app(ImageTagger::class)->apply($p);

        return $p->fresh();
    }

    private function fake(array $liveNodes): void
    {
        Http::fake([self::LIVE.'/*' => function ($request) use ($liveNodes) {
            $q = (string) ($request->data()['query'] ?? '');
            $this->sent[] = json_decode($request->body(), true);
            if (str_contains($q, 'products(first')) {
                return Http::response(['data' => ['products' => ['nodes' => $liveNodes]]]);
            }
            if (str_contains($q, 'publications(')) {
                return Http::response(['data' => ['publications' => ['nodes' => [['id' => 'gid://shopify/Publication/9', 'name' => 'Online Store']]]]]);
            }
            if (str_contains($q, 'publishablePublish')) {
                return Http::response(['data' => ['publishablePublish' => ['userErrors' => []]]]);
            }

            return Http::response(['data' => ['productSet' => ['userErrors' => [], 'product' => ['id' => 'gid://shopify/Product/9001', 'media' => ['nodes' => []]]]]]);
        }]);
    }

    public function test_a_live_product_is_taken_over_with_its_pictures(): void
    {
        $p = $this->tee();
        $this->fake([['id' => 'gid://shopify/Product/9001', 'handle' => '43446', 'status' => 'ACTIVE', 'media' => ['nodes' => [
            ['id' => 'gid://shopify/MediaImage/1', 'image' => ['url' => 'https://cdn.shopify.com/s/files/1/0/files/5001_main.jpg?v=1']],
            ['id' => 'gid://shopify/MediaImage/2', 'image' => ['url' => 'https://cdn.shopify.com/s/files/1/0/files/black_0a1b2c3d-1111-2222-3333-444455556666.jpg?v=2']],
            ['id' => 'gid://shopify/MediaImage/3', 'image' => ['url' => 'https://cdn.shopify.com/s/files/1/0/files/staff-lifestyle.jpg?v=3']],
        ]]]]);

        $r = app(ProductPublisher::class)->publish($p);

        $this->assertTrue($r['ok'], (string) $r['message']);
        $this->assertSame('update', $r['action']);
        $this->assertSame(9001, (int) $p->fresh()->shopify_product_id);
        $set = collect($this->sent)->first(fn ($d) => str_contains((string) $d['query'], 'productSet'));
        $this->assertSame(['id' => 'gid://shopify/Product/9001'], $set['variables']['identifier']);
        $files = $set['variables']['input']['files'];
        $this->assertSame([['id' => 'gid://shopify/MediaImage/1'], ['id' => 'gid://shopify/MediaImage/2'], ['id' => 'gid://shopify/MediaImage/3']], array_map(fn ($f) => array_intersect_key($f, ['id' => 1]), $files));
        $this->assertSame(['id' => 'gid://shopify/MediaImage/2'], $set['variables']['input']['variants'][0]['file']);
        $this->assertTrue(DB::table('shopify_publish_log')->where('action', 'adopt')->where('ok', true)->exists());
    }

    public function test_a_product_not_on_the_store_is_created(): void
    {
        $p = $this->tee();
        $this->fake([]);

        $r = app(ProductPublisher::class)->publish($p);

        $this->assertSame('create', $r['action']);
        $set = collect($this->sent)->first(fn ($d) => str_contains((string) $d['query'], 'productSet'));
        $this->assertSame(['handle' => '43446'], $set['variables']['identifier']);
    }

    public function test_file_keys(): void
    {
        $this->assertSame('ll0007_white', ProductPublisher::fileKey('https://res.cloudinary.com/promodata/ll/LL0007_White.jpg'));
        $this->assertSame('ll0007_white', ProductPublisher::fileKey('https://cdn.shopify.com/s/files/1/0/files/LL0007_White_3f2a1b2c-1111-2222-3333-444455556666.jpg?v=17'));
    }
}
