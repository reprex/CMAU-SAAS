<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Quotes\ItemSplitter;
use App\Quotes\QuoteCalculator;
use App\Stock\PromodataStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class QuoteFreightAndSplitTest extends TestCase
{
    use QuoteFixtures, RefreshDatabase;

    private function quote(): Quote
    {
        return Quote::create(['number' => 'MR1', 'delivery_city' => 'Brunswick', 'delivery_state' => 'VIC']);
    }

    private function item(Quote $q, int $supplierId, string $name, int $qty, float $product, float $dec = 0, float $setup = 0, array $extra = []): QuoteItem
    {
        return $q->items()->create(['supplier_id' => $supplierId, 'name' => $name, 'quantity' => $qty, 'product_unit_price' => $product,
            'decoration_unit_price' => $dec, 'decoration_setup_price' => $setup, 'decoration_name' => $dec ? 'Print' : null] + $extra);
    }

    public function test_one_freight_line_per_supplier_per_delivery_matches_the_approved_quote_design(): void
    {
        $this->supplier(3, 'Trends', ['freight_sell' => 22.50, 'freight_cost' => 15.00, 'freight_basis' => 'per_delivery']);
        $q = $this->quote();
        $this->item($q, 3, 'WNSDY Essential Tee', 25, 18.12, 5.25, 60);
        $this->item($q, 3, 'Bergen Stubby Cooler', 100, 1.03, 0.45, 60);

        app(QuoteCalculator::class)->recalculate($q);
        $q->refresh();

        $freight = $q->extras()->where('kind', 'freight')->get();
        $this->assertCount(1, $freight);
        $this->assertSame('Delivery to Brunswick VIC: WNSDY Essential Tee, Bergen Stubby Cooler', $freight[0]->description);
        $this->assertSame('874.75', number_format((float) $q->subtotal, 2));
        $this->assertSame('87.48', number_format((float) $q->gst, 2));
        $this->assertSame('962.23', number_format((float) $q->total, 2));
    }

    public function test_per_line_free_over_and_not_set_suppliers(): void
    {
        $this->supplier(2, 'Logo Line', ['freight_sell' => 29.00, 'freight_basis' => 'per_line']);
        $this->supplier(7, 'Free Co', ['freight_sell' => 20.00, 'free_freight_over' => 500]);
        $this->supplier(9, 'Unknown Co');
        $q = $this->quote();
        $this->item($q, 2, 'Monza Car Charger', 250, 3.66);
        $this->item($q, 2, 'HB Pencil', 500, 0.61);
        $this->item($q, 7, 'Big order', 100, 6.00);   // 600 >= 500: free
        $this->item($q, 9, 'Mystery', 10, 1.00);

        app(QuoteCalculator::class)->recalculate($q);
        $lines = $q->extras()->where('kind', 'freight')->get();

        $this->assertCount(2, $lines->where('supplier_id', 2));                     // per line
        $this->assertSame(29.0, (float) $lines->where('supplier_id', 2)->first()->unit_price);
        $free = $lines->firstWhere('supplier_id', 7);
        $this->assertSame(0.0, (float) $free->unit_price);
        $this->assertStringContainsString('free on this order', $free->description);
        $tbc = $lines->firstWhere('supplier_id', 9);
        $this->assertTrue($tbc->tbc);
        $this->assertNull($q->fresh()->cost_total);                                   // unknown costs
    }

    public function test_staff_price_changes_survive_recalculation_and_lines_go_when_the_supplier_goes(): void
    {
        $this->supplier(3, 'Trends', ['freight_sell' => 22.50]);
        $q = $this->quote();
        $item = $this->item($q, 3, 'Tee', 25, 18.12);
        $calc = app(QuoteCalculator::class);
        $calc->recalculate($q);

        $line = $q->extras()->where('kind', 'freight')->first();
        $line->update(['unit_price' => 35, 'overridden' => true]);
        $calc->recalculate($q->fresh());
        $this->assertSame(35.0, (float) $line->fresh()->unit_price);

        $item->delete();
        $calc->recalculate($q->fresh());
        $this->assertSame(0, $q->extras()->where('kind', 'freight')->count());
    }

    public function test_outside_decorator_delivers_and_the_supplier_leg_is_added_only_when_set(): void
    {
        $this->supplier(88, 'AS Colour', ['freight_sell' => 15.00, 'freight_decorator_leg' => true]);
        $this->supplier(900, 'Melbourne Merch', ['freight_sell' => 18.00, 'is_decorator' => true]);
        Product::create(['id' => 10002000, 'supplier_id' => 900, 'name' => 'Melbourne Merch decoration price file']);
        $q = $this->quote();
        $this->item($q, 88, 'Heavy Crew', 60, 29.50, 4.80, 35, ['product_id' => 5145, 'decoration_product_id' => 10002000]);

        app(QuoteCalculator::class)->recalculate($q);
        $lines = $q->extras()->where('kind', 'freight')->orderBy('position')->get();

        $this->assertCount(2, $lines);
        $this->assertSame([900, 88], $lines->pluck('supplier_id')->all());
        $this->assertStringStartsWith('Delivery to Brunswick', $lines[0]->description);
        $this->assertStringStartsWith('Freight to decorator', $lines[1]->description);
    }

    public function test_size_split_from_the_live_sku_list_sets_quantity_and_reprices(): void
    {
        config(['promodata.token' => 'test-token']);
        Http::fake(['api.promodata.com.au/products/25384/check-stock-levels' => Http::response(['data' => ['stock' => [
            ['Navy', 'S', 120, '25384-NAVY-S'], ['Navy', 'M', 5, '25384-NAVY-M'], ['Black', 'M', 900, '25384-BLACK-M'],
        ]]])]);
        $this->supplier(53, 'Shiny');
        $this->catalogueProduct(25384, 53);
        $q = $this->quote();
        $item = $this->item($q, 53, 'Cap', 99, 4.21, 4.13, 45, ['product_id' => 25384, 'colour' => 'Navy', 'stage' => 'separated',
            'product_option_id' => '25384-undecorated', 'decoration_option_id' => '25384-embroideryto5kstitches']);

        $splitter = app(ItemSplitter::class);
        $list = $splitter->skuList($item);
        $this->assertTrue($list['ok']);
        $this->assertSame('Navy', $list['rows'][0]['colour']);    // the item's colour first
        $this->assertSame('25384-NAVY-S', $list['rows'][0]['sku']);

        $result = $splitter->saveSkus($item, [
            ['colour' => 'Navy', 'size' => 'S', 'quantity' => 60, 'supplier_sku' => '25384-NAVY-S', 'stock_on_hand' => 120],
            ['colour' => 'Navy', 'size' => 'M', 'quantity' => 40, 'supplier_sku' => '25384-NAVY-M', 'stock_on_hand' => 5],
            ['colour' => 'Black', 'size' => 'M', 'quantity' => 0],
        ], 'list');

        $item->refresh();
        $this->assertTrue($result['quantity_changed']);
        $this->assertTrue($result['repriced']);
        $this->assertSame('sku', $item->stage);
        $this->assertSame(100, $item->quantity);
        $this->assertSame('3.90', number_format((float) $item->product_unit_price, 2));   // the 100 break
        $this->assertSame('3.80', number_format((float) $item->decoration_unit_price, 2));
        $this->assertCount(2, $item->skus);
        $this->assertTrue($item->skus[1]->isShort());                                     // 40 wanted, 5 in stock
        $this->assertSame(['Navy' => [['size' => 'S', 'qty' => 60], ['size' => 'M', 'qty' => 40]]], $item->sizeBreakdown());
    }

    public function test_manual_sizes_for_products_without_a_sku_list(): void
    {
        $q = $this->quote();
        $item = $this->item($q, 1, 'Custom hoodie', 10, 40, extra: ['stage' => 'separated']);
        $list = app(ItemSplitter::class)->skuList($item);
        $this->assertFalse($list['ok']);

        app(ItemSplitter::class)->saveSkus($item, [['size' => 'M', 'quantity' => 6], ['size' => 'L', 'quantity' => 4]], 'manual');
        $this->assertSame('sku', $item->fresh()->stage);
        $this->assertSame(10, $item->fresh()->quantity);
    }

    public function test_promodata_no_information_reply_is_reported_not_thrown(): void
    {
        config(['promodata.token' => 'test-token']);
        Http::fake(['*' => Http::response(['message' => 'Queried the supplier but they returned no information', 'data' => ['stock' => []]])]);
        $rows = app(PromodataStock::class)->rows(43446);
        $this->assertFalse($rows['ok']);
        $this->assertSame('Queried the supplier but they returned no information', $rows['message']);
    }
}
