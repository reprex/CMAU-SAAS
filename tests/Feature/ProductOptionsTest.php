<?php

namespace Tests\Feature;

use App\Catalogue\ProductOptions;
use App\Models\PriceOption;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class ProductOptionsTest extends TestCase
{
    use QuoteFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supplier(3, 'Trends');
    }

    /** A product with Promodata lines: [description, type code, sell breaks, sell setup]. */
    private function product(int $id, array $lines): Product
    {
        $p = Product::create(['id' => $id, 'supplier_id' => 3, 'name' => 'Product '.$id, 'code' => (string) $id, 'tier' => 'live']);
        foreach ($lines as $i => [$desc, $type, $breaks, $setup]) {
            PriceOption::create(['product_id' => $id, 'option_id' => $id.'-'.strtolower(preg_replace('/[^a-z0-9]/i', '', $desc)), 'code' => strtolower(preg_replace('/[^a-z0-9]/i', '', $desc)),
                'kind' => 'PDADEC', 'type_code' => $type, 'description' => $desc, 'price_string' => $breaks, 'setup_price' => $setup, 'position' => $i]);
        }

        return $p->load('priceOptions');
    }

    public function test_lanyard_gets_clip_choice_attachments_and_sample_but_not_the_print(): void
    {
        $p = $this->product(2084, [
            ['Unbranded', 12, '100:2.44,250:1.8', 0],
            ['Screen Print Per Colour/Position (Logo repeats along the full length of the lanyard material)', 10, '100:0.05', 60],
            ['Pre-Production Sample Per Artwork', 11, null, 60],
            ['Double Clip', 11, null, 0],
            ['Metal Clip', null, null, 0],
            ['Safety Clip', null, null, 0],
            ['Loop Attachment', 11, '100:0.15', 0],
            ['J Hook Clip', 11, '100:0.22', 0],
            ['ID Holder', 11, '100:0.37', 0],
            ['Duet ID Holder', 11, '100:0.37', 0],
        ]);
        $o = app(ProductOptions::class)->forProduct($p);
        $g = collect($o['groups'])->keyBy('key');

        $this->assertSame(['clip', 'attach', 'service'], $g->keys()->all()); // the print stays in DecorationJSON
        $this->assertSame('one', $g['clip']['pick']);
        $clip = collect($g['clip']['options'])->keyBy('name');
        $this->assertTrue($clip['Single metal clip']['included']);
        $this->assertSame('100:0.22', $clip['J hook clip']['breaks']);
        $this->assertArrayNotHasKey('breaks', $clip['Double clip']); // no charge
        $attach = collect($g['attach']['options'])->keyBy('name');
        $this->assertTrue($attach['Safety clip']['fixed']);
        $this->assertSame(['2084-duetidholder'], $attach['ID holder']['excludes']);
        $sample = $g['service']['options'][0];
        $this->assertSame(['Pre-production sample', 60.0, 'artwork'], [$sample['name'], $sample['setup'], $sample['per']]);
        $this->assertSame(['Special PMS lanyard colours from 500.'], $o['notes']);
    }

    public function test_embroidery_extras_and_packaging_go_with_their_rules(): void
    {
        $p = $this->product(3001, [
            ['Embroidery Per Position (up to 10,000 stitches)', 10, '25:4.50', 60],
            ['Embroidery Per Additional 5,000 Stitches', 11, '25:1.50', 0],
            ['Metallic Thread Additional Charge Per 1,000 Stitches', 11, '25:0.30', 0],
            ['Gift Box', 11, '25:1.50', 0],
            ['Kraft Paper Pen Sleeve', 11, '25:0.17', 0],
            ['Personalisation Surcharge (per line)', 11, '25:2.25', 0],
            ['Mystery Widget', 11, '25:9.00', 0],
        ]);
        $opts = app(ProductOptions::class);
        $g = collect($opts->forProduct($p)['groups'])->keyBy('key');

        $this->assertSame(['embroidery'], $g['stitches']['with_method']);
        $this->assertSame(10000, $g['stitches']['base_stitches']);
        $this->assertSame(1000, collect($g['stitches']['options'])->firstWhere('name', 'Metallic thread')['per_stitches']);
        $this->assertSame('one', $g['packaging']['pick']);
        $this->assertTrue($g['packaging']['optional']);
        $this->assertArrayNotHasKey('included', $g['packaging']['options'][0]); // optional: nothing chosen by default
        $this->assertSame('count', $g['personalise']['pick']);
        $this->assertSame('per line', $g['personalise']['options'][0]['note']);
        $this->assertSame(['Mystery Widget'], $opts->unmatched($p));
    }

    public function test_other_suppliers_get_nothing(): void
    {
        $this->supplier(88, 'AS Colour');
        $p = Product::create(['id' => 9, 'supplier_id' => 88, 'name' => 'Tee', 'code' => 'T', 'tier' => 'live']);
        $this->assertNull(app(ProductOptions::class)->forProduct($p->load('priceOptions')));
    }
}
