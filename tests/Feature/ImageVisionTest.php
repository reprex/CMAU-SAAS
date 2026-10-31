<?php

namespace Tests\Feature;

use App\Catalogue\ImageTagger;
use App\Catalogue\ImageVision;
use App\Models\ImageCheck;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class ImageVisionTest extends TestCase
{
    use QuoteFixtures, RefreshDatabase;

    private const CDN = 'https://res.cloudinary.com/promodata/JB/';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.admin_allowed_ips' => [], 'images.check.enabled' => true, 'search.anthropic.key' => 'test-key']);
        $this->supplier(12, "JB's Wear");
    }

    /** JB's file names say nothing: 210_1_01 etc. */
    private function polo(): Product
    {
        $p = Product::create(['id' => 35681, 'supplier_id' => 12, 'name' => 'Podium Polo', 'code' => '210', 'tier' => 'live', 'hydrated_at' => now(), 'hero_image_url' => self::CDN.'210_1_01']);
        foreach (['210_1_01', '210_1_02', '210_1_03', '210_1_04', '210_navy_05'] as $i => $f) {
            $p->images()->create(['url' => self::CDN.$f, 'role' => 'other_style', 'position' => $i]);
        }
        $p->colours()->create(['name' => 'Navy', 'image_url' => self::CDN.'col_navy', 'position' => 0]);
        $p->colours()->create(['name' => 'Red', 'image_url' => self::CDN.'col_red', 'position' => 1]);
        app(ImageTagger::class)->apply($p);

        return $p->fresh(['images', 'colours', 'override']);
    }

    /** @param list<array<string, mixed>> $images */
    private function fakeClaude(array $images): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode(['images' => $images])]], 'usage' => ['input_tokens' => 9000, 'output_tokens' => 300]])]);
    }

    public function test_claude_settles_unsure_images_and_flags_disagreements(): void
    {
        $p = $this->polo();
        // Unchecked: 210_1_02..04 and 210_navy_05 (style/colour guesses) plus the hero and the two colour images.
        $this->assertCount(7, app(ImageVision::class)->unchecked($p));

        $this->fakeClaude([
            ['n' => 1, 'tag' => 'other_style', 'colour' => null, 'shows' => 'whole_product', 'confidence' => 0.95, 'note' => ''],   // 210_1_01 hero
            ['n' => 4, 'tag' => 'other_style', 'colour' => null, 'shows' => 'angle', 'confidence' => 0.9, 'note' => ''],           // 02 back of hero colour
            ['n' => 5, 'tag' => 'other_colour', 'colour' => 'Red', 'shows' => 'angle', 'confidence' => 0.88, 'note' => ''],        // 03 actually red
            ['n' => 6, 'tag' => 'other_style', 'colour' => null, 'shows' => 'detail', 'confidence' => 0.4, 'note' => 'blurry'],     // 04 unsure
            ['n' => 7, 'tag' => 'other_colour', 'colour' => 'Red', 'shows' => 'angle', 'confidence' => 0.9, 'note' => 'Looks red'], // navy_05: file name says navy
            ['n' => 2, 'tag' => 'other_colour', 'colour' => 'Navy', 'shows' => 'whole_product', 'confidence' => 0.9, 'note' => ''], // col_navy fine
            ['n' => 3, 'tag' => 'other_colour', 'colour' => 'Navy', 'shows' => 'whole_product', 'confidence' => 0.9, 'note' => ''], // col_red shows navy!
            ['n' => 99, 'tag' => 'other_colour', 'colour' => 'Purple'],                                                            // ignored
        ]);
        $this->assertSame(7, app(ImageVision::class)->check($p));
        app(ImageTagger::class)->apply($p);

        $rows = ProductImage::query()->where('product_id', $p->id)->get()->keyBy(fn ($i) => basename($i->url));
        $this->assertSame(['other_style', 'ai', null], [$rows['210_1_02']->role, $rows['210_1_02']->tagged_by, $rows['210_1_02']->review]);
        $this->assertSame(['other_colour', 'Red', 'ai'], [$rows['210_1_03']->role, $rows['210_1_03']->colour_name, $rows['210_1_03']->tagged_by]);
        $this->assertSame('other_style', $rows['210_1_04']->role);
        $this->assertStringStartsWith('Claude is unsure', $rows['210_1_04']->review);
        $this->assertSame(['other_colour', 'Navy'], [$rows['210_navy_05']->role, $rows['210_navy_05']->colour_name]); // file name kept...
        $this->assertSame('Claude sees Red, the file name says Navy', $rows['210_navy_05']->review);                // ...but flagged
        $this->assertNull($rows['col_navy']->review);
        $this->assertSame('Claude sees Navy, not Red', $rows['col_red']->review);
        $this->assertSame('hero_style', $rows['210_1_01']->role);

        // Each image is only ever checked once, and the request carried the hint and the images by URL.
        $this->assertSame([], app(ImageVision::class)->unchecked($p->fresh(['images', 'colours', 'override'])));
        Http::assertSent(fn ($r) => str_contains(json_encode($r['messages']), 'The file name suggests Navy') && $r['messages'][0]['content'][2]['type'] === 'image');
        Http::assertSentCount(1);

        // The range screen lists the product under "Images to check".
        $this->staff('admin');
        $this->get(route('range.index', ['view' => 'images']))->assertOk()->assertSee('Podium Polo');
    }

    public function test_staff_saving_the_product_clears_the_flags(): void
    {
        $p = $this->polo();
        $this->fakeClaude([['n' => 3, 'tag' => 'other_colour', 'colour' => 'Navy', 'shows' => 'whole_product', 'confidence' => 0.9, 'note' => '']]);
        app(ImageVision::class)->check($p);
        app(ImageTagger::class)->apply($p);
        $this->assertSame(1, ProductImage::query()->whereNotNull('review')->count());

        $this->staff('admin');
        $rows = app(ImageTagger::class)->tags($p->fresh(['images', 'colours', 'override']))->map(fn ($t) => ['url' => $t['url'], 'role' => $t['role'], 'colour' => $t['colour']])->values()->all();
        $this->put(route('range.update', $p), ['images' => $rows])->assertRedirect();

        $this->assertSame(0, ProductImage::query()->whereNotNull('review')->count());
        $this->assertSame(['role' => 'colour', 'colour' => 'Red'], $p->fresh('override')->overrideValue('image_tags')[self::CDN.'col_red']);
    }

    public function test_one_bad_image_is_retried_alone_and_recorded(): void
    {
        $p = $this->polo();
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'Unable to download the file']], 400)]);
        $this->assertSame(0, app(ImageVision::class)->check($p));        // whole batch failed: nothing stored

        config(['images.check.images_per_request' => 1]);
        $this->assertSame(7, app(ImageVision::class)->check($p));        // one at a time: each recorded as could not check
        $this->assertSame(7, ImageCheck::query()->whereNull('tag')->count());
        app(ImageTagger::class)->apply($p);
        $this->assertStringStartsWith('Could not check', (string) ProductImage::query()->where('url', self::CDN.'210_1_02')->value('review'));
    }

    public function test_daily_limit_and_off_switch(): void
    {
        $p = $this->polo();
        config(['images.check.images_per_day' => 0]);
        Http::fake();
        $this->assertSame(0, app(ImageVision::class)->check($p));
        config(['images.check.enabled' => false]);
        $this->artisan('catalogue:check-images')->expectsOutputToContain('off')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_scheduled_run_works_through_the_range(): void
    {
        $this->polo();
        $this->fakeClaude([['n' => 4, 'tag' => 'other_style', 'colour' => null, 'shows' => 'angle', 'confidence' => 0.9, 'note' => '']]);
        $this->artisan('catalogue:check-images')->expectsOutputToContain('on 1 products')->assertSuccessful();
        $this->assertSame(1, ImageCheck::query()->count());
    }
}
