<?php

namespace Tests\Feature;

use App\Catalogue\CatalogueWriter;
use App\Models\DecTypeCode;
use App\Models\PriceOption;
use App\Models\PriceOptionSetting;
use App\Models\Product;
use App\Models\ProductOverride;
use App\Models\Supplier;
use App\Pricing\DecorationJson;
use App\Pricing\PriceOptionBuilder;
use App\Pricing\WebPriceCalculator;
use App\Sources\Promodata\PromodataNormaliser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\PromodataFixtures;
use Tests\TestCase;

class PricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Supplier::create([
            'id' => 3, 'name' => 'Test Supplier Pty Ltd',
            'markup_decoration' => 1.50, 'markup_undecorated' => 1.45,
            'markup_accessory' => 1.45, 'markup_setup' => 1.00,
        ]);
        DecTypeCode::create(['code' => 'undecorated', 'type_code' => 12]);
        DecTypeCode::create(['code' => 'padprint1colour1position', 'type_code' => 10, 'spot_colour' => true]);
        DecTypeCode::create(['code' => 'extracolourposition', 'type_code' => 11]);
    }

    /** Stores a live product whose price groups are given in Promodata form. */
    private function liveProduct(int $id, array $priceGroups): Product
    {
        $payload = PromodataFixtures::product($id);
        $payload['product']['prices']['price_groups'] = $priceGroups;

        Product::create(['id' => $id, 'supplier_id' => 3, 'name' => 'x', 'tier' => Product::TIER_LIVE]);
        app(CatalogueWriter::class)->store((new PromodataNormaliser)->normalise($payload));

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

    private function standardProduct(int $id = 1001): Product
    {
        return $this->liveProduct($id, [[
            'base_price' => $this->line('Undecorated', [100 => 1.00, 250 => 0.90], undecorated: true),
            'additions' => [
                $this->line('Pad Print 1 Colour 1 Position', [100 => 0.30, 250 => 0.25], setup: 60),
                $this->line('Extra colour/position', [100 => 0.20], setup: 45),
                $this->line('Gift Box', [100 => 0.80]),
            ],
        ]]);
    }

    public function test_options_are_priced_with_supplier_markups_and_rounded_up(): void
    {
        $options = app(PriceOptionBuilder::class)->build($this->standardProduct())->keyBy('option_id');

        $undec = $options['1001-undecorated'];
        $this->assertSame(12, $undec->type_code);
        $this->assertSame('100:1,250:0.9', $undec->cost_string);
        $this->assertSame('100:1.45,250:1.31', $undec->price_string); // 0.90 x 1.45 = 1.305 -> 1.31

        $pad = $options['1001-padprint1colour1position'];
        $this->assertSame(10, $pad->type_code);
        $this->assertSame('100:0.45,250:0.38', $pad->price_string);    // 0.25 x 1.50 = 0.375 -> 0.38
        $this->assertSame(60.0, $pad->setup_price);

        $this->assertSame(11, $options['1001-extracolourposition']->type_code);
        $this->assertSame('100:0.29', $options['1001-extracolourposition']->price_string);
    }

    public function test_unknown_decorations_are_queued_and_use_the_accessory_markup(): void
    {
        $options = app(PriceOptionBuilder::class)->build($this->standardProduct())->keyBy('option_id');

        $gift = $options['1001-giftbox'];
        $this->assertNull($gift->type_code);
        $this->assertSame('100:1.16', $gift->price_string); // 0.80 x 1.45 = 1.16

        $queued = DecTypeCode::unclassified()->pluck('code')->all();
        $this->assertSame(['giftbox'], $queued);
        $this->assertSame('Gift Box', DecTypeCode::find('giftbox')->full_description);
    }

    public function test_staff_type_override_changes_type_but_not_markup(): void
    {
        $product = $this->standardProduct();
        PriceOptionSetting::create(['product_id' => 1001, 'option_id' => '1001-extracolourposition', 'type_code_override' => 10]);
        $options = app(PriceOptionBuilder::class)->build($product)->keyBy('option_id');

        $extra = $options['1001-extracolourposition'];
        $this->assertSame(10, $extra->type_code);
        $this->assertSame('100:0.29', $extra->price_string, 'Still priced with the accessory markup, as in TOD.');
    }

    public function test_web_prices_and_decoration_json(): void
    {
        $product = $this->standardProduct();
        app(PriceOptionBuilder::class)->build($product);
        $web = app(WebPriceCalculator::class)->calculate($product);

        $this->assertSame('AUTO', $web->decoration_source);
        $this->assertSame('1001-padprint1colour1position', $web->default_decoration_option_id);
        $this->assertSame(100, $web->actual_moq);
        $this->assertSame(100, $web->collection_moq);
        // 100 x 0.45 decoration + 60 decoration setup (own decorations, as TOD's
        // stored values show) + 0 undecorated setup + 100 x 1.45
        $this->assertSame(250.0, $web->order_value_100);
        $this->assertSame(2.5, $web->collection_variant_price);
        $this->assertSame('Extra colour/position', $web->accessory_list);
        $this->assertNotEmpty($web->warnings, 'The unclassified Gift Box line is flagged.');

        $this->assertSame(
            '{"BrandFlag":"Price includes branding","UndecoratedPriceSetup":"0","UndecoratedPriceString":"100:1.45,250:1.31",'
            .'"Decorations":[{"DecID":"1001-padprint1colour1position","DecName":"Pad Print 1 Colour 1 Position","CombinedSetup":"60","CombinedPrcString":"100:0.45,250:0.38"}]}',
            $web->decoration_json,
        );
    }

    public function test_default_decoration_comes_first_then_by_price_at_50(): void
    {
        DecTypeCode::create(['code' => 'embroidery', 'type_code' => 10]);
        DecTypeCode::create(['code' => 'laserengrave', 'type_code' => 10]);
        $product = $this->liveProduct(1002, [[
            'base_price' => $this->line('Undecorated', [100 => 1.00], undecorated: true),
            'additions' => [
                $this->line('Embroidery', [100 => 2.00], setup: 80),
                $this->line('Laser Engrave', [100 => 0.50], setup: 40),
                $this->line('Pad Print 1 Colour 1 Position', [100 => 0.30], setup: 60),
            ],
        ]]);
        PriceOptionSetting::create(['product_id' => 1002, 'option_id' => '1002-embroidery', 'is_default' => true]);

        app(PriceOptionBuilder::class)->build($product);
        $web = app(WebPriceCalculator::class)->calculate($product);
        $ids = array_column(json_decode($web->decoration_json, true)['Decorations'], 'DecID');

        $this->assertSame('1002-embroidery', $web->default_decoration_option_id, 'The staff default wins.');
        // Default first; then laser (price@50 = 100x0.75/50 = 1.5, + 40 setup) before pad (0.9 + 60).
        $this->assertSame(['1002-embroidery', '1002-laserengrave', '1002-padprint1colour1position'], $ids);
    }

    public function test_products_without_undecorated_or_decoration_prices_get_placeholders(): void
    {
        $decorated = $this->liveProduct(1003, [[
            'base_price' => $this->line('Pad Print 1 Colour 1 Position', [100 => 1.30], setup: 60),
            'additions' => [],
        ]]);
        $plain = $this->liveProduct(1004, [[
            'base_price' => $this->line('Undecorated', [50 => 2.00], undecorated: true),
            'additions' => [],
        ]]);

        app(PriceOptionBuilder::class)->build($decorated);
        $json = json_decode(app(WebPriceCalculator::class)->calculate($decorated)->decoration_json, true);
        $this->assertSame('1:0', $json['UndecoratedPriceString']);
        $this->assertTrue(PriceOption::where('option_id', '1003-unbranded')->value('placeholder'));

        app(PriceOptionBuilder::class)->build($plain);
        $json = json_decode(app(WebPriceCalculator::class)->calculate($plain)->decoration_json, true);
        // Matches TOD: the placeholder is a type-10 option, so BrandFlag still reads "includes".
        $this->assertSame('Price includes branding', $json['BrandFlag']);
        $this->assertSame([['DecID' => '1004-unbrandeddec', 'DecName' => 'Product is only available unbranded', 'CombinedSetup' => '0', 'CombinedPrcString' => '1:0']], $json['Decorations']);
    }

    public function test_external_decorations_and_moq_overrides(): void
    {
        DecTypeCode::create(['code' => 'fullcolourdtfprinttoa3maxsize', 'type_code' => 10]);
        Supplier::create(['id' => 999, 'name' => 'Melbourne Merch']);
        $mm = $this->liveProduct(10001999, [[
            'base_price' => $this->line('Full colour DTF print (to A3 max size)', [1 => 8.00, 20 => 6.00]),
            'additions' => [],
        ]]);
        Product::whereKey(10001999)->update(['supplier_id' => 999]);
        app(PriceOptionBuilder::class)->build($mm->refresh());

        $tee = $this->liveProduct(1005, [[
            'base_price' => $this->line('Undecorated', [10 => 5.00, 100 => 4.00], undecorated: true),
            'additions' => [],
        ]]);
        ProductOverride::create(['product_id' => 1005, 'data' => ['decoration_product_id' => 10001999, 'collection_moq' => 50]]);
        app(PriceOptionBuilder::class)->build($tee);
        $web = app(WebPriceCalculator::class)->calculate($tee->refresh());

        $this->assertSame('EXTERNAL', $web->decoration_source);
        $this->assertSame(10001999, $web->decoration_product_id);
        $this->assertSame('10001999-fullcolourdtfprinttoa3maxsize', $web->default_decoration_option_id);
        $this->assertSame(10, $web->actual_moq);
        $this->assertSame(50, $web->collection_moq);
        // 50 x 9.00 DTF (6.00 x 1.50) + 50 x 7.25 undecorated (5.00 x 1.45)
        $this->assertSame(812.5, $web->order_value_display);
        $this->assertSame(16.25, $web->collection_variant_price);
        $this->assertStringContainsString('"DecID":"10001999-fullcolourdtfprinttoa3maxsize"', $web->decoration_json);
    }

    public function test_names_with_line_breaks_produce_valid_json(): void
    {
        // TOD produced invalid JSON for 9 products with names like this.
        DecTypeCode::create(['code' => 'includingfullcolourprinting', 'type_code' => 10]);
        $product = $this->liveProduct(2838, [[
            'base_price' => $this->line("Including Full Colour Printing\r\r\n", [1 => 1.00]),
            'additions' => [],
        ]]);

        app(PriceOptionBuilder::class)->build($product);
        $json = json_decode(app(WebPriceCalculator::class)->calculate($product)->decoration_json, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('Including Full Colour Printing', $json['Decorations'][0]['DecName']);
    }

    public function test_the_validator_rejects_json_that_breaks_the_contract(): void
    {
        $validator = app(DecorationJson::class);

        $this->assertNotEmpty($validator->validate('{"BrandFlag":"yes"}'));
        $this->expectException(RuntimeException::class);
        $validator->assertValid('{"BrandFlag":"Price includes branding","UndecoratedPriceSetup":"0","UndecoratedPriceString":"1:0","Decorations":[{"DecID":"1-x","DecName":"a, b","CombinedSetup":"0","CombinedPrcString":"1:0"}]}');
    }

    public function test_summary_products_cannot_be_priced_until_hydrated(): void
    {
        $product = Product::create(['id' => 1006, 'supplier_id' => 3, 'name' => 'x']);

        $this->expectException(RuntimeException::class);
        app(PriceOptionBuilder::class)->build($product);
    }

    public function test_the_contract_schema_copy_matches_the_docs(): void
    {
        $this->assertJsonFileEqualsJsonFile(
            base_path('docs/contracts/decorationjson.schema.json'),
            resource_path('contracts/decorationjson.schema.json'),
        );
    }
}
