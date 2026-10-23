<?php

namespace Tests\Feature;

use App\Catalogue\CatalogueWriter;
use App\Models\DecTypeCode;
use App\Models\PriceOptionSetting;
use App\Models\Product;
use App\Models\ProductOverride;
use App\Models\Supplier;
use App\Pricing\DecorationJson;
use App\Pricing\PriceOptionBuilder;
use App\Pricing\WebPriceCalculator;
use App\Sources\Promodata\PromodataNormaliser;
use App\Tod\TodPricingImporter;
use App\Tod\TsvReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PromodataFixtures;
use Tests\TestCase;

/**
 * Rules confirmed by comparing the engine with TOD's stored prices for the
 * live range (Oct 2026). Synthetic data only.
 */
class TodPricingRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([2 => 'Logo Line', 3 => 'Trends', 53 => 'Shiny', 68 => "JB's Wear", 999 => 'Melbourne Merch'] as $id => $name) {
            Supplier::create([
                'id' => $id, 'name' => $name,
                'markup_decoration' => 1.50, 'markup_undecorated' => 1.45,
                'markup_accessory' => 1.45, 'markup_setup' => 1.50,
            ]);
        }
        DecTypeCode::create(['code' => 'undecorated', 'type_code' => 12]);
        DecTypeCode::create(['code' => 'padprint', 'type_code' => 10]);
        DecTypeCode::create(['code' => 'laser', 'type_code' => 10]);
        DecTypeCode::create(['code' => 'allcolours', 'type_code' => 10]);
    }

    private function product(int $id, int $supplierId, array $base, array $additions = []): Product
    {
        $payload = PromodataFixtures::product($id);
        $payload['product']['prices']['price_groups'] = [['base_price' => $base, 'additions' => $additions]];

        Product::create(['id' => $id, 'supplier_id' => $supplierId, 'name' => 'x', 'tier' => Product::TIER_LIVE]);
        app(CatalogueWriter::class)->store((new PromodataNormaliser)->normalise($payload));
        Product::whereKey($id)->update(['supplier_id' => $supplierId]); // the fixture payload sets its own supplier

        return Product::findOrFail($id);
    }

    private function line(string $description, array $breaks, float $setup = 0, bool $undecorated = false): array
    {
        return [
            'key' => $description, 'type' => null, 'setup' => $setup, 'currency' => 'AUD',
            'lead_time' => null, 'description' => $description, 'undecorated' => $undecorated,
            'indent' => false, 'promodata_decoration' => null, 'tags' => [],
            'price_breaks' => array_map(fn ($q, $p) => ['qty' => $q, 'price' => $p], array_keys($breaks), $breaks),
        ];
    }

    private function price(Product $product): array
    {
        $options = app(PriceOptionBuilder::class)->build($product)->keyBy('option_id');
        $web = app(WebPriceCalculator::class)->calculate($product->refresh());

        return [$options, $web];
    }

    public function test_jbs_wear_adds_2_dollars_and_rounds_to_the_nearest_cent(): void
    {
        $product = $this->product(35682, 68, $this->line('Undecorated', [1 => 12.38], undecorated: true));
        [$options, $web] = $this->price($product);

        $undec = $options['35682-undecorated'];
        $this->assertSame('1:14.38', $undec->cost_string);
        $this->assertSame('1:20.85', $undec->price_string, '14.38 x 1.45 = 20.851, rounded to the nearest cent (not up)');
        $this->assertSame(50, $web->collection_moq);
    }

    public function test_shiny_options_are_all_undecorated_at_the_undecorated_markup(): void
    {
        $product = $this->product(25322, 53, $this->line('All Colours', [1 => 4.50]));
        [$options, $web] = $this->price($product);

        $option = $options['25322-allcolours'];
        $this->assertSame(12, $option->type_code, 'DecTypeCode says 10, Shiny forces 12');
        $this->assertSame('1:6.53', $option->price_string, '4.50 x 1.45 = 6.525, rounded up');
        $this->assertSame(20, $web->collection_moq, 'Shiny collection quantity is at least 20');
    }

    public function test_logo_line_types_come_from_descriptions(): void
    {
        $product = $this->product(106713, 2, $this->line('Undecorated', [50 => 7.00], undecorated: true), [
            $this->line('ADD 1 Pos Pad Print', [50 => 0.40], setup: 40),
            $this->line('ADD Variable Data Print', [50 => 1.50]),
            $this->line('1 Pos Colour Fusion', [50 => 7.15]),
        ]);
        [$options] = $this->price($product);

        $this->assertSame(12, $options['106713-undecorated']->type_code);
        $this->assertSame(10, $options['106713-add1pospadprint']->type_code);
        $this->assertSame(11, $options['106713-addvariabledataprint']->type_code, '"Variable" wins over "ADD"');
        $this->assertSame(23, $options['106713-1poscolourfusion']->type_code, 'anything else is hidden');
    }

    public function test_trends_moq_one_products_start_every_option_at_quantity_1(): void
    {
        $product = $this->product(1967, 3, $this->line('Undecorated', [250 => 0.21, 500 => 0.20], undecorated: true));
        ProductOverride::create(['product_id' => 1967, 'data' => ['moq_one' => true, 'collection_moq' => 250]]);
        [$options, $web] = $this->price($product);

        $this->assertSame('1:0.21,500:0.2', $options['1967-undecorated']->cost_string);
        $this->assertSame('1:0.31,500:0.29', $options['1967-undecorated']->price_string);
        $this->assertSame(1, $web->actual_moq);
        $this->assertSame(250, $web->collection_moq);
    }

    public function test_a_default_or_other_decoration_flag_makes_an_option_a_decoration(): void
    {
        DecTypeCode::create(['code' => 'giftbox', 'type_code' => 11]);
        $product = $this->product(2001, 3, $this->line('Undecorated', [100 => 1.00], undecorated: true), [
            $this->line('Gift Box', [100 => 0.80]),
        ]);
        PriceOptionSetting::create(['product_id' => 2001, 'option_id' => '2001-giftbox', 'type_code_override' => 11, 'is_other' => true]);
        [$options] = $this->price($product);

        $this->assertSame(10, $options['2001-giftbox']->type_code);
        $this->assertSame('100:1.16', $options['2001-giftbox']->price_string, 'markup still from DecTypeCode (accessory)');
    }

    public function test_order_values_include_decoration_setup_only_for_own_decorations(): void
    {
        $product = $this->product(2002, 3, $this->line('Undecorated', [100 => 1.00], undecorated: true), [
            $this->line('Pad Print', [100 => 0.30], setup: 40),
        ]);
        [, $web] = $this->price($product);
        // 100 x 0.45 + 60 setup (40 x 1.50) + 100 x 1.45
        $this->assertSame(250.0, $web->order_value_100);

        config(['pricing.order_value_decoration_setup' => 'never']);
        $this->assertSame(190.0, app(WebPriceCalculator::class)->calculate($product)->order_value_100);
    }

    public function test_decoration_json_order_follows_sort_groups_then_price_then_descending_id(): void
    {
        DecTypeCode::create(['code' => 'embroidery', 'type_code' => 10]);
        $product = $this->product(2003, 3, $this->line('Undecorated', [100 => 1.00], undecorated: true), [
            $this->line('Pad Print', [100 => 0.30], setup: 40),
            $this->line('Laser', [100 => 0.30], setup: 40),
            $this->line('Embroidery', [100 => 2.00], setup: 80),
        ]);
        PriceOptionSetting::create(['product_id' => 2003, 'option_id' => '2003-embroidery', 'json_sort' => 1]);
        [, $web] = $this->price($product);

        $ids = array_column(json_decode($web->decoration_json, true)['Decorations'], 'DecID');
        // Embroidery is pinned; pad print and laser tie on price, so descending id.
        $this->assertSame(['2003-embroidery', '2003-padprint', '2003-laser'], $ids);
        $this->assertSame('2003-padprint', $web->default_decoration_option_id, 'default is the cheapest at 100; ties keep the first listed by the supplier');
    }

    public function test_borrowed_price_file_decorations_default_to_their_first_listed_option(): void
    {
        DecTypeCode::create(['code' => 'embroiderytopocketsize', 'type_code' => 10]);
        DecTypeCode::create(['code' => 'dtftoa3', 'type_code' => 10]);
        $mm = $this->product(10002000, 999, $this->line('DTF to A3', [1 => 7.75]), [
            $this->line('Embroidery to pocket size', [1 => 8.50], setup: 35),
        ]);
        Product::whereKey(10002000)->update(['supplier_id' => 999]);
        app(PriceOptionBuilder::class)->build($mm->refresh());
        PriceOptionSetting::create(['product_id' => 10002000, 'option_id' => '10002000-embroiderytopocketsize', 'json_sort' => 1]);

        $tee = $this->product(1006, 3, $this->line('Undecorated', [1 => 5.00], undecorated: true));
        ProductOverride::create(['product_id' => 1006, 'data' => ['decoration_product_id' => 10002000]]);
        [$options, $web] = $this->price($tee);

        $this->assertSame('10002000-embroiderytopocketsize', $web->default_decoration_option_id, 'first listed, not cheapest');
        // 100 x 12.75 embroidery + 100 x 7.25 undecorated; no decoration setup for borrowed decorations
        $this->assertSame(2000.0, $web->order_value_100);
        $this->assertSame(20.0, $web->collection_variant_price);
        $this->assertArrayNotHasKey('1006-unbrandeddec', $options->all());
        $this->assertFalse($mm->priceOptions()->where('code', 'unbrandeddec')->exists(), 'no placeholder on price-file products');
    }

    public function test_unbranded_only_products_have_no_default_decoration(): void
    {
        $product = $this->product(1991, 3, $this->line('Undecorated', [1 => 1.00], undecorated: true));
        [, $web] = $this->price($product);

        $this->assertNull($web->default_decoration_option_id);
        $this->assertTrue($web->decoration_available, 'TOD counts the "only available unbranded" placeholder');
        $this->assertStringStartsWith('{"BrandFlag":"Price includes branding"', $web->decoration_json);
    }

    public function test_decoration_names_keep_tod_spacing_but_lose_commas_and_line_breaks(): void
    {
        $this->assertSame(' Including full colour printing', DecorationJson::cleanName(' Including full colour printing'));
        $this->assertSame('Foil (Notebook - 70mm x  50mm)', DecorationJson::cleanName('Foil, (Notebook - 70mm x  50mm)'));
        $this->assertSame('Embroidery (Up to 10000 stitches) (80mm x 35mm)', DecorationJson::cleanName("Embroidery (Up to 10,000 stitches)\r\r\n (80mm x 35mm)"));
        $this->assertSame('Resin Coated Finish', DecorationJson::cleanName("Resin Coated Finish\r\r\n"));
    }

    public function test_tsv_reader_unescapes_line_breaks(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tsv');
        file_put_contents($path, "\xEF\xBB\xBFA\tB\r\nx\\r\\ny\t1\r\n");
        $rows = iterator_to_array(TsvReader::rows($path), false);
        unlink($path);

        $this->assertSame([['A' => "x\r\ny", 'B' => '1']], $rows);
    }

    public function test_importer_loads_tod_exports_and_infers_overrides(): void
    {
        $dir = sys_get_temp_dir().'/tod-'.uniqid();
        mkdir($dir);
        $write = function (string $file, array $header, array $rows) use ($dir) {
            $lines = [implode("\t", $header)];
            foreach ($rows as $row) {
                $lines[] = implode("\t", array_map(fn ($h) => $row[$h] ?? '', $header));
            }
            file_put_contents("{$dir}/{$file}", implode("\r\n", $lines)."\r\n");
        };
        $write('dbo_PDS_Supplier.tsv', ['APPASupplierID', 'APPASupplierCode', 'SupplierName', 'SupplierWebFlag', 'SupplierRank', 'SupplierCountry',
            'SupplierMarkupUnDec', 'SupplierMarkupDec', 'SupplierMarkupAcc', 'SupplierMarkupSetup', 'SupplierMarkupTo300', 'SupplierMarkTo1000',
            'SupplierMarkOver1001', 'SupplierMarkupSample', 'SupplierSampleFreight', 'SupplierSampleAvailFlag', 'SupplierStockColumnCSV'], [
                ['APPASupplierID' => '3', 'SupplierName' => 'Trends', 'SupplierMarkupUnDec' => '1.45', 'SupplierMarkupDec' => '1.5', 'SupplierMarkupAcc' => '1.45', 'SupplierMarkupSetup' => '1.5', 'SupplierMarkupTo300' => '1.65'],
            ]);
        $write('DecTypeCode.tsv', ['DecCodeID', 'DecTypeCode', 'OverwriteDesc', 'FirstSupplierID', 'FirstSupplierName', 'FullDesc', 'PromoDecoration',
            'SpotColourPrintFlag', 'FullColourPrintFlag', 'EmbroiderFlag', 'EngraveFlag', 'EmbossDebossFlag', 'FoilStampFlag', 'SublimationFlag'], [
                ['DecCodeID' => 'includingfullcolourprint', 'DecTypeCode' => '10'],
            ]);
        $write('dbo_PDS_Products.tsv', ['APPAProductID', 'SupplierID', 'SupplierAPPAName', 'SupplierProductID', 'WebShortDesc', 'ShortDescription',
            'WebProdAddFlag', 'WebProdRemoveFlag', 'SuppplierDiscontinuedFlag', 'WebDecLiveAPPAID', 'WebOverrideDecAPPAID', 'WebDecPDIDManual',
            'OverrideActualMOQ', 'ActualMOQ', 'CollectionMOQ', 'DefaultDecCPSID'], [
                ['APPAProductID' => '10092', 'SupplierID' => '3', 'WebShortDesc' => 'Pen', 'WebProdAddFlag' => '1', 'WebProdRemoveFlag' => '0',
                    'WebDecLiveAPPAID' => '10092', 'ActualMOQ' => '1', 'CollectionMOQ' => '100'],
                ['APPAProductID' => '10093', 'SupplierID' => '3', 'WebProdAddFlag' => '0', 'WebProdRemoveFlag' => '0'],
            ]);
        $write('dbo_PDS_CostPriceBreak.tsv', ['PDSCPB_APPAProdID', 'PDSCPB_TextPDID', 'PDSCPB_DecString', 'PDSCPB_SetupCost', 'PDSCPB_UnitCost',
            'PDSCPB_Type', 'PDSCPB_Qty', 'PDSCPB_DeleteFlag', 'PDSCPB_PromodataDecoration'], [
                ['PDSCPB_APPAProdID' => '10092', 'PDSCPB_TextPDID' => '10092-includingfullcolourprint', 'PDSCPB_DecString' => 'Including full colour print',
                    'PDSCPB_SetupCost' => '0', 'PDSCPB_UnitCost' => '4.07', 'PDSCPB_Type' => 'PDADEC', 'PDSCPB_Qty' => '100', 'PDSCPB_DeleteFlag' => '0'],
                ['PDSCPB_APPAProdID' => '10092', 'PDSCPB_TextPDID' => '10092-includingfullcolourprint', 'PDSCPB_DecString' => 'Including full colour print',
                    'PDSCPB_SetupCost' => '0', 'PDSCPB_UnitCost' => '2.45', 'PDSCPB_Type' => 'PDADEC', 'PDSCPB_Qty' => '250', 'PDSCPB_DeleteFlag' => '0'],
            ]);
        $write('dbo_PDS_CostPriceSummary.tsv', ['PDSCP_APPAID', 'PDSCP_TextPDID', 'PDSCP_Type', 'PDSCP_QtyCostString', 'PDSCP_DecJSONSort'], [
            ['PDSCP_APPAID' => '10092', 'PDSCP_TextPDID' => '10092-includingfullcolourprint', 'PDSCP_Type' => 'PDADEC', 'PDSCP_QtyCostString' => '1:4.07,250:2.45', 'PDSCP_DecJSONSort' => '1'],
            ['PDSCP_APPAID' => '10092', 'PDSCP_TextPDID' => '10092-unbranded', 'PDSCP_Type' => 'UNDEC', 'PDSCP_QtyCostString' => '1:0', 'PDSCP_DecJSONSort' => ''],
        ]);
        $write('dbo_PDS_LocalPrice.tsv', ['PDSLP_ID', 'PDSLP_CPSID', 'PDSLP_APPAID', 'PDSLP_DecType', 'PDSLP_DefaultDec', 'PDSLP_OtherDec'], [
            ['PDSLP_ID' => '1', 'PDSLP_CPSID' => '10092-unbranded', 'PDSLP_APPAID' => '10092', 'PDSLP_DecType' => '', 'PDSLP_DefaultDec' => '0', 'PDSLP_OtherDec' => '0'],
        ]);

        $counts = (new TodPricingImporter($dir))->import();
        array_map('unlink', glob("{$dir}/*"));
        rmdir($dir);

        $this->assertSame(1, $counts['products'], 'only the live product');
        $this->assertSame(1.65, (float) Supplier::find(3)->quote_markup_to_300);
        $this->assertSame(['moq_one' => true, 'collection_moq' => 100], Product::find(10092)->override->data);
        $this->assertSame(1, PriceOptionSetting::where('option_id', '10092-includingfullcolourprint')->value('json_sort'));

        [$options, $web] = $this->price(Product::find(10092));
        $this->assertSame('1:4.07,250:2.45', $options['10092-includingfullcolourprint']->cost_string);
        $this->assertSame(100, $web->collection_moq);
    }
}
