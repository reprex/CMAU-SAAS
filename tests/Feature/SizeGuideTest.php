<?php

namespace Tests\Feature;

use App\Catalogue\GarmentSizes;
use App\Catalogue\SizeGuide;
use App\Shopify\ProductPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class SizeGuideTest extends TestCase
{
    use QuoteFixtures, RefreshDatabase;

    private function tee(array $details, array $attrs = [], string $desc = 'A tee.')
    {
        $this->supplier(7, 'AS Colour');
        $p = $this->catalogueProduct(43446, 7, 'AS Colour Mens Staple Tee', '5001');
        $p->forceFill(['promodata_type_name' => 'Shirts > T-Shirts'])->save();
        $p->detail()->create(['description' => $desc, 'details' => $details, 'promodata_attributes' => $attrs]);

        return $p->fresh();
    }

    public function test_fit_and_measurements_become_the_size_guide(): void
    {
        $p = $this->tee([
            ['name' => 'Fit', 'detail' => 'Regular fit'],
            ['name' => 'Chest (1/2)', 'detail' => 'S 52, M 55, L 58, XL 61'],
            ['name' => 'Body Length', 'detail' => 'S 71, M 74, L 76, XL 78'],
            ['name' => 'Sleeve Length', 'detail' => 'S 20, M 21, L 22, XL 23'],
            ['name' => 'Material', 'detail' => '100% cotton'],
        ]);
        $g = SizeGuide::forProduct($p);
        $this->assertSame('Regular fit', $g['fit']);
        $this->assertSame(['S', 'M', 'L', 'XL'], $g['sizes']);
        $this->assertSame(['Chest (½)', 'Length', 'Sleeve'], array_column($g['rows'], 'label'));
        $this->assertSame([52, 55, 58, 61], $g['rows'][0]['values']);
        $this->assertSame('L', $g['highlight']);

        $input = app(ProductPayload::class)->build($p)['input'];
        $mf = collect($input['metafields'])->keyBy('key');
        $this->assertSame('Regular fit', json_decode($mf['sizeguide']['value'], true)['fit']);
        $this->assertSame('L:58', $mf['mockupgarment']['value']);
        // The specifications keep the material but not the lines the guide shows.
        $this->assertStringContainsString('100% cotton', $input['descriptionHtml']);
        $this->assertStringNotContainsString('Chest (1/2)', $input['descriptionHtml']);
        $this->assertStringNotContainsString('Regular fit', $input['descriptionHtml']);
    }

    public function test_a_table_in_the_description_and_a_fit_attribute(): void
    {
        $p = $this->tee([], ['Fit: Slim'], 'Soft tee.<br>Size | S | M | L | XL<br>Half chest | 48 | 51 | 54 | 57<br>Length | 69 | 71 | 73 | 75');
        $g = SizeGuide::forProduct($p);
        $this->assertSame('Slim fit', $g['fit']);
        $this->assertSame(['S', 'M', 'L', 'XL'], $g['sizes']);
        $this->assertSame([48, 51, 54, 57], $g['rows'][0]['values']);
        $this->assertSame('Length', $g['rows'][1]['label']);
        $html = app(ProductPayload::class)->build($p)['input']['descriptionHtml'];
        $this->assertStringContainsString('Soft tee.', $html);
        $this->assertStringNotContainsString('Half chest', $html);
        $this->assertStringNotContainsString('Size | S', $html);
    }

    public function test_size_chart_picture_and_link(): void
    {
        $p = $this->tee([['name' => 'Size Guide', 'detail' => 'https://supplier.example/5001-size-guide.pdf']]);
        $p->images()->create(['url' => 'https://cdn.example/5001_size_chart.jpg', 'role' => 'other_style', 'position' => 9]);
        $g = SizeGuide::forProduct($p->fresh());
        $this->assertSame('https://cdn.example/5001_size_chart.jpg', $g['image']);
        $this->assertSame('https://supplier.example/5001-size-guide.pdf', $g['link']);
    }

    public function test_a_fit_without_measurements_is_not_a_guide(): void
    {
        $p = $this->tee([['name' => 'Fit', 'detail' => 'Regular fit'], ['name' => 'Size Guide', 'detail' => 'https://supplier.example/guide.pdf']]);
        $this->assertNull(SizeGuide::forProduct($p));
        $input = app(ProductPayload::class)->build($p)['input'];
        $this->assertNull(collect($input['metafields'])->firstWhere('key', 'sizeguide'));
        $this->assertStringContainsString('Regular fit', $input['descriptionHtml'], 'the fit stays in Product specifications');
    }

    public function test_grouped_attributes_and_odd_details_do_not_stop_publishing(): void
    {
        $p = $this->tee([['name' => 'Chest (1/2)', 'detail' => 'S 52, M 55, L 58']],
            ['Fit' => ['Slim'], 'Material' => ['Cotton', 'Elastane'], 'Gender: Men']);
        $g = SizeGuide::forProduct($p);
        $this->assertSame('Slim fit', $g['fit']);
        $this->assertSame('L:58', GarmentSizes::metafield($p));
        $this->assertNotEmpty(app(ProductPayload::class)->build($p)['input']['title']);
    }

    public function test_no_sizing_no_guide(): void
    {
        $p = $this->tee([['name' => 'Material', 'detail' => 'Cotton'], ['name' => 'Print Area', 'detail' => 'Left chest 90 x 90mm']]);
        $this->assertNull(SizeGuide::forProduct($p));
        $input = app(ProductPayload::class)->build($p)['input'];
        $this->assertNull(collect($input['metafields'])->firstWhere('key', 'sizeguide'));
        $this->assertStringContainsString('Left chest 90 x 90mm', $input['descriptionHtml']);
    }
}
