<?php

namespace Tests\Feature;

use App\Catalogue\PositionResolver;
use App\Models\PositionRule;
use App\Models\PriceOption;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class PositionRulesTest extends TestCase
{
    use QuoteFixtures;
    use RefreshDatabase;

    private function mug(int $id = 5001, string $type = 'Mugs'): Product
    {
        Supplier::query()->firstOrCreate(['id' => 3], ['name' => 'Test Supplier']);
        $p = Product::create(['id' => $id, 'supplier_id' => 3, 'name' => 'Mug', 'promodata_type_name' => $type, 'tier' => 'live', 'hydrated_at' => now(), 'hero_image_url' => 'https://cdn.example/mug.jpg']);
        $p->images()->create(['url' => 'https://cdn.example/mug.jpg', 'role' => 'hero_style', 'position' => 0]);
        PriceOption::create(['product_id' => $id, 'option_id' => "{$id}-pad1", 'code' => 'pad1', 'kind' => 'PDADEC', 'type_code' => 10, 'description' => 'Pad print 1 colour', 'price_string' => '1:1']);

        return $p;
    }

    public function test_a_type_rule_replaces_the_automatic_positions_and_a_product_rule_beats_it(): void
    {
        $mug = $this->mug();
        $other = $this->mug(5002);
        PositionRule::create(['product_type' => 'Mugs', 'positions' => [['name' => 'Side 1', 'view' => 'front', 'width_mm' => 70, 'height_mm' => 50], ['name' => 'Side 2', 'view' => 'back', 'width_mm' => 70, 'height_mm' => 50]]]);
        PositionRule::create(['product_id' => 5002, 'positions' => [['name' => 'Wrap around', 'view' => 'front', 'width_mm' => 200, 'height_mm' => 80]]]);

        $a = app(PositionResolver::class)->forShopify($mug);
        $this->assertSame(['Side 1', 'Side 2'], array_column($a, 'name'));
        $this->assertSame('staff', $a[0]['source']);
        $this->assertSame([70, 50], $a[0]['max_mm']);
        $this->assertSame([70, 50], $a[0]['method_areas'][0]['max_mm']); // the product's methods, capped
        $this->assertSame('back', $a[1]['view']);

        $this->assertSame(['Wrap around'], array_column(app(PositionResolver::class)->forShopify($other), 'name'));
    }

    public function test_saving_positions_marks_the_types_live_products_and_clearing_removes_the_rule(): void
    {
        $this->mug();
        $this->staff('admin');

        $this->post(route('range.positions.save'), ['product_type' => 'Mugs', 'positions' => [['name' => 'Side 1', 'view' => 'front', 'width_mm' => 70]]])->assertRedirect();
        $this->assertNotNull(Product::find(5001)->range_changed_at);
        $this->assertSame('Side 1', PositionRule::first()->positions[0]['name']);
        $this->get(route('range.positions', ['type' => 'Mugs']))->assertOk()->assertSee('Save positions');

        $this->post(route('range.positions.save'), ['product_type' => 'Mugs', 'positions' => [['name' => '']]])->assertRedirect();
        $this->assertSame(0, PositionRule::count());
    }
}
