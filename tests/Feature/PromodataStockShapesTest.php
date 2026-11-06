<?php

namespace Tests\Feature;

use App\Stock\PromodataStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\CheckoutFixtures;
use Tests\TestCase;

/**
 * Live stock rows from Promodata check-stock-levels in the shapes suppliers
 * return. The bug: rows without a size slot ([colour, qty, sku]) were read
 * positionally, so the quantity became the size ("Natural 850: ?").
 */
class PromodataStockShapesTest extends TestCase
{
    use CheckoutFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-30 10:00', 'Australia/Melbourne'));
        $this->setUpCheckout();
        config(['quotes.stock_relay.allowed_origins' => []]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function stockReply(array $rows, int $id = self::MENS): void
    {
        Http::swap(new Factory);
        Http::fake(["api.promodata.test/products/{$id}/check-stock-levels" => Http::response(['data' => ['stock' => $rows]])]);
    }

    private function rows(array $stock): array
    {
        $this->stockReply($stock);

        return app(PromodataStock::class)->rows(self::MENS, fresh: true)['rows'];
    }

    private function row(?string $colour, ?string $size, ?int $qty, ?string $sku): array
    {
        return ['colour' => $colour, 'size' => $size, 'qty' => $qty, 'sku' => $sku];
    }

    public function test_rows_without_a_size_slot_get_no_size_and_the_number_as_quantity(): void
    {
        // [colour, qty, sku]: the shape behind "Natural 850: ?".
        $this->assertSame([
            $this->row('Natural', null, 850, 'BAG-NAT'),
            $this->row('Black', null, 0, 'BAG-BLK'),
        ], $this->rows([['Natural', 850, 'BAG-NAT'], ['Black', '0', 'BAG-BLK']]));

        // [colour, qty] and [colour, qty, sku, null].
        $this->assertSame([$this->row('Natural', null, 850, null)], $this->rows([['Natural', 850]]));
        $this->assertSame([$this->row('Natural', null, 850, 'BAG-NAT')], $this->rows([['Natural', '850', 'BAG-NAT', null]]));
    }

    public function test_rows_keyed_by_name_are_read_by_name(): void
    {
        $this->assertSame([
            $this->row('Natural', null, 850, 'BAG-NAT'),
            $this->row('Black', 'M', 12, 'T-BLK-M'),
        ], $this->rows([
            ['colour' => 'Natural', 'quantity' => 850, 'sku' => 'BAG-NAT'],
            ['Colour' => 'Black', 'Size' => 'M', 'Qty' => '12', 'SKU' => 'T-BLK-M'],
        ]));
    }

    public function test_full_rows_keep_their_sizes_including_numeric_ones(): void
    {
        $this->assertSame([
            $this->row('Black', 'M', 500, '5001-BLK-M'),
            $this->row('Black', '8', 120, 'SHOE-BLK-8'),     // shoe size
            $this->row('Navy', '4', 30, 'KIDS-NVY-4'),       // kids size sent as a number
            $this->row('Navy', '10', 0, 'KIDS-NVY-10'),
            $this->row('White', '8', null, 'SHOE-WHT-8'),    // size 8, quantity unknown: still size 8
            $this->row('Black', null, 300, 'PEN-BLK'),       // no size in a full row (existing convention)
            $this->row('Red', null, 120, 'PEN-RED'),
        ], $this->rows([
            ['Black', 'M', 500, '5001-BLK-M'],
            ['Black', '8', 120, 'SHOE-BLK-8'],
            ['Navy', 4, 30, 'KIDS-NVY-4'],
            ['Navy', '10', 0, 'KIDS-NVY-10'],
            ['White', '8', null, 'SHOE-WHT-8'],
            ['Black', null, 300, 'PEN-BLK'],
            ['Red', '', 120, 'PEN-RED'],
        ]));

        // [colour, size, qty] with no SKU: the third value is the quantity, so "8" stays the size.
        $this->assertSame([$this->row('Black', '8', 120, null), $this->row('Black', 'M', 40, null)], $this->rows([['Black', '8', 120], ['Black', 'M', 40]]));
    }

    public function test_the_relay_puts_rows_without_a_size_slot_in_the_order_the_theme_reads(): void
    {
        $this->stockReply([['Natural', 850, 'BAG-NAT'], ['Black', 'M', 500, '5001-BLK-M']]);
        $this->get('/stock.php?id='.self::MENS)->assertOk()->assertExactJson(['data' => ['stock' => [
            ['Natural', null, 850, 'BAG-NAT'], ['Black', 'M', 500, '5001-BLK-M'],
        ]]]);

        // Normal replies go through byte for byte.
        cache()->flush();
        $body = json_encode(['data' => ['stock' => [['Black', '8', 120, 'SHOE-BLK-8']]]]);
        Http::swap(new Factory);
        Http::fake(['api.promodata.test/products/*/check-stock-levels' => Http::response($body)]);
        $this->assertSame($body, $this->get('/stock.php?id='.self::MENS)->getContent());
    }

    public function test_checkout_orders_a_no_size_product_as_one_size_against_the_real_quantity(): void
    {
        $this->stockReply([['Black', 850, '5001-BLK'], ['Navy', 20, '5001-NVY']]);
        $line = fn (string $colour, int $qty) => ['lines' => [['product_id' => self::MENS, 'colour' => $colour, 'size' => 'One size', 'qty' => $qty]]];

        $ok = $this->price($this->cart($line('Black', 60)))->assertOk();
        $this->assertNotContains('stock', array_column($ok->json('issues'), 'code'));
        $this->assertSame(850, $ok->json('lines.0.stock'));

        $short = $this->price($this->cart($line('Navy', 60)))->assertOk();
        $stock = collect($short->json('issues'))->firstWhere('code', 'stock');
        $this->assertSame(20, $stock['available'] ?? null);
    }
}
