<?php

namespace Tests\Feature;

use App\Catalogue\BrandingTemplates;
use App\Catalogue\PhotoBrandings;
use App\Models\BrandingPosition;
use App\Models\BrandingTemplate;
use App\Models\PhotoBranding;
use App\Models\Product;
use App\Shopify\ProductPayload;
use App\Support\ClaudeLimit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

/** Example branding on mockup photos (Scott, 5 Nov 2026; docs/branding-templates.md). */
class PhotoBrandingTest extends TestCase
{
    use QuoteFixtures, RefreshDatabase;

    private const CDN = 'https://cdn.example/';

    protected function setUp(): void
    {
        parent::setUp();
        config(['search.anthropic.key' => 'test-key', 'images.check.enabled' => true, 'images.branding.enabled' => true]);
        $this->supplier(88, 'Pens Co');
    }

    /** Black has its own photo and a back photo; Navy's colour photo is the hero. */
    private function pen(): Product
    {
        $p = Product::create(['id' => 8443, 'supplier_id' => 88, 'name' => 'Flurr Pen', 'code' => 'P1', 'tier' => 'live', 'hydrated_at' => now(), 'hero_image_url' => self::CDN.'navy.jpg']);
        $p->images()->create(['url' => self::CDN.'navy.jpg', 'role' => 'hero_style', 'position' => 0]);
        $p->images()->create(['url' => self::CDN.'lifestyle.jpg', 'role' => 'other_style', 'position' => 1]);
        $p->colours()->create(['name' => 'Navy', 'image_url' => self::CDN.'navy.jpg', 'position' => 0]);
        $p->colours()->create(['name' => 'Black', 'image_url' => self::CDN.'black.jpg', 'position' => 1]);
        $p->images()->create(['url' => self::CDN.'black.jpg', 'role' => 'colour', 'position' => 2]);
        $p->images()->create(['url' => self::CDN.'black_back.jpg', 'role' => 'other_colour', 'position' => 3]);

        return $p->fresh(['images', 'colours', 'override']);
    }

    /** @param array<string, array<string, mixed>> $byFile file name => Claude's answer */
    private function fakeClaude(array $byFile, ?array $template = null): void
    {
        Http::fake(['api.anthropic.com/*' => function ($request) use ($byFile, $template) {
            if (str_contains((string) $request['system'], 'EXAMPLE BRANDING')) {
                $images = [];
                $n = 0;
                foreach ($request['messages'][0]['content'] as $part) {
                    if ($part['type'] === 'image') {
                        $n++;
                        $images[] = ['n' => $n] + ($byFile[basename($part['source']['url'])] ?? ['branding' => 'none', 'box' => null, 'plain' => true]);
                    }
                }

                return Http::response(['content' => [['type' => 'text', 'text' => json_encode(['images' => $images])]]]);
            }

            return Http::response(['content' => [['type' => 'text', 'text' => json_encode(['positions' => $template ?? []])]]]);
        }]);
    }

    public function test_each_colour_photo_is_checked_once_including_a_colour_photo_that_is_the_hero(): void
    {
        $p = $this->pen();
        $photos = app(PhotoBrandings::class)->photos($p);
        $this->assertSame([['navy.jpg', 'Navy', 'front'], ['black.jpg', 'Black', 'front'], ['black_back.jpg', 'Black', 'back']],
            array_map(fn ($x) => [basename($x['url']), $x['colour'], $x['view']], $photos)); // never the style photo

        $this->fakeClaude(['black.jpg' => ['branding' => 'small', 'box' => [40, 45, 20, 8], 'plain' => true, 'note' => 'sample logo']]);
        $this->artisan('catalogue:check-branding', ['--product' => $p->id])->expectsOutputToContain('Checked 3 photos')->assertSuccessful();
        $this->assertSame('small', PhotoBranding::query()->find(PhotoBranding::hashOf(self::CDN.'black.jpg'))->branding);
        $this->assertSame([40.0, 45.0, 20.0, 8.0], array_map('floatval', PhotoBranding::query()->find(PhotoBranding::hashOf(self::CDN.'black.jpg'))->box));

        Http::fake(); // nothing left to check: no second request
        $this->assertSame([], app(PhotoBrandings::class)->unchecked($p));
        Http::assertNothingSent();
    }

