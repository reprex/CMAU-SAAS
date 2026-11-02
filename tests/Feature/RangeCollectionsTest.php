<?php

namespace Tests\Feature;

use App\Shopify\ProductPayload;
use App\Shopify\RangeCollections;
use App\Shopify\ShopifyAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class RangeCollectionsTest extends TestCase
{
    use QuoteFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shopify.store' => 'cmau-dev.myshopify.com', 'shopify.dev_store' => 'cmau-dev.myshopify.com', 'shopify.token' => 'test', 'shopify.allow_live' => false]);
        $this->supplier(53, 'Headwear Co');
    }

    public function test_tags_follow_the_tod_style(): void
    {
        $this->assertSame('dufflesportsbags', RangeCollections::slug(RangeCollections::subcategory('Bags > Duffle/Sports Bags')));
        $this->assertSame('fooddrink', RangeCollections::slug(RangeCollections::subcategory('Food > Other Food & Drink')));
        $this->assertSame('drinkbottles', RangeCollections::slug(RangeCollections::subcategory('Drinkware > Drink Bottles')));
        $this->assertSame('tshirts', RangeCollections::slug('T-Shirts'));
    }

    public function test_sync_matches_existing_collections_and_creates_the_rest(): void
    {
        foreach ([1 => 'Bags > Duffle/Sports Bags', 2 => 'Drinkware > Drink Bottles', 3 => 'Drinkware > Drink Bottles'] as $id => $type) {
            $this->catalogueProduct($id, 53, "P{$id}", "C{$id}")->forceFill(['promodata_type_name' => $type])->save();
        }
        $created = [];
        Http::fake(function ($req) use (&$created) {
            $q = $req['query'] ?? '';
            if (str_contains($q, 'collections(first')) {
                return Http::response(['data' => ['collections' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'nodes' => [
                    ['id' => 'gid://shopify/Collection/1', 'title' => 'Duffle/Sports Bags', 'handle' => 'custom-printed-dufflesportsbags', 'sortOrder' => 'MANUAL', 'templateSuffix' => 'col_as_variant',
                        'ruleSet' => ['rules' => [['column' => 'TAG', 'relation' => 'EQUALS', 'condition' => 'dufflesportsbags']]]],
                ]]]]);
            }
            if (str_contains($q, 'collectionCreate')) {
                $input = json_decode($req->body(), true)['variables']['input'];
                $created[] = $input;

                return Http::response(['data' => ['collectionCreate' => ['collection' => ['id' => 'gid://shopify/Collection/2', 'handle' => $input['handle']], 'userErrors' => []]]]);
            }
            if (str_contains($q, 'publications')) {
                return Http::response(['data' => ['publications' => ['nodes' => [['id' => 'gid://shopify/Publication/9', 'name' => 'Online Store']]]]]);
            }

            return Http::response(['data' => ['publishablePublish' => ['userErrors' => []]]]);
        });
        $this->mock(ShopifyAdmin::class, function ($m) {
            $m->makePartial();
        });

        $rows = app(RangeCollections::class)->sync(true);
        $this->assertSame(['created', 'existing'], collect($rows)->pluck('status')->sort()->values()->all());
        $this->assertCount(1, $created);
        $this->assertSame('drinkbottles', $created[0]['ruleSet']['rules'][0]['condition']);
        $this->assertSame('MANUAL', $created[0]['sortOrder']);
        $this->assertSame('col_as_variant', $created[0]['templateSuffix']);
        $this->assertSame('dufflesportsbags', DB::table('shopify_collections')->where('product_type', 'Bags > Duffle/Sports Bags')->value('tag'));
    }

    public function test_products_carry_their_subcategory_tag(): void
    {
        $p = $this->catalogueProduct(7, 53, 'Bottle', 'B7');
        $p->forceFill(['promodata_type_name' => 'Drinkware > Drink Bottles'])->save();
        $input = app(ProductPayload::class)->build($p->fresh())['input'];
        $this->assertContains('drinkbottles', $input['tags']);
        $mf = collect($input['metafields'])->firstWhere('key', 'tagsubcategory');
        $this->assertSame('any,drinkbottles', $mf['value']);
    }
}
