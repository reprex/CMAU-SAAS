<?php

namespace Tests\Feature;

use App\Models\PriceCheck;
use App\Models\PriceLine;
use App\Models\PricePattern;
use App\Models\Product;
use App\Pricing\PricePatterns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class PriceCheckTest extends TestCase
{
    use QuoteFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.admin_allowed_ips' => []]);
        $this->supplier(3, 'Trends');
        $this->supplier(19, 'Dex Group');
    }

    /**
     * A product with an unbranded base line and a decorated base line (with an add-on).
     *
     * @param  array<int, float>  $blank  qty => unbranded cost
     * @param  array<int, float>  $branded  qty => branded cost
     */
    private function product(int $id, int $supplierId, string $brandedWording, array $blank, array $branded, string $tier = 'live'): Product
    {
        $product = Product::create(['id' => $id, 'supplier_id' => $supplierId, 'name' => "Product {$id}", 'code' => "C{$id}", 'tier' => $tier, 'hydrated_at' => now()]);
        $line = fn (array $attrs, array $breaks, ?int $base = null) => tap(PriceLine::create($attrs + ['product_id' => $id, 'base_price_line_id' => $base]),
            fn ($l) => collect($breaks)->each(fn ($cost, $qty) => $l->breaks()->create(['qty' => $qty, 'unit_cost' => $cost])));

        $line(['description' => 'Unbranded', 'undecorated' => true, 'setup_cost' => 0], $blank);
        $dec = $line(['description' => $brandedWording, 'undecorated' => false, 'setup_cost' => 50, 'promodata_decoration' => 'Pad Print'], $branded);
        $line(['description' => 'Extra colour', 'undecorated' => false, 'setup_cost' => 50], [100 => 0.2], $dec->id);

        app(PricePatterns::class)->assign($product);

        return $product->refresh();
    }

    public function test_products_priced_the_same_way_share_a_pattern_whatever_the_numbers(): void
    {
        $a = $this->product(1, 3, 'Pad print 1 colour 1 position', [100 => 2.00], [100 => 2.50]);
        $b = $this->product(2, 3, 'Pad print 2 colour 3 position', [100 => 5.00], [100 => 6.00]);
        $c = $this->product(3, 19, 'Pad print 1 colour 1 position', [100 => 2.00], [100 => 2.50]);
        $d = $this->product(4, 3, 'Laser engraving 1 position', [100 => 2.00], [100 => 2.50]);

        $this->assertNotNull($a->price_pattern);
        $this->assertSame($a->price_pattern, $b->price_pattern);
        $this->assertNotSame($a->price_pattern, $c->price_pattern, 'another supplier is another pattern');
        $this->assertNotSame($a->price_pattern, $d->price_pattern, 'other base wording is another pattern');
        $this->assertSame(1.25, $a->price_flags['ratio']);
        $this->assertStringContainsString('pad print # colour # position', PricePattern::find($a->price_pattern)->label);
    }

    public function test_flags_products_that_do_not_fit(): void
    {
        $cheaper = $this->product(1, 3, 'Pad print', [100 => 3.00], [100 => 2.50]);
        $rising = $this->product(2, 3, 'Pad print', [100 => 2.00, 250 => 1.80], [100 => 2.50, 250 => 2.70]);

        $this->assertTrue($cheaper->price_flags['branded_below_unbranded']);
        $this->assertTrue($rising->price_flags['rising_breaks']);
        $this->assertArrayNotHasKey('branded_below_unbranded', $rising->price_flags);

        $this->product(3, 3, 'Pad print', [100 => 2.00], [100 => 2.50]);
        app(PricePatterns::class)->refresh();
        $this->staff('admin');
        $this->get(route('admin.price-check.index', ['view' => 'flagged']))->assertOk()
            ->assertSee('Product 1')->assertSee('Branded base costs less than unbranded')
            ->assertSee('Product 2')->assertDontSee('Product 3');
    }

    public function test_refresh_counts_patterns_picks_live_examples_and_marks_ratio_outliers(): void
    {
        $this->product(1, 3, 'Pad print', [100 => 2.00], [100 => 2.40], 'active');
        $this->product(2, 3, 'Pad print', [100 => 2.00], [100 => 2.50]);
        $this->product(3, 3, 'Pad print', [100 => 2.00], [100 => 2.60]);
        $this->product(4, 3, 'Pad print', [100 => 2.00], [100 => 6.00]); // ratio 3 vs median 1.25

        $this->assertSame(1, app(PricePatterns::class)->refresh());

        $pattern = PricePattern::query()->first();
        $this->assertSame(4, $pattern->product_count);
        $this->assertSame(3, $pattern->live_count);
        $this->assertSame([2, 3], $pattern->examples);
        $this->assertSame(1, $pattern->flagged_count);
        $this->assertTrue(Product::find(4)->price_flags['ratio_outlier']);
        $this->assertArrayNotHasKey('ratio_outlier', Product::find(2)->price_flags);
    }

    public function test_one_check_verifies_the_whole_pattern(): void
    {
        $this->product(1, 3, 'Pad print', [100 => 2.00], [100 => 2.50]);
        $this->product(2, 3, 'Pad print', [100 => 2.00], [100 => 2.50]);
        app(PricePatterns::class)->refresh();
        $this->staff('admin');

        $this->get(route('admin.price-check.index'))->assertOk()->assertSee('Trends')->assertSee('To check')->assertSee('3-C1.png');

        $this->post(route('admin.price-check.store'), ['product_id' => 1, 'verdict' => 'base_branded', 'notes' => 'Site shows 1 col pad print included'])->assertRedirect();

        $check = PriceCheck::query()->sole();
        $this->assertSame(Product::find(1)->price_pattern, $check->price_pattern);
        $this->get(route('admin.price-check.index', ['status' => 'done']))->assertOk()->assertSee('Base price includes branding')->assertSee('100%');
        $this->get(route('admin.price-check.index'))->assertSee('Nothing left to check here.');
    }

    public function test_disagreeing_checks_reopen_the_pattern(): void
    {
        $this->product(1, 3, 'Pad print', [100 => 2.00], [100 => 2.50]);
        $this->product(2, 3, 'Pad print', [100 => 2.00], [100 => 2.50]);
        app(PricePatterns::class)->refresh();
        $this->staff('admin');

        $this->post(route('admin.price-check.store'), ['product_id' => 1, 'verdict' => 'base_branded']);
        $this->post(route('admin.price-check.store'), ['product_id' => 2, 'verdict' => 'base_unbranded']);

        $this->get(route('admin.price-check.index'))->assertSee('Checks disagree');
    }

    public function test_results_can_be_uploaded_as_csv_and_the_list_downloaded(): void
    {
        $this->product(1, 3, 'Pad print', [100 => 2.00], [100 => 2.50]);
        $this->product(2, 19, 'Print per col pos', [100 => 2.00], [100 => 2.50]);
        app(PricePatterns::class)->refresh();
        $this->staff('admin');

        $export = $this->get(route('admin.price-check.export'))->assertOk();
        $this->assertStringContainsString('save_screengrab_as', $export->streamedContent());
        $this->assertStringContainsString('19-C2.png', $export->streamedContent());

        $csv = "appaid,verdict,notes,evidence\n1,base_unbranded,Blank price on site,3-C1.png\n999,base_branded,,\n2,maybe,,\n";
        $this->post(route('admin.price-check.import'), ['file' => UploadedFile::fake()->createWithContent('checks.csv', $csv)])
            ->assertSessionHas('status', fn ($s) => str_contains($s, '1 checks recorded') && str_contains($s, '999 (not found)') && str_contains($s, "verdict 'maybe'"));

        $this->assertSame('3-C1.png', PriceCheck::query()->sole()->evidence);
    }

    public function test_price_check_is_admin_only(): void
    {
        $this->staff('sales');
        $this->get(route('admin.price-check.index'))->assertForbidden();
    }
}
