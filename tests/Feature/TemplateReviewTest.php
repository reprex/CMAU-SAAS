<?php

namespace Tests\Feature;

use App\Models\BrandingPosition;
use App\Models\BrandingTemplate;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class TemplateReviewTest extends TestCase
{
    use QuoteFixtures, RefreshDatabase;

    public function test_template_reads_show_print_areas_on_the_photo(): void
    {
        config(['app.admin_allowed_ips' => []]);
        $this->supplier(19, 'Dex');
        $p = Product::create(['id' => 8443, 'supplier_id' => 19, 'name' => 'Flurr Bamboo Pen', 'code' => 'BP002', 'tier' => 'live', 'hydrated_at' => now()]);
        $t = BrandingTemplate::query()->create(['product_id' => $p->id, 'url' => 'https://cdn.example/t.pdf', 'source' => 'promodata', 'status' => 'read', 'read_at' => now()]);
        BrandingPosition::query()->create(['product_id' => $p->id, 'branding_template_id' => $t->id, 'position' => 0, 'name' => 'Barrel', 'view' => 'front',
            'image_url' => 'https://cdn.example/black.jpg', 'left' => 30, 'top' => 40, 'width' => 40, 'quad' => [[30, 40], [70, 40], [70, 46], [30, 46]], 'surface' => 'cylinder_h',
            'max_width_mm' => 50, 'max_height_mm' => 8, 'methods' => ['Pad print'], 'source' => 'template']);

        $this->staff();
        $this->get(route('range.template-reads'))->assertOk()
            ->assertSee('Flurr Bamboo Pen')->assertSee('https://cdn.example/black.jpg', false)->assertSee('<polygon points="30,40 70,40 70,46 30,46"', false)
            ->assertSee('50 × 8 mm')->assertSee('cylinder h')->assertSee('Pad print')->assertSee('tread-logo', false)->assertSee('data-surface="cylinder_h"', false)
            ->assertSee(route('range.templates.download', [8443, $t->id]), false)->assertSee('Show the CrowdMerch logo');
    }
}
