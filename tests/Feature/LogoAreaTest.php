<?php

namespace Tests\Feature;

use App\Catalogue\LogoAreaLearner;
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
        app(LogoAreaLearner::class)->pickSample(10);
        $this->get(route('range.logo-areas'))->assertOk()->assertSee('Podium Polo')->assertSee('Predicted by Claude')->assertSee('left:55%', false);

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

    public function test_learns_from_the_vetted_sample_and_applies_to_the_range(): void
    {
        // 12 polos and 6 totes from one supplier; a cap from another.
        $make = function (int $id, string $type, int $supplier = 12) {
            $url = "https://res.cloudinary.com/promodata/x/{$id}.jpg";
            $p = Product::create(['id' => $id, 'supplier_id' => $supplier, 'name' => "{$type} {$id}", 'promodata_type_name' => $type, 'tier' => 'live', 'hydrated_at' => now(), 'hero_image_url' => $url]);
            $p->images()->create(['url' => $url, 'role' => 'hero_style', 'position' => 0]);

            return $p;
        };
        $this->supplier(88, 'AS Colour');
        $polos = collect(range(100, 111))->map(fn ($i) => $make($i, 'Polo Shirts'));
        $totes = collect(range(200, 205))->map(fn ($i) => $make($i, 'Tote Bags'));
        $cap = $make(300, 'Caps', 88);
        $this->artisan('catalogue:logo-areas --predict')->assertSuccessful();

        $areas = app(PrintAreas::class);
        $this->assertSame(19, PrintArea::query()->count());
        $this->assertSame(3, app(LogoAreaLearner::class)->pickSample(3)); // spread over the groups
        $this->assertSame(3, PrintArea::query()->where('sample', true)->distinct()->count('group_key'));

        // Staff move polos to the left chest (+18 across, same height, half the width); totes down 6.
        $user = $this->staff('sales');
        foreach ($polos->take(2) as $p) {
            $areas->approve($p, 37 + 18, 30, 13, $user->id);
        }
        foreach ($totes->take(2) as $p) {
            $areas->approve($p, 32, 44 + 6, 36, $user->id);
        }
        $this->artisan('catalogue:logo-areas')->expectsOutputToContain('Applied learned areas')->assertSuccessful();

        $polo = PrintArea::query()->find(sha1('https://res.cloudinary.com/promodata/x/111.jpg'));
        $this->assertSame(['learned', 'group', 55.0, 30.0, 13.0], [$polo->source, $polo->learned_from, $polo->left, $polo->top, $polo->width]);
        $tote = PrintArea::query()->find(sha1('https://res.cloudinary.com/promodata/x/205.jpg'));
        $this->assertSame([32.0, 50.0], [$tote->left, $tote->top]);
        // The cap's group and type have no vetted samples and there are fewer than 10 overall: left as predicted, not published.
        $this->assertSame('default', PrintArea::query()->find(sha1('https://res.cloudinary.com/promodata/x/300.jpg'))->source);
        $this->assertNull($areas->forShopify($cap));
        $this->assertSame(55.0, $areas->forShopify(Product::find(111))['left']);

        $this->get(route('range.logo-areas', ['view' => 'groups']))->assertOk()->assertSee('Polo Shirts')->assertSee('+18.0%')->assertSee('consistent');
        $this->get(route('range.logo-areas', ['view' => 'learned']))->assertOk()->assertSee('Learned from this supplier and type');
    }
}
