<?php

namespace Tests\Feature;

use App\Catalogue\ImageTagger;
use App\Models\Product;
use App\Models\ProductOverride;
use App\Shopify\ProductPayload;
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
        Http::fake(['cmau-dev.myshopify.com/*' => function () use (&$n) {
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

        Http::assertSentCount(2); // upload, then link the colour images
        $second = json_decode(Http::recorded()[1][0]->body(), true)['variables'];
        $this->assertSame(['id' => 'gid://shopify/Product/777'], $second['identifier']);
        $this->assertSame(['id' => 'gid://shopify/MediaImage/3'], $second['input']['variants'][0]['file']);
        $this->assertSame(['id' => 'gid://shopify/MediaImage/1'], $second['input']['files'][0]);
        $p->refresh();
        $this->assertSame(777, $p->shopify_product_id);
        $this->assertSame(4, DB::table('shopify_media')->count());

        $this->artisan('shopify:publish --product=43446')->expectsOutputToContain('1 skip')->assertSuccessful();
        Http::assertSentCount(2);

        ProductOverride::query()->create(['product_id' => $p->id, 'data' => ['title' => 'Staple Tee Black Label']]);
        $this->artisan('shopify:publish --product=43446')->expectsOutputToContain('1 update')->assertSuccessful();
        Http::assertSentCount(3); // images already known: one call
        $this->assertSame('Staple Tee Black Label [88-5001]', json_decode(Http::recorded()[2][0]->body(), true)['variables']['input']['title']);
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

    public function test_shopify_errors_are_logged(): void
    {
        $this->tee();
        Http::fake(['cmau-dev.myshopify.com/*' => Http::response(['data' => ['productSet' => ['product' => null, 'userErrors' => [['field' => ['input', 'variants', '0', 'price'], 'message' => 'Price is invalid']]]]])]);
        $this->artisan('shopify:publish')->assertFailed();
        $this->assertSame('input.variants.0.price: Price is invalid', DB::table('shopify_publish_log')->value('message'));
        $this->assertNull(Product::find(43446)->shopify_product_id);
    }
}
