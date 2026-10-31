<?php

namespace Tests\Feature;

use App\Catalogue\PrintAreas;
use App\Models\PrintArea;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class LogoAreaTest extends TestCase
{
    use QuoteFixtures, RefreshDatabase;

    private const HERO = 'https://res.cloudinary.com/promodata/ll/polo-hero.jpg';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.admin_allowed_ips' => [], 'search.anthropic.key' => 'test-key']);
        $this->supplier(12, "JB's Wear");
    }

    private function polo(): Product
    {
        $p = Product::create(['id' => 35681, 'supplier_id' => 12, 'name' => 'Podium Polo', 'code' => '7PIP', 'promodata_type_name' => 'Polo Shirts',
            'tier' => 'live', 'hydrated_at' => now(), 'hero_image_url' => self::HERO]);
        $p->images()->create(['url' => self::HERO, 'role' => 'hero_style', 'position' => 0]);

        return $p;
    }

    public function test_defaults_by_product_type_and_nothing_sent_until_approved(): void
    {
        $p = $this->polo();
        $areas = app(PrintAreas::class);
        $this->assertSame([37, 30, 26], $areas->areaFor($p));
        $cap = Product::create(['id' => 2, 'supplier_id' => 12, 'name' => 'Trucker', 'promodata_type_name' => 'Caps', 'tier' => 'live']);
        $this->assertSame([36, 34, 28], $areas->default($cap));
        $this->assertNull($areas->forShopify($p));
    }

    public function test_claude_suggests_once_and_staff_approve_from_the_screen(): void
    {
        $p = $this->polo();
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => '{"left":55,"top":28,"width":14}']]])]);
        app(PrintAreas::class)->suggest($p);
        app(PrintAreas::class)->suggest($p->fresh());
        Http::assertSentCount(1);
        $this->assertSame('ai', PrintArea::query()->value('source'));

        $this->staff('sales');
        $this->get(route('range.logo-areas'))->assertOk()->assertSee('Podium Polo')->assertSee('Suggested by Claude')->assertSee('left:55%', false);

        $this->postJson(route('range.logo-areas.store', $p), ['left' => 56.5, 'top' => 29, 'width' => 13])->assertOk();
        $this->assertSame(['image' => self::HERO, 'left' => 56.5, 'top' => 29.0, 'width' => 13.0], app(PrintAreas::class)->forShopify($p->fresh()));
        $this->assertNotNull($p->fresh()->range_changed_at);
        $this->get(route('range.logo-areas', ['view' => 'done']))->assertSee('Approved');
        $this->get(route('range.logo-areas'))->assertDontSee('Podium Polo');
    }

    public function test_read_only_staff_cannot_approve(): void
    {
        $p = $this->polo();
        $this->staff('readonly');
        $this->postJson(route('range.logo-areas.store', $p), ['left' => 1, 'top' => 1, 'width' => 10])->assertForbidden();
    }
}
