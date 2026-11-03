<?php

namespace Tests\Feature;

use App\Catalogue\ImageTagger;
use App\Models\Product;
use App\Models\ProductOverride;
use App\Shopify\ProductPayload;
use App\Shopify\ProductPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class ShopifyPublishTest extends TestCase
{
    use QuoteFixtures, RefreshDatabase;

    private const CDN = 'https://res.cloudinary.com/promodata/as-colour/';

    protected function setUp(): void
    {
        parent::setUp();
        config(['shopify.store' => 'cmau-dev.myshopify.com', 'shopify.dev_store' => 'cmau-dev.myshopify.com', 'shopify.token' => 'test', 'shopify.allow_live' => false]);
        $this->supplier(88, 'AS Colour');
    }

    private function tee(): Product
    {
        $p = $this->catalogueProduct(43446, 88, 'Staple Tee', '5001');
        $p->update(['promodata_type_name' => 'T-Shirts', 'hero_image_url' => self::CDN.'5001_main.jpg', 'hydrated_at' => now(), 'range_changed_at' => now()]);
        $p->images()->delete();
        $p->images()->create(['url' => self::CDN.'5001_main.jpg', 'role' => 'hero_style', 'position' => 0]);
        $p->images()->create(['url' => self::CDN.'5001_back.jpg', 'role' => 'other_style', 'position' => 1]);
        $p->images()->create(['url' => self::CDN.'5001_navy_back.jpg', 'role' => 'other_style', 'position' => 2]);
        $p->colours()->create(['name' => 'Black', 'image_url' => self::CDN.'black.jpg', 'appa_colours' => ['Black'], 'position' => 0]);
        $p->colours()->create(['name' => 'Navy', 'image_url' => self::CDN.'navy.jpg', 'appa_colours' => ['Blue'], 'position' => 1]);
        $p->webPrice()->update(['collection_variant_price' => 18.22, 'collection_moq' => 25,
            'decoration_json' => json_encode(['BrandFlag' => '1', 'Decorations' => [['DecID' => 'x', 'DecName' => 'Embroidery to 5K stitches']]])]);
        app(ImageTagger::class)->apply($p);

        return $p->fresh();
    }

    private function fakeShopify(): void
    {
        $n = 0;
        Http::fake(['cmau-dev.myshopify.com/*' => function ($request) use (&$n) {
            $q = (string) ($request->data()['query'] ?? '');
            if (str_contains($q, 'publications(')) {
                return Http::response(['data' => ['publications' => ['nodes' => [['id' => 'gid://shopify/Publication/9', 'name' => 'Online Store']]]]]);
            }
            if (str_contains($q, 'publishablePublish')) {
                return Http::response(['data' => ['publishablePublish' => ['userErrors' => []]]]);
            }
            $n++;

            return Http::response(['data' => ['productSet' => ['userErrors' => [], 'product' => ['id' => 'gid://shopify/Product/777',
                'media' => ['nodes' => [['id' => 'gid://shopify/MediaImage/1'], ['id' => 'gid://shopify/MediaImage/2'], ['id' => 'gid://shopify/MediaImage/3'], ['id' => 'gid://shopify/MediaImage/4']]]]]]]);
        }]);
    }

    public function test_payload_follows_the_contract(): void
    {
        $in = app(ProductPayload::class)->build($this->tee())['input'];

        $this->assertSame('43446', $in['handle']);
        $this->assertSame('Staple Tee [88-5001]', $in['title']);
        $this->assertSame('ACTIVE', $in['status']);
        $this->assertSame('colour_as_variant_product', $in['templateSuffix']);
        $this->assertSame('Custom Embroidered Staple Tee [5001]', $in['seo']['title']);
        $this->assertContains('style', $in['tags']);
        $this->assertSame(['Black', 'Navy'], array_column($in['productOptions'][0]['values'], 'name'));
        $this->assertSame('43446-black', $in['variants'][0]['inventoryItem']['sku']);
        $this->assertSame('18.22', $in['variants'][0]['price']);
        $this->assertSame(self::CDN.'black.jpg', $in['variants'][0]['file']['originalSource']);
        $navy = collect($in['variants'][1]['metafields'])->keyBy('key');
        $this->assertSame(self::CDN.'5001_navy_back.jpg', $navy['altimagepublicpath']['value']);
        $this->assertSame('["Blue"]', $navy['colourfamily']['value']);
        $mf = collect($in['metafields'])->keyBy('key');
        $this->assertSame('25', $mf['collectiondisplayqty']['value']);
        $this->assertSame('any,embroider', $mf['tagbrandingoption']['value']);
        $this->assertSame('any,black,blue', $mf['tagcolourgroup']['value']);
        $this->assertSame('AS Colour 5001', $mf['suppliercode']['value']);
        $this->assertArrayHasKey('decorationjson', $mf->all());
        // Media: hero, other style, then the colour images; the navy back is a variant's other image, not product media.
        $this->assertSame([self::CDN.'5001_main.jpg', self::CDN.'5001_back.jpg', self::CDN.'black.jpg', self::CDN.'navy.jpg'], array_column($in['files'], 'originalSource'));
    }

    public function test_first_publish_uploads_images_then_links_variants_and_skips_when_unchanged(): void
    {
        $p = $this->tee();
        $this->fakeShopify();
        $this->artisan('shopify:publish')->expectsOutputToContain('1 create')->assertSuccessful();

        // upload, then link the colour images (plus the Online Store channel calls)
        $sets = collect(Http::recorded())->map(fn ($r) => json_decode($r[0]->body(), true))->filter(fn ($b) => str_contains($b['query'], 'productSet'))->values();
        $this->assertCount(2, $sets);
        $second = $sets[1]['variables'];
        $this->assertSame(['id' => 'gid://shopify/Product/777'], $second['identifier']);
        $this->assertSame(['id' => 'gid://shopify/MediaImage/3'], $second['input']['variants'][0]['file']);
        $this->assertSame(['id' => 'gid://shopify/MediaImage/1'], $second['input']['files'][0]);
        $p->refresh();
        $this->assertSame(777, $p->shopify_product_id);
        $this->assertSame(4, DB::table('shopify_media')->count());

        $count = count(Http::recorded());
        $this->artisan('shopify:publish --product=43446')->expectsOutputToContain('1 skip')->assertSuccessful();
        $this->assertCount($count, Http::recorded()); // unchanged: nothing sent

        ProductOverride::query()->create(['product_id' => $p->id, 'data' => ['title' => 'Staple Tee Black Label']]);
        $this->artisan('shopify:publish --product=43446')->expectsOutputToContain('1 update')->assertSuccessful();
        $sets = collect(Http::recorded())->map(fn ($r) => json_decode($r[0]->body(), true))->filter(fn ($b) => str_contains($b['query'], 'productSet'))->values();
        $this->assertCount(3, $sets); // images already known: one productSet call
        $this->assertSame('Staple Tee Black Label [88-5001]', $sets[2]['variables']['input']['title']);
    }

    public function test_preview_sends_nothing_and_live_store_is_blocked(): void
    {
        $this->tee();
        Http::fake();
        $this->artisan('shopify:publish --preview')->expectsOutputToContain('1 preview')->assertSuccessful();
        Http::assertNothingSent();

        config(['shopify.store' => 'merchrep.myshopify.com']);
        $this->artisan('shopify:publish')->expectsOutputToContain('FAILED')->assertFailed();
        Http::assertNothingSent();
        $this->assertStringContainsString('blocked', DB::table('shopify_publish_log')->latest('id')->value('message'));
    }

    public function test_a_picture_shopify_failed_to_load_is_left_out_and_the_product_retried(): void
    {
        $p = $this->tee();
        DB::table('shopify_media')->insert(['product_id' => 43446, 'url_hash' => sha1(self::CDN.'5001_back.jpg'), 'url' => self::CDN.'5001_back.jpg', 'media_gid' => 'gid://shopify/MediaImage/45317247271210', 'created_at' => now(), 'updated_at' => now()]);
        $first = true;
        Http::fake(['cmau-dev.myshopify.com/*' => function ($request) use (&$first) {
            $q = (string) ($request->data()['query'] ?? '');
            if (str_contains($q, 'productSet') && $first) {
                $first = false;

                return Http::response(['data' => ['productSet' => ['product' => null, 'userErrors' => [['field' => ['input', 'files', '1', 'id'], 'message' => 'File with id 45317247271210 is in the FAILED state and cannot be associated with a product.']]]]]);
            }
            if (str_contains($q, 'publications(')) {
                return Http::response(['data' => ['publications' => ['nodes' => [['id' => 'gid://shopify/Publication/9', 'name' => 'Online Store']]]]]);
            }
            if (str_contains($q, 'publishablePublish')) {
                return Http::response(['data' => ['publishablePublish' => ['userErrors' => []]]]);
            }

            return Http::response(['data' => ['productSet' => ['userErrors' => [], 'product' => ['id' => 'gid://shopify/Product/777', 'media' => ['nodes' => [['id' => 'gid://shopify/MediaImage/1']]]]]]]);
        }]);
        $r = app(ProductPublisher::class)->publish($p);
        $this->assertTrue($r['ok'], (string) $r['message']);
        $this->assertStringContainsString('left out 1 picture', $r['message']);
        $this->assertTrue((bool) $p->images()->where('url', self::CDN.'5001_back.jpg')->value('hide_web'));
        $this->assertSame(0, DB::table('shopify_media')->where('media_gid', 'like', '%45317247271210')->count());
        $this->assertSame(777, Product::find(43446)->shopify_product_id);
    }

    public function test_shopify_errors_are_logged(): void
    {
        $this->tee();
        Http::fake(['cmau-dev.myshopify.com/*' => Http::response(['data' => ['productSet' => ['product' => null, 'userErrors' => [['field' => ['input', 'variants', '0', 'price'], 'message' => 'Price is invalid']]]]])]);
        $this->artisan('shopify:publish')->assertFailed();
        $this->assertSame('input.variants.0.price: Price is invalid', DB::table('shopify_publish_log')->value('message'));
        $this->assertNull(Product::find(43446)->shopify_product_id);
    }

    public function test_a_published_product_is_put_on_the_online_store_channel(): void
    {
        $this->fakeShopify();
        $product = $this->tee();

        $result = app(ProductPublisher::class)->publish($product);

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('on Online Store', (string) $result['message']);
        $calls = collect(Http::recorded())->map(fn ($r) => json_decode($r[0]->body(), true));
        $publish = $calls->first(fn ($b) => str_contains($b['query'], 'publishablePublish'));
        $this->assertSame('gid://shopify/Publication/9', $publish['variables']['input'][0]['publicationId']);
    }

    public function test_colour_families_ignore_shades_and_navy_is_blue(): void
    {
        $this->assertSame(['Red', 'Blue'], ProductPayload::families(['Dark Red', 'Navy', 'Light Blue', 'red']));
    }
}
