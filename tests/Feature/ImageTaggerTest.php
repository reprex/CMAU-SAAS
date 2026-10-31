<?php

namespace Tests\Feature;

use App\Catalogue\ImageTagger;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductOverride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class ImageTaggerTest extends TestCase
{
    use QuoteFixtures, RefreshDatabase;

    private const CDN = 'https://res.cloudinary.com/promodata/as-colour/';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.admin_allowed_ips' => []]);
        $this->supplier(88, 'AS Colour');
    }

    /** The AS Colour Maple Tee as Promodata sends it. */
    private function mapleTee(): Product
    {
        $p = Product::create(['id' => 43331, 'supplier_id' => 88, 'name' => 'Maple Tee', 'code' => '4001', 'tier' => 'live', 'hydrated_at' => now(),
            'hero_image_url' => self::CDN.'4001_maple_tee_main__87044.jpg']);
        foreach (['4001_maple_tee_main__87044.jpg', '4001_maple_tee_pistacio_back__89157.jpg', '4001_maple_tee_back__90295.jpg', '4001_maple_tee_side__57201.jpg', '4001_maple_tee_navy_white_detail__1.jpg'] as $i => $f) {
            $p->images()->create(['url' => self::CDN.$f, 'role' => $i ? 'other_style' : 'hero_style', 'position' => $i]);
        }
        foreach (['Pistachio' => 'pistachio.jpg', 'Navy' => 'navy.jpg', 'Navy/White' => 'navy-white.jpg', 'Natural' => 'natural.jpg'] as $i => $name) {
            $p->colours()->create(['name' => $i, 'image_url' => self::CDN.'colours/'.$name, 'position' => 0]);
        }

        return $p;
    }

    private function tagsOf(Product $p): array
    {
        return app(ImageTagger::class)->tags($p->fresh(['images', 'colours', 'override']))
            ->mapWithKeys(fn ($t) => [basename($t['url']) => $t['role'].($t['colour'] ? ':'.$t['colour'] : '')])->all();
    }

    public function test_automatic_tags(): void
    {
        $p = $this->mapleTee();
        app(ImageTagger::class)->apply($p);

        $this->assertSame([
            '4001_maple_tee_main__87044.jpg' => 'hero_style',
            '4001_maple_tee_pistacio_back__89157.jpg' => 'other_colour:Pistachio', // misspelt in the file name, still matched
            '4001_maple_tee_back__90295.jpg' => 'other_style',
            '4001_maple_tee_side__57201.jpg' => 'other_style',
            '4001_maple_tee_navy_white_detail__1.jpg' => 'other_colour:Navy/White', // the longer colour name wins over "Navy"
            'pistachio.jpg' => 'colour:Pistachio',
            'navy.jpg' => 'colour:Navy',
            'navy-white.jpg' => 'colour:Navy/White',
            'natural.jpg' => 'colour:Natural',
        ], $this->tagsOf($p));
        $this->assertSame(9, ProductImage::query()->where('product_id', $p->id)->count()); // colour images added as rows
        $this->assertSame('Pistachio', ProductImage::query()->where('url', self::CDN.'4001_maple_tee_pistacio_back__89157.jpg')->value('colour_name'));
    }

    public function test_colour_word_in_the_product_name_is_not_a_colour_match(): void
    {
        $p = Product::create(['id' => 9, 'supplier_id' => 88, 'name' => 'Natural Jute Bag', 'code' => 'NJ1', 'tier' => 'live', 'hydrated_at' => now()]);
        $p->images()->create(['url' => self::CDN.'natural_jute_bag_front.jpg', 'role' => 'other_style', 'position' => 0]);
        $p->images()->create(['url' => self::CDN.'natural_jute_bag_back.jpg', 'role' => 'other_style', 'position' => 1]);
        $p->colours()->create(['name' => 'Natural', 'image_url' => null, 'position' => 0]);

        $this->assertSame(['natural_jute_bag_front.jpg' => 'hero_style', 'natural_jute_bag_back.jpg' => 'other_style'], $this->tagsOf($p));
    }

    public function test_hero_falls_back_to_a_colour_image_and_stays_that_colours_image(): void
    {
        $p = Product::create(['id' => 1430, 'supplier_id' => 88, 'name' => 'Cup', 'code' => 'LL0100', 'tier' => 'live', 'hydrated_at' => now(), 'hero_image_url' => self::CDN.'LL0100_White.jpg']);
        $p->colours()->create(['name' => 'White', 'image_url' => self::CDN.'LL0100_White.jpg', 'position' => 0]);
        $p->colours()->create(['name' => 'Black', 'image_url' => self::CDN.'LL0100_Black.jpg', 'position' => 1]);
        app(ImageTagger::class)->apply($p);

        $this->assertSame(['LL0100_White.jpg' => 'hero_style:White', 'LL0100_Black.jpg' => 'colour:Black'], $this->tagsOf($p));
        $out = app(ImageTagger::class)->forShopify($p->fresh(['images', 'colours', 'override']));
        $this->assertSame(self::CDN.'LL0100_White.jpg', $out['colours']['White']['image']);
        $this->assertSame([self::CDN.'LL0100_White.jpg', self::CDN.'LL0100_Black.jpg'], $out['media']);
    }

    public function test_shopify_output_orders_media_and_groups_colour_images(): void
    {
        $p = $this->mapleTee();
        ProductOverride::query()->create(['product_id' => $p->id, 'data' => ['hidden_colours' => ['Natural']]]);
        app(ImageTagger::class)->apply($p);
        $out = app(ImageTagger::class)->forShopify($p->fresh(['images', 'colours', 'override']));

        $this->assertSame([self::CDN.'4001_maple_tee_main__87044.jpg', self::CDN.'4001_maple_tee_back__90295.jpg', self::CDN.'4001_maple_tee_side__57201.jpg'], $out['style']);
        $this->assertSame(['Pistachio', 'Navy', 'Navy/White'], array_keys($out['colours']));        // hidden colour left out
        $this->assertSame([self::CDN.'4001_maple_tee_pistacio_back__89157.jpg'], $out['colours']['Pistachio']['other']);
        $this->assertSame(self::CDN.'colours/pistachio.jpg', $out['colours']['Pistachio']['image']);
        $this->assertCount(6, $out['media']);                                                         // 3 style + 3 colour images
        $this->assertNotContains(self::CDN.'4001_maple_tee_pistacio_back__89157.jpg', $out['media']); // other colour stays out of product media
    }

    public function test_staff_tags_win_and_only_differences_are_stored(): void
    {
        $p = $this->mapleTee();
        app(ImageTagger::class)->apply($p);
        $this->staff('admin');
        $rows = app(ImageTagger::class)->tags($p->fresh(['images', 'colours', 'override']))
            ->map(fn ($t) => ['url' => $t['url'], 'role' => $t['role'], 'colour' => $t['colour']])->values()->all();
        foreach ($rows as &$r) {
            if (str_contains($r['url'], 'tee_side')) {
                $r = ['role' => 'hero_style', 'colour' => ''] + $r;         // new hero
            }
            if (str_contains($r['url'], 'tee_back__')) {
                $r = ['role' => 'other_colour', 'colour' => 'Navy'] + $r;   // staff say this back view is navy
            }
            if (str_contains($r['url'], 'tee_main')) {
                $r = ['role' => 'other_style'] + $r;                        // old hero (the JS does this too)
            }
        }
        unset($r);

        $this->put(route('range.update', $p), ['images' => $rows])->assertRedirect();
        $p = $p->fresh(['images', 'colours', 'override']);

        $this->assertSame(self::CDN.'4001_maple_tee_side__57201.jpg', $p->overrideValue('hero_image_url'));
        $this->assertSame([self::CDN.'4001_maple_tee_back__90295.jpg' => ['role' => 'other_colour', 'colour' => 'Navy']], $p->overrideValue('image_tags'));
        $tags = $this->tagsOf($p);
        $this->assertSame('hero_style', $tags['4001_maple_tee_side__57201.jpg']);
        $this->assertSame('other_style', $tags['4001_maple_tee_main__87044.jpg']);
        $this->assertSame('other_colour:Navy', $tags['4001_maple_tee_back__90295.jpg']);
        $this->assertSame('staff', ProductImage::query()->where('url', self::CDN.'4001_maple_tee_back__90295.jpg')->value('tagged_by'));

        $this->get(route('range.show', $p))->assertOk()->assertSee('Set by staff')->assertSee('Other colour');
    }

    public function test_tag_images_command(): void
    {
        $p = $this->mapleTee();
        $this->artisan('catalogue:tag-images')->expectsOutputToContain('1 products')->assertSuccessful();
        $this->assertSame('other_colour', ProductImage::query()->where('url', self::CDN.'4001_maple_tee_pistacio_back__89157.jpg')->value('role'));
    }

    public function test_products_without_colours_get_one_custom_print_variant(): void
    {
        $p = Product::create(['id' => 60602, 'supplier_id' => 88, 'name' => 'Custom Lanyard', 'code' => 'LL5098', 'tier' => 'live', 'hydrated_at' => now(), 'hero_image_url' => self::CDN.'ll5098.jpg']);
        $p->images()->create(['url' => self::CDN.'ll5098.jpg', 'role' => 'hero_style', 'position' => 0]);
        $p->images()->create(['url' => self::CDN.'ll5098_detail.jpg', 'role' => 'other_style', 'position' => 1]);
        app(ImageTagger::class)->apply($p);
        $out = app(ImageTagger::class)->forShopify($p->fresh(['images', 'colours', 'override']));

        $this->assertSame([ImageTagger::CUSTOM_PRINT => ['image' => self::CDN.'ll5098.jpg', 'other' => []]], $out['colours']);
        $this->assertSame([self::CDN.'ll5098.jpg', self::CDN.'ll5098_detail.jpg'], $out['media']);

        // A colour with no picture of its own shows the hero instead of being dropped.
        $p->colours()->create(['name' => 'Blue', 'image_url' => null, 'position' => 0]);
        $out = app(ImageTagger::class)->forShopify($p->fresh(['images', 'colours', 'override']));
        $this->assertSame(['Blue' => ['image' => self::CDN.'ll5098.jpg', 'other' => []]], $out['colours']);
    }
}