    public function test_the_product_page_is_told_which_photos_carry_a_mockup(): void
    {
        $p = $this->pen();
        $this->fakeClaude([
            'navy.jpg' => ['branding' => 'none', 'box' => null, 'plain' => true],
            'black.jpg' => ['branding' => 'small', 'box' => [40, 44, 20, 6], 'plain' => true],      // inside the barrel area: painted over
            'black_back.jpg' => ['branding' => 'small', 'box' => [5, 5, 10, 10], 'plain' => true],  // where no logo goes: not used
        ]);
        app(PhotoBrandings::class)->checkMany([$p]);
        BrandingPosition::query()->create(['product_id' => $p->id, 'position' => 0, 'name' => 'Barrel', 'view' => 'front', 'image_url' => self::CDN.'black.jpg',
            'left' => 30, 'top' => 40, 'width' => 40, 'max_width_mm' => 50, 'max_height_mm' => 8, 'source' => 'template']);
        BrandingPosition::query()->create(['product_id' => $p->id, 'position' => 1, 'name' => 'Back of barrel', 'view' => 'back', 'image_url' => self::CDN.'black_back.jpg',
            'left' => 30, 'top' => 40, 'width' => 40, 'max_width_mm' => 50, 'max_height_mm' => 8, 'source' => 'template']);

        $mf = collect(app(ProductPayload::class)->build($p->fresh())['input']['metafields'])->keyBy('key');
        $photos = json_decode($mf['mockupphotos']['value'], true);
        $this->assertSame('ok', $photos['Navy']['front']);
        $this->assertSame([38.5, 42.5, 23, 9], $photos['Black']['front']);
        $this->assertSame('skip', $photos['Black']['back']);
    }

    public function test_large_or_textured_branding_and_unchecked_photos_never_carry_a_mockup(): void
    {
        $b = app(PhotoBrandings::class);
        $area = [[30.0, 40.0, 40.0, 10.0]];
        $this->assertSame('skip', $b->verdict(new PhotoBranding(['branding' => 'large', 'box' => [35, 42, 20, 5], 'plain' => true]), $area));
        $this->assertSame('skip', $b->verdict(new PhotoBranding(['branding' => 'small', 'box' => [35, 42, 20, 5], 'plain' => false]), $area));
        $this->assertSame('skip', $b->verdict(new PhotoBranding(['branding' => null, 'box' => null, 'plain' => null]), $area)); // could not check
        $this->assertSame('ok', $b->verdict(new PhotoBranding(['branding' => 'none']), $area));

        $p = $this->pen();
        $mf = collect(app(ProductPayload::class)->build($p)['input']['metafields'])->keyBy('key');
        $this->assertArrayNotHasKey('mockupphotos', $mf->all()); // nothing checked: the page draws no mockup on them
        $this->assertFalse($b->usable(self::CDN.'black.jpg'));
    }

    public function test_a_template_is_placed_on_a_cleared_photo_checked_first_and_a_clean_hero_colour_photo_counts(): void
    {
        $p = $this->pen();
        $t = BrandingTemplate::query()->create(['product_id' => $p->id, 'url' => 'https://cdn.example/p1-template.pdf', 'source' => 'promodata']);
        $this->fakeClaude(['black.jpg' => ['branding' => 'large', 'box' => [10, 10, 80, 60], 'plain' => true]],
            [['name' => 'Barrel', 'view' => 'front', 'max_width_mm' => 50, 'max_height_mm' => 8, 'methods' => ['Pad print'], 'box' => ['left' => 30, 'top' => 40, 'width' => 40], 'confidence' => 'high']]);

        app(BrandingTemplates::class)->read($t);

        // Black's photo has large example branding: the template is placed on Navy's (the hero, clean).
        $this->assertSame(self::CDN.'navy.jpg', BrandingPosition::query()->where('product_id', $p->id)->value('image_url'));
        $this->assertSame(3, PhotoBranding::query()->count()); // checked before the read
    }

