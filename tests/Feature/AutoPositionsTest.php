<?php

namespace Tests\Feature;

use App\Catalogue\BrandingTemplates;
use App\Catalogue\LogoAreaLearner;
use App\Catalogue\PositionResolver;
use App\Catalogue\PrintAreas;
use App\Models\BrandingTemplate;
use App\Models\PriceOption;
use App\Models\PrintArea;
use App\Models\Product;
use App\Shopify\ProductPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

/**
 * Branding positions and logo areas without manual vetting (Scott, 1 Nov 2026):
 * staff-approved area, then template, then the print area in Promodata's
 * decoration data, then the product type default.
 */
class AutoPositionsTest extends TestCase
{
    use QuoteFixtures, RefreshDatabase;

    private const STUBBY_HERO = 'https://res.cloudinary.com/promodata/trends/104743-hero.jpg';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.admin_allowed_ips' => [], 'search.anthropic.key' => 'test-key', 'images.check.enabled' => true]);
        $this->supplier(3, 'Trends Collection');
        $this->supplier(88, 'AS Colour');
    }

    /** Trends Neoprene Stubby Holder 104743, decorated as on its live product page. */
    private function stubby(array $attrs = []): Product
    {
        $p = Product::create($attrs + ['id' => 2119, 'supplier_id' => 3, 'name' => 'Neoprene Stubby Holder', 'code' => '104743', 'promodata_type_name' => 'Stubby Holders',
            'tier' => 'live', 'hydrated_at' => now(), 'hero_image_url' => self::STUBBY_HERO]);
        $p->images()->create(['url' => self::STUBBY_HERO, 'role' => 'hero_style', 'position' => 0]);
        $p->priceLines()->create(['position' => 0, 'type' => 'Decoration', 'description' => 'Full colour printing (sublimation print area: 209mm x 108mm)', 'undecorated' => false, 'promodata_decoration' => 'Sublimation', 'setup_cost' => 0]);
        $p->priceLines()->create(['position' => 1, 'type' => 'Unbranded', 'description' => 'Unbranded', 'undecorated' => true, 'setup_cost' => 0]);
        PriceOption::create(['product_id' => 2119, 'option_id' => '2119-sub', 'code' => 'sub', 'kind' => 'PDADEC', 'type_code' => 10, 'description' => 'Full colour printing (sublimation print area: 209mm x 108mm)', 'price_string' => '25:1.90']);

        return $p;
    }

    private function tee(): Product
    {
        $hero = 'https://res.cloudinary.com/promodata/as-colour/5001_main.jpg';
        $p = Product::create(['id' => 43446, 'supplier_id' => 88, 'name' => 'Staple Tee', 'code' => '5001', 'promodata_type_name' => 'T-Shirts', 'tier' => 'live', 'hydrated_at' => now(), 'hero_image_url' => $hero]);
        $p->images()->create(['url' => $hero, 'role' => 'hero_style', 'position' => 0]);
        $p->images()->create(['url' => 'https://res.cloudinary.com/promodata/as-colour/5001_back.jpg', 'role' => 'other_style', 'position' => 1]);
        $add = fn (string $code, string $desc) => PriceOption::create(['product_id' => 43446, 'option_id' => "43446-{$code}", 'code' => $code, 'kind' => 'PDADEC', 'type_code' => 10, 'description' => $desc, 'price_string' => '1:7.75']);
        $add('dtf', 'DTF print up to A3');
        $add('oversizedtf', 'Oversize DTF transfer up to 380 x 480mm');
        $add('screen1', 'Screen print 1 colour');
        $add('embroidery', 'Embroidery up to 8000 stitches');
        $p->detail()->create(['details' => [['name' => 'Print Area', 'detail' => 'Back: 280 x 350mm'], ['name' => 'Dimensions', 'detail' => 'S-5XL']]]);

        return $p;
    }

    public function test_the_stubby_holder_gets_one_wrap_around_sublimation_position_209_by_108(): void
    {
        $p = $this->stubby();
        $positions = app(PositionResolver::class)->forShopify($p);

        $this->assertCount(1, $positions);
        $wrap = $positions[0];
        $this->assertSame(['Wrap around', 'front', [209, 108], ['Sublimation'], 'decoration'], [$wrap['name'], $wrap['view'], $wrap['max_mm'], $wrap['methods'], $wrap['source']]);
        $this->assertSame([['method' => 'sublimation', 'name' => 'Sublimation', 'max_mm' => [209, 108], 'decIDs' => ['2119-sub']]], $wrap['method_areas']);
        // No dimensions from Promodata: the stubby type default box, on the hero image.
        $this->assertSame([self::STUBBY_HERO, 32.0, 36.0, 36.0], [$wrap['image'], $wrap['left'], $wrap['top'], $wrap['width']]);

        // The same box is the logo area, published without vetting, and in the Shopify payload.
        $this->assertSame(['image' => self::STUBBY_HERO, 'left' => 32.0, 'top' => 36.0, 'width' => 36.0], app(PrintAreas::class)->forShopify($p));
        $mf = collect(app(ProductPayload::class)->build($p->fresh())['input']['metafields'])->keyBy('key');
        $this->assertSame('Wrap around', json_decode($mf['brandingpositions']['value'], true)[0]['name']);
        $this->assertSame(32, json_decode($mf['printarea']['value'], true)['left']);
        $this->assertSame('3-104743', $mf['ourcode']['value']);
        $this->assertSame('104743', $mf['supplierproductcode']['value']);
    }

    public function test_the_box_is_scaled_by_the_print_width_against_the_product_size(): void
    {
        // A 105 mm tall holder: the wrap's visible front is about 209 / π = 66.5 mm, 66.5 / 105 × 80% = 50.7% of the picture.
        $p = $this->stubby(['dim_1' => 105, 'dim_2' => 70]);
        $wrap = app(PositionResolver::class)->forShopify($p)[0];
        $this->assertEqualsWithDelta(50.7, $wrap['width'], 0.1);
        $this->assertEqualsWithDelta(50.0, $wrap['left'] + $wrap['width'] / 2, 0.01); // kept on the default's centre
    }

    public function test_each_position_carries_its_methods_and_each_methods_own_max_size(): void
    {
        $p = $this->tee();
        $positions = collect(app(PositionResolver::class)->forShopify($p))->keyBy('name');

        // Garments also get sleeves and back neck (the data names front and back already).
        $this->assertSame(['Front', 'Back', 'Left sleeve', 'Right sleeve', 'Back neck'], $positions->keys()->all());
        $this->assertSame([100, 100], $positions['Left sleeve']['max_mm']);
        $this->assertSame('back', $positions['Back neck']['view']);
        $this->assertSame('garment', $positions['Back neck']['source']);
        $front = collect($positions['Front']['method_areas'])->keyBy('method');
        $this->assertSame([297, 420], $front['dtf']['max_mm']);          // "up to A3"
        $this->assertSame([380, 480], $front['dtf_oversize']['max_mm']); // DTF and oversize DTF have different areas
        $this->assertSame(['43446-oversizedtf'], $front['dtf_oversize']['decIDs']);
        $this->assertSame([380, 480], $positions['Front']['max_mm']);
        // The "Print Area" detail names a back of 280 × 350 without a method: it applies to the methods the product offers.
        $back = collect($positions['Back']['method_areas'])->keyBy('method');
        $this->assertSame([280, 350], $back['screen']['max_mm']);
        $this->assertSame('https://res.cloudinary.com/promodata/as-colour/5001_back.jpg', $positions['Back']['image']);
        // Methods with no size (screen print here has none of its own on the front) are still offered.
        $this->assertContains('Embroidery', $positions['Front']['methods']);
    }

    public function test_the_template_comes_before_the_decoration_data_and_staff_before_both(): void
    {
        $p = $this->stubby();
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode(['positions' => [
            ['name' => 'Front panel', 'view' => 'front', 'max_width_mm' => 90, 'max_height_mm' => 90, 'methods' => ['Screen print'], 'box' => ['left' => 30, 'top' => 30, 'width' => 40]],
        ]])]]])]);
        app(BrandingTemplates::class)->read(BrandingTemplate::query()->create(['product_id' => $p->id, 'url' => 'https://cdn.example/104743.pdf']));

        $positions = app(PositionResolver::class)->forShopify($p);
        $this->assertSame(['Front panel', 'template'], [$positions[0]['name'], $positions[0]['source']]);
        $this->assertSame(30.0, app(PrintAreas::class)->forShopify($p)['left']);

        $user = $this->staff('sales');
        app(PrintAreas::class)->approve($p, 20, 25, 30, $user->id);
        $this->assertSame(20.0, app(PrintAreas::class)->forShopify($p->fresh())['left']);
    }

    public function test_a_staff_approved_area_moves_the_first_front_decoration_position(): void
    {
        $p = $this->stubby();
        $user = $this->staff('sales');
        app(PrintAreas::class)->approve($p, 25, 40, 50, $user->id);

        $wrap = app(PositionResolver::class)->forShopify($p->fresh())[0];
        $this->assertSame([25.0, 40.0, 50.0], [$wrap['left'], $wrap['top'], $wrap['width']]);
        $this->assertSame([209, 108], $wrap['max_mm']);
    }

    public function test_without_a_stated_print_area_the_type_default_is_used_and_needs_no_vetting(): void
    {
        $hero = 'https://res.cloudinary.com/promodata/x/cap.jpg';
        $cap = Product::create(['id' => 300, 'supplier_id' => 88, 'name' => 'Trucker Cap', 'code' => 'C1', 'promodata_type_name' => 'Caps', 'tier' => 'live', 'hydrated_at' => now(), 'hero_image_url' => $hero]);
        $cap->images()->create(['url' => $hero, 'role' => 'hero_style', 'position' => 0]);
        PriceOption::create(['product_id' => 300, 'option_id' => '300-emb', 'code' => 'emb', 'kind' => 'PDADEC', 'type_code' => 10, 'description' => 'Embroidery up to 8000 stitches', 'price_string' => '1:5']);

        $positions = app(PositionResolver::class)->forShopify($cap);
        $this->assertSame(['Front', 'default', ['Embroidery'], 36.0], [$positions[0]['name'], $positions[0]['source'], $positions[0]['methods'], $positions[0]['left']]);
        $this->assertSame(['image' => $hero, 'left' => 36.0, 'top' => 34.0, 'width' => 28.0], app(PrintAreas::class)->forShopify($cap));
    }

    public function test_predictions_use_the_decoration_area_and_sample_learning_leaves_it_alone(): void
    {
        $p = $this->stubby();
        $this->artisan('catalogue:logo-areas --predict')->assertSuccessful();
        $this->assertSame('decoration', PrintArea::query()->find(sha1(self::STUBBY_HERO))->source);

        foreach ([801, 802] as $id) { // two vetted samples in the same supplier and type, moved a long way
            PrintArea::query()->create(['url_hash' => sha1("v{$id}"), 'url' => "v{$id}", 'product_id' => $id, 'group_key' => '3|stubby holders',
                'left' => 5, 'top' => 5, 'width' => 10, 'pred_left' => 32, 'pred_top' => 36, 'pred_width' => 36, 'source' => 'staff', 'approved_at' => now()]);
        }
        app(LogoAreaLearner::class)->learn();
        $this->assertSame([32.0, 'decoration'], [PrintArea::query()->find(sha1(self::STUBBY_HERO))->left, PrintArea::query()->find(sha1(self::STUBBY_HERO))->source]);
    }

    public function test_the_range_screen_shows_the_automatic_positions(): void
    {
        $p = $this->stubby();
        $this->staff('sales');
        $this->get(route('range.show', $p))->assertOk()->assertSee('Branding positions on the website')->assertSee('Wrap around')->assertSee('Sublimation 209 × 108 mm');
        $this->get(route('range.logo-areas'))->assertOk()->assertSee('Vetting here is optional', false);
    }

    public function test_a_tee_whose_data_names_only_the_front_still_gets_a_back_of_the_same_size(): void
    {
        $p = $this->tee();
        $p->detail->update(['details' => [['name' => 'Dimensions', 'detail' => 'S-5XL']]]);
        $positions = collect(app(PositionResolver::class)->forShopify($p->fresh()))->keyBy('name');

        $this->assertSame(['Front', 'Back', 'Left sleeve', 'Right sleeve', 'Back neck'], $positions->keys()->all());
        $this->assertSame($positions['Front']['max_mm'], $positions['Back']['max_mm']);
        $this->assertSame('back', $positions['Back']['view']);
        $this->assertSame($positions['Front']['methods'], $positions['Back']['methods']);
    }

    public function test_non_garments_get_no_extra_positions(): void
    {
        $this->assertCount(1, app(PositionResolver::class)->forShopify($this->stubby()));
    }
}
