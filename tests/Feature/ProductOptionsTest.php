<?php

namespace Tests\Feature;

use App\Catalogue\ProductOptions;
use App\Checkout\BrandingPricer;
use App\Models\PriceOption;
use App\Models\Product;
use App\Pricing\WebPriceCalculator;
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

    public function test_logo_line_packaging_leaves_branding_and_becomes_options(): void
    {
        $this->supplier(2, 'Logo Line');
        $p = Product::create(['id' => 7566, 'supplier_id' => 2, 'name' => 'Pen', 'code' => 'LL0326', 'tier' => 'live']);
        foreach ([
            ['Undecorated', 12, '250:0.53', 0], ['ADD 1 Pos Pad Print Per Colour', 10, '250:0.18', 60], ['ADD 1 Pos Digital Print', 10, '250:0.38', 60],
            ['ADD Variable Data - Digital Print', 11, '250:2.18', 0], ['ADD LL0512 Cardboard Sleeve with Pen Inserted', 10, '250:0.29', 0],
            ['ADD 1 Col Print on Pen Sleeve', 10, '250:0.18', 60], ['ADD LL0501 Black Cardboard Pen Box with Pen Inserted', 10, '250:0.92', 0],
            ['1 Pos Pad Print Per Colour', 23, '250:0.72', 60],
        ] as $i => [$desc, $type, $breaks, $setup]) {
            PriceOption::create(['product_id' => 7566, 'option_id' => '7566-'.strtolower(preg_replace('/[^a-z0-9]/i', '', $desc)), 'code' => strtolower(preg_replace('/[^a-z0-9]/i', '', $desc)),
                'kind' => 'PDADEC', 'type_code' => $type, 'description' => $desc, 'price_string' => $breaks, 'cost_string' => $breaks, 'setup_price' => $setup, 'position' => $i]);
        }
        $opts = app(ProductOptions::class);
        $g = collect($opts->forProduct($p->load('priceOptions'))['groups'])->keyBy('key');

        $this->assertSame(['personalise', 'packaging', 'packprint'], $g->keys()->all());
        $this->assertSame(['Cardboard sleeve, pen inserted', 'Black pen box, pen inserted'], array_column($g['packaging']['options'], 'name'));
        $this->assertSame(['7566-addll0512cardboardsleevewithpeninserted'], $g['packprint']['options'][0]['needs']); // print the sleeve needs the sleeve
        $this->assertSame(['7566-addll0512cardboardsleevewithpeninserted', '7566-add1colprintonpensleeve', '7566-addll0501blackcardboardpenboxwithpeninserted'], $opts->claimed(2, $p->priceOptions));

        // The packaging is no longer offered as a print method.
        $wp = app(WebPriceCalculator::class)->calculate($p->fresh());
        $decs = array_column(json_decode($wp->decoration_json, true)['Decorations'], 'DecName');
        $this->assertSame(['ADD 1 Pos Pad Print Per Colour', 'ADD 1 Pos Digital Print'], $decs);
        $this->assertSame(['7566-add1pospadprintpercolour', '7566-add1posdigitalprint'], app(BrandingPricer::class)->options(7566)->keys()->all());
    }

    public function test_dex_branding_lines_become_a_production_time_choice(): void
    {
        $this->supplier(19, 'Dex');
        $p = Product::create(['id' => 8385, 'supplier_id' => 19, 'name' => 'Apron', 'code' => 'AP001', 'tier' => 'live']);
        foreach ([
            ['Local Blank Stock', 12, '50:7.54', 0], ['Same Day Print Per Col/Pos', 10, '50:0.98', 60], ['24 Hrs. Print Per Col/Pos', 10, '50:0.98', 60],
            ['3 Days Print Per Col/Pos', 10, '50:0.83', 60], ['7 Days Print Per Col/Pos', 10, '50:0.68', 60], ['12 Days Print Per Col/Pos', 10, '50:0.6', 60],
            ['28 Days Print Per Col/Pos', 23, '50:0.37', 60], ['7 Days Data Upload', 11, null, 60],
        ] as $i => [$desc, $type, $breaks, $setup]) {
            PriceOption::create(['product_id' => 8385, 'option_id' => '8385-'.strtolower(preg_replace('/[^a-z0-9]/i', '', $desc)), 'code' => strtolower(preg_replace('/[^a-z0-9]/i', '', $desc)),
                'kind' => 'PDADEC', 'type_code' => $type, 'description' => $desc, 'price_string' => $breaks, 'setup_price' => $setup, 'position' => $i]);
        }
        $o = app(ProductOptions::class)->forProduct($p->load('priceOptions'));

        $speed = collect($o['speed']['options'])->keyBy('key');
        $this->assertSame(['sameday', '24hrs', '3days', '7days', '12days'], $speed->keys()->all()); // the hidden 28-day line isn't offered
        $this->assertSame('12days', $o['speed']['default']);
        $this->assertSame(['8385-samedayprintpercolpos'], $speed['sameday']['ids']);
        $this->assertSame([50, 100], $speed['sameday']['ranges']['8385-samedayprintpercolpos']); // Dex's page: from 50 to 100
        $this->assertSame([50, 2500], $speed['12days']['ranges']['8385-12daysprintpercolpos']);
        $this->assertSame('7days', $o['groups'][0]['options'][0]['speed']); // data upload priced for 7 days shows with 7 days only
    }

    public function test_legend_life_methods_it_does_not_offer_leave_branding(): void
    {
        $this->supplier(57, 'Legend Life');
        $p = Product::create(['id' => 59500, 'supplier_id' => 57, 'name' => 'Polo', 'code' => 'ST9010', 'tier' => 'live']); // Legend Life: SupaDTF only
        foreach ([['Undecorated', 12, '1:20'], ['Supacolour 60mm x 60mm', 10, '1:0,20:4.5,250:2.5'], ['SupaDTF 100mm x 100mm', 10, '1:0,20:3'], ['SupaDTF 210mm x 300mm', 10, '1:0,20:4']] as $i => [$desc, $type, $breaks]) {
            PriceOption::create(['product_id' => 59500, 'option_id' => '59500-'.strtolower(preg_replace('/[^a-z0-9]/i', '', $desc)), 'code' => strtolower(preg_replace('/[^a-z0-9]/i', '', $desc)),
                'kind' => 'PDADEC', 'type_code' => $type, 'description' => $desc, 'price_string' => $breaks, 'cost_string' => $breaks, 'setup_price' => $type === 10 ? 60 : 0, 'position' => $i]);
        }
        $this->assertSame(['59500-supacolour60mmx60mm'], app(ProductOptions::class)->claimed(57, $p->priceOptions));
        $wp = app(WebPriceCalculator::class)->calculate($p->fresh());
        $this->assertSame(['SupaDTF 100mm x 100mm', 'SupaDTF 210mm x 300mm'], array_column(json_decode($wp->decoration_json, true)['Decorations'], 'DecName'));
        $this->assertSame(20, $wp->actual_moq); // decoration from 20 (the leading zero break)
    }

    public function test_other_suppliers_get_nothing(): void
    {
        $this->supplier(88, 'AS Colour');
        $p = Product::create(['id' => 9, 'supplier_id' => 88, 'name' => 'Tee', 'code' => 'T', 'tier' => 'live']);
        $this->assertNull(app(ProductOptions::class)->forProduct($p->load('priceOptions')));
    }
}
