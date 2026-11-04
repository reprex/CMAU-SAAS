<?php

namespace Tests\Feature;

use App\Catalogue\BrandingTemplates;
use App\Catalogue\ImageTagger;
use App\Catalogue\LogoAreaLearner;
use App\Catalogue\PositionResolver;
use App\Catalogue\PrintAreas;
use App\Models\BrandingPosition;
use App\Models\BrandingTemplate;
use App\Models\PrintArea;
use App\Models\Product;
use App\Shopify\ProductPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class BrandingTemplateTest extends TestCase
{
    use QuoteFixtures, RefreshDatabase;

    private const CDN = 'https://res.cloudinary.com/promodata/as-colour/';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.admin_allowed_ips' => [], 'search.anthropic.key' => 'test-key', 'images.check.enabled' => true]);
        $this->supplier(88, 'AS Colour');
    }

    private function tee(): Product
    {
        $p = Product::create(['id' => 43446, 'supplier_id' => 88, 'name' => 'Staple Tee', 'code' => '5001', 'promodata_type_name' => 'T-Shirts', 'tier' => 'live', 'hydrated_at' => now(), 'hero_image_url' => self::CDN.'5001_main.jpg']);
        $p->images()->create(['url' => self::CDN.'5001_main.jpg', 'role' => 'hero_style', 'position' => 0]);
        $p->images()->create(['url' => self::CDN.'5001_back.jpg', 'role' => 'other_style', 'position' => 1]);
        $p->images()->create(['url' => 'https://cdn.example/5001-template.pdf', 'role' => 'line_art', 'position' => 2]);

        return $p;
    }

    private function fakeClaude(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode(['positions' => [
            ['name' => 'Front centre', 'view' => 'front', 'max_width_mm' => 280, 'max_height_mm' => 350, 'methods' => ['Screen print', 'DTF'], 'box' => ['left' => 35, 'top' => 28, 'width' => 30]],
            ['name' => 'Left chest', 'view' => 'front', 'max_width_mm' => 90, 'max_height_mm' => 90, 'methods' => ['Embroidery'], 'box' => ['left' => 57, 'top' => 29, 'width' => 12]],
            ['name' => 'Back', 'view' => 'back', 'max_width_mm' => 280, 'max_height_mm' => 350, 'methods' => [], 'box' => ['left' => 33, 'top' => 22, 'width' => 34]],
            ['name' => 'Right sleeve', 'view' => 'side', 'max_width_mm' => 70, 'max_height_mm' => 70, 'methods' => [], 'box' => ['left' => 1, 'top' => 1, 'width' => 5]],
        ]])]]])]);
    }

    public function test_line_art_is_kept_apart_from_photos(): void
    {
        $p = $this->tee();
        $tags = app(ImageTagger::class)->tags($p->fresh(['images', 'colours', 'override']));
        $this->assertNotContains('https://cdn.example/5001-template.pdf', $tags->pluck('url')->all());
    }

    public function test_reading_a_template_gives_positions_logo_area_and_shopify_fields(): void
    {
        $p = $this->tee();
        $t = BrandingTemplate::query()->create(['product_id' => $p->id, 'url' => 'https://cdn.example/5001-template.pdf', 'source' => 'promodata']);
        $this->fakeClaude();

        $this->assertSame(4, app(BrandingTemplates::class)->read($t));
        Http::assertSent(fn ($r) => $r['messages'][0]['content'][1]['type'] === 'document'
            && str_contains(json_encode($r['messages']), '5001_back.jpg'));

        $back = BrandingPosition::query()->where('name', 'Back')->first();
        $this->assertSame(self::CDN.'5001_back.jpg', $back->image_url);
        $this->assertNull(BrandingPosition::query()->where('name', 'Right sleeve')->value('image_url')); // no side picture: no box
        $area = PrintArea::query()->find(sha1(self::CDN.'5001_main.jpg'));
        $this->assertSame(['template', 35.0, 28.0, 30.0], [$area->source, $area->left, $area->top, $area->width]);
        $this->assertSame(35.0, app(PrintAreas::class)->forShopify($p)['left']); // published without vetting

        $mf = collect(app(ProductPayload::class)->build($p->fresh())['input']['metafields'])->keyBy('key');
        $positions = json_decode($mf['brandingpositions']['value'], true);
        $this->assertSame(['Front centre', 'Left chest', 'Back', 'Right sleeve'], array_column($positions, 'name'));
        $this->assertSame([280, 350], $positions[0]['max_mm']);
        // Only whether a template is available goes to Shopify, never its address (Scott, 3 Nov 2026).
        $this->assertSame(['boolean', 'true'], [$mf['hasbrandingtemplate']['type'], $mf['hasbrandingtemplate']['value']]);
        $this->assertArrayNotHasKey('brandingtemplate', $mf->all());
        $this->assertStringNotContainsString('5001-template.pdf', json_encode(app(ProductPayload::class)->build($p->fresh())));
    }

    public function test_sample_learning_leaves_template_areas_alone(): void
    {
        $p = $this->tee();
        $this->fakeClaude();
        app(BrandingTemplates::class)->read(BrandingTemplate::query()->create(['product_id' => $p->id, 'url' => 'https://cdn.example/t.pdf']));
        PrintArea::query()->create(['url_hash' => sha1('x'), 'url' => 'x', 'product_id' => 999, 'group_key' => $p->supplier_id.'|t-shirts',
            'left' => 50, 'top' => 30, 'width' => 10, 'pred_left' => 37, 'pred_top' => 30, 'pred_width' => 26, 'source' => 'staff', 'approved_at' => now()]);
        PrintArea::query()->create(['url_hash' => sha1('y'), 'url' => 'y', 'product_id' => 998, 'group_key' => $p->supplier_id.'|t-shirts',
            'left' => 50, 'top' => 30, 'width' => 10, 'pred_left' => 37, 'pred_top' => 30, 'pred_width' => 26, 'source' => 'staff', 'approved_at' => now()]);
        app(LogoAreaLearner::class)->learn();
        $this->assertSame(35.0, PrintArea::query()->find(sha1(self::CDN.'5001_main.jpg'))->left);
    }

    public function test_supplier_pattern_and_the_product_screen(): void
    {
        $p = $this->tee();
        $p->supplier->update(['template_url_pattern' => 'https://ascolour.example/templates/{code}.pdf']);
        Http::fake(['ascolour.example/*' => Http::response('', 200)]);
        $t = app(BrandingTemplates::class)->discover($p->fresh('supplier'));
        $this->assertSame('https://ascolour.example/templates/5001.pdf', $t->url);
        $this->assertSame('supplier', $t->source);

        $this->staff('sales');
        $this->get(route('range.show', $p))->assertOk()->assertSee('Branding template')->assertSee('5001.pdf')->assertSee('To read');
        $this->post(route('range.templates.store', $p), ['url' => 'https://cdn.example/other.pdf'])->assertRedirect();
        $this->assertSame(2, BrandingTemplate::query()->where('product_id', $p->id)->count());
    }

    public function test_all_templates_are_read_in_parallel_with_each_methods_size_and_only_trusted_boxes(): void
    {
        $this->supplier(3, 'Trends');
        foreach ([105947, 105948, 105949] as $id) {
            $p = Product::create(['id' => $id, 'supplier_id' => 3, 'name' => 'Vulcan Pen', 'code' => (string) $id, 'tier' => 'live', 'hydrated_at' => now(), 'hero_image_url' => "https://cdn.example/{$id}.jpg"]);
            $p->images()->create(['url' => "https://cdn.example/{$id}.jpg", 'role' => 'hero_style', 'position' => 0]);
            BrandingTemplate::query()->create(['product_id' => $id, 'url' => "https://cdn.example/{$id}.pdf", 'source' => 'file']);
        }
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode(['positions' => [
            ['name' => 'Barrel', 'view' => 'front', 'max_width_mm' => null, 'max_height_mm' => null, 'confidence' => 'high',
                'methods' => [['name' => 'Screen print', 'max_width_mm' => 55, 'max_height_mm' => 12], ['name' => 'Pad print', 'max_width_mm' => 60, 'max_height_mm' => 7]], 'box' => null,
                'quad' => [[30, 50], [68, 38], [70, 43], [32, 55]], 'surface' => 'cylinder_h'],
            ['name' => 'Cap', 'view' => 'front', 'confidence' => 'high', 'methods' => [], 'quad' => [[10, 10], [20, 20], [20, 10], [10, 20]], 'box' => null],
            ['name' => 'Clip', 'view' => 'front', 'max_width_mm' => 30, 'max_height_mm' => 5, 'confidence' => 'low', 'methods' => ['Pad print'], 'box' => ['left' => 70, 'top' => 40, 'width' => 10]],
        ]])]]])]);

        $this->artisan('catalogue:read-templates', ['--all' => true, '--parallel' => 2])->expectsOutputToContain('Read 3 templates (9 positions)')->assertSuccessful();

        $this->assertSame(3, BrandingTemplate::query()->where('status', 'read')->count());
        Http::assertSent(fn ($r) => $r['model'] === 'claude-sonnet-5-5' && str_contains($r['system'], 'never by the template'));
        $barrel = BrandingPosition::query()->where('product_id', 105947)->where('name', 'Barrel')->sole();
        $this->assertSame([[55, 12], ['Screen print' => [55, 12], 'Pad print' => [60, 7]], 30.0, 38.0, 40.0], [[$barrel->max_width_mm, $barrel->max_height_mm], $barrel->method_sizes, $barrel->left, $barrel->top, $barrel->width]); // the corners' bounding box
        $this->assertSame([[[30, 50], [68, 38], [70, 43], [32, 55]], 'cylinder_h'], [$barrel->quad, $barrel->surface]);
        $this->assertNull(BrandingPosition::query()->where('product_id', 105947)->where('name', 'Cap')->value('quad')); // crossed corners: no mockup
        $this->assertNull(BrandingPosition::query()->where('product_id', 105947)->where('name', 'Clip')->value('left')); // low confidence: a guide card

        $areas = collect(app(PositionResolver::class)->forShopify(Product::find(105947)))->keyBy('name');
        $this->assertSame([[55, 12], [60, 7]], array_column($areas['Barrel']['method_areas'], 'max_mm'));
        $this->assertArrayNotHasKey('method_sizes', $areas['Barrel']);
        $this->assertSame('cylinder_h', $areas['Barrel']['surface']);
        $this->assertSame([68, 38], $areas['Barrel']['quad'][1]);

        $this->artisan('catalogue:read-templates', ['--all' => true])->expectsOutputToContain('Read 0 templates')->assertSuccessful();
        $this->travel(1)->minutes(); // read again with newer reading rules
        $this->artisan('catalogue:read-templates', ['--all' => true, '--again' => true, '--supplier' => 3])->expectsOutputToContain('Read 3 templates')->assertSuccessful();
    }
}