    public function test_only_failed_templates_are_queued_again(): void
    {
        $p = $this->pen();
        BrandingTemplate::query()->create(['product_id' => $p->id, 'url' => 'https://cdn.example/a.pdf', 'source' => 'promodata', 'status' => 'failed', 'message' => 'Claude reply was not usable: reply cut off (too long)']);
        BrandingTemplate::query()->create(['product_id' => $p->id, 'url' => 'https://cdn.example/b.pdf', 'source' => 'promodata', 'status' => 'read']);
        $this->artisan('catalogue:read-templates', ['--retry-failed' => true])->expectsOutputToContain('1 failed templates')->assertSuccessful();
        $this->assertSame(['new', 'read'], BrandingTemplate::query()->orderBy('url')->pluck('status')->all());
    }

    public function test_status_at_a_glance(): void
    {
        $p = $this->pen();
        BrandingTemplate::query()->create(['product_id' => $p->id, 'url' => 'https://cdn.example/a.pdf', 'source' => 'promodata', 'status' => 'new']);
        BrandingTemplate::query()->create(['product_id' => $p->id, 'url' => 'https://cdn.example/b.pdf', 'source' => 'promodata', 'status' => 'failed', 'message' => 'reply cut off']);
        $this->artisan('saas:status')->expectsOutputToContain('Templates: ')->expectsOutputToContain('Failed 8443: reply cut off')->expectsOutputToContain('Photos checked: 0')->assertSuccessful();
    }

    public function test_a_template_that_stopped_the_reader_is_set_aside_and_the_queue_moves_on(): void
    {
        $p = $this->pen();
        $stuck = BrandingTemplate::query()->create(['product_id' => $p->id, 'url' => 'https://cdn.example/big.pdf', 'source' => 'promodata', 'status' => 'reading']);
        BrandingTemplate::query()->whereKey($stuck->id)->update(['updated_at' => now()->subMinutes(20)]);
        $next = BrandingTemplate::query()->create(['product_id' => $p->id, 'url' => 'https://cdn.example/next.pdf', 'source' => 'promodata', 'status' => 'new']);
        $this->fakeClaude([], [['name' => 'Barrel', 'view' => 'front', 'max_width_mm' => 50, 'max_height_mm' => 8, 'methods' => [], 'box' => null, 'confidence' => 'low']]);

        $this->artisan('catalogue:read-templates', ['--limit' => 5])->expectsOutputToContain('set aside as failed')->assertSuccessful();
        $this->assertSame('failed', $stuck->fresh()->status);
        $this->assertSame('read', $next->fresh()->status);
    }

    public function test_an_api_limit_pauses_everything_and_marks_nothing_failed(): void
    {
        $p = $this->pen();
        $t = BrandingTemplate::query()->create(['product_id' => $p->id, 'url' => 'https://cdn.example/p1.pdf', 'source' => 'promodata', 'status' => 'new']);
        Http::fake(['api.anthropic.com/*' => Http::response(['type' => 'error', 'error' => ['type' => 'invalid_request_error',
            'message' => 'You have reached your specified API usage limits. You will regain access on 2026-12-01 at 00:00 UTC.']], 400)]);

        $this->artisan('catalogue:read-templates', ['--limit' => 5])->assertSuccessful();
        $this->assertSame('new', $t->fresh()->status);                     // back in the queue, not failed
        $this->assertSame(0, PhotoBranding::query()->count());              // no photo recorded as "could not load"
        $this->assertTrue(ClaudeLimit::paused());
        $this->artisan('saas:status')->expectsOutputToContain('Paused (Claude API limit)')->assertSuccessful();

        Http::fake();
        $this->artisan('catalogue:read-templates', ['--limit' => 5])->assertSuccessful();
        Http::assertNothingSent();                                          // while paused, nothing is tried
    }
}
