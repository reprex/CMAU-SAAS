<?php

namespace Tests\Support;

use App\Models\PriceOption;
use App\Models\Product;
use App\Models\ProductWebPrice;
use App\Models\RelatedStyle;
use App\Models\Supplier;
use App\Models\WebsiteOrder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

/**
 * Website checkout fixtures: AS Colour Mens Staple Tee (43446) with a related
 * Womens Maple Tee (43447), and an unrelated cap from another supplier.
 *
 * Unbranded: mens 25:10.00,50:9.00,100:8.00,250:7.50 · womens 0.45 less at every break.
 * Decorations: DTF 7.75 (no setup), Oversize DTF 10.85, Screen print 1-4 colours
 * (4.20/45, 5.60/90, 6.90/135, 8.10/180), Pad print 1 colour 0.65/55, Embroidery 10.15/52.50.
 */
trait CheckoutFixtures
{
    protected const ORIGIN = 'https://cmau-dev.myshopify.com';

    protected const MENS = 43446;

    protected const WOMENS = 43447;

    protected const CAP = 25384;

    protected function setUpCheckout(): void
    {
        config([
            'website.orders.enabled' => true,
            'website.orders.allowed_origins' => [self::ORIGIN],
            'promodata.token' => 'test-token',
            'promodata.base_url' => 'https://api.promodata.test',
        ]);

        Supplier::create(['id' => 7, 'name' => 'AS Colour', 'state' => 'VIC']);
        Supplier::create(['id' => 53, 'name' => 'Headwear Co', 'state' => 'NSW']);

        $this->tee(self::MENS, '5001', 'AS Colour Mens Staple Tee', '25:10.00,50:9.00,100:8.00,250:7.50');
        $this->tee(self::WOMENS, '4001', 'AS Colour Womens Maple Tee', '25:9.55,50:8.55,100:7.55,250:7.05');
        $this->decorations(self::MENS);
        RelatedStyle::create(['product_id' => self::MENS, 'related_product_id' => self::WOMENS, 'label' => 'Womens fit']);

        Product::create(['id' => self::CAP, 'supplier_id' => 53, 'name' => 'Heavy brushed cotton cap', 'code' => 'CH35', 'tier' => 'live', 'can_check_stock' => true]);
        PriceOption::create(['product_id' => self::CAP, 'option_id' => self::CAP.'-undecorated', 'code' => 'undecorated', 'kind' => 'UNDEC', 'type_code' => 12, 'description' => 'Unbranded', 'price_string' => '25:5.00']);
        ProductWebPrice::create(['product_id' => self::CAP, 'decoration_product_id' => self::CAP, 'undecorated_option_id' => self::CAP.'-undecorated']);

        $this->fakeStock();
    }

    protected function tee(int $id, string $code, string $name, string $prices): void
    {
        Product::create(['id' => $id, 'supplier_id' => 7, 'name' => $name, 'code' => $code, 'tier' => 'live', 'can_check_stock' => true,
            'promodata_type_name' => 'T-Shirts', 'hero_image_url' => 'https://example.com/tee.png']);
        PriceOption::create(['product_id' => $id, 'option_id' => "{$id}-undecorated", 'code' => 'undecorated', 'kind' => 'UNDEC', 'type_code' => 12,
            'description' => 'Unbranded', 'price_string' => $prices, 'setup_price' => 0]);
        ProductWebPrice::create(['product_id' => $id, 'decoration_product_id' => $id, 'undecorated_option_id' => "{$id}-undecorated"]);
    }

    protected function decorations(int $id): void
    {
        $add = fn (string $code, string $desc, string $price, float $setup) => PriceOption::create([
            'product_id' => $id, 'option_id' => "{$id}-{$code}", 'code' => $code, 'kind' => 'PDADEC', 'type_code' => 10,
            'description' => $desc, 'price_string' => $price, 'setup_price' => $setup,
        ]);
        $add('dtf', 'DTF print up to A3', '1:7.75', 0);
        $add('oversizedtf', 'Oversize DTF transfer up to 380 x 480mm', '1:10.85', 0);
        $add('screen1', 'Screen print 1 colour', '1:4.20', 45);
        $add('screen2', 'Screen print 2 colours', '1:5.60', 90);
        $add('screen3', 'Screen print 3 colours', '1:6.90', 135);
        $add('screen4', 'Screen print 4 colours', '1:8.10', 180);
        $add('pad1', 'Pad print 1 colour', '1:0.65', 55);
        $add('embroidery', 'Embroidery up to 8000 stitches', '1:10.15', 52.50);
    }

    /** Live stock: mens Black S-XL 500 each (XL 20), womens White 8-12 300 each. */
    protected function fakeStock(array $override = []): void
    {
        Http::fake($override + [
            'api.promodata.test/products/'.self::MENS.'/check-stock-levels' => Http::response(['data' => ['stock' => [
                ['Black', 'S', 500, '5001-BLK-S'], ['Black', 'M', 500, '5001-BLK-M'], ['Black', 'L', 500, '5001-BLK-L'], ['Black', 'XL', 20, '5001-BLK-XL'],
            ]]]),
            'api.promodata.test/products/'.self::WOMENS.'/check-stock-levels' => Http::response(['data' => ['stock' => [
                ['White', '8', 300, '4001-WHT-8'], ['White', '10', 300, '4001-WHT-10'], ['White', '12', 300, '4001-WHT-12'],
            ]]]),
            'api.promodata.test/products/'.self::CAP.'/check-stock-levels' => Http::response(['data' => ['stock' => [['Navy', 'OSFA', 900, 'CH35-NVY']]]]),
        ]);
    }

    /** A cart that can be ordered directly: 60 mens + 50 womens, front DTF, VIC, need-by well ahead. */
    protected function cart(array $override = []): array
    {
        return array_replace([
            'product_id' => self::MENS,
            'lines' => [
                ['product_id' => self::MENS, 'colour' => 'Black', 'size' => 'M', 'qty' => 30],
                ['product_id' => self::MENS, 'colour' => 'Black', 'size' => 'L', 'qty' => 30],
                ['product_id' => self::WOMENS, 'colour' => 'White', 'size' => '10', 'qty' => 50],
            ],
            'positions' => [['name' => 'Front centre', 'decID' => self::MENS.'-dtf', 'width_mm' => 280, 'has_logo' => true]],
            'delivery' => ['country' => 'AU', 'postcode' => '3066'],
            'freight_service' => 'tnt_road',
            'need_by' => '2026-12-04',
        ], $override);
    }

    protected function price(array $cart)
    {
        return $this->withHeaders(['Origin' => self::ORIGIN])->postJson('/crowdmerch/checkout/price', $cart);
    }

    /** The multipart order form for a cart (each position gets a logo file). */
    protected function orderForm(array $cart, array $override = []): array
    {
        $positions = array_map(fn ($p) => array_diff_key($p, ['has_logo' => 1]) + ['logo' => UploadedFile::fake()->image('logo.png', 400, 200)], $cart['positions']);

        return array_replace_recursive([
            'product_id' => $cart['product_id'],
            'lines' => $cart['lines'],
            'positions' => $positions,
            'freight_service' => $cart['freight_service'],
            'need_by' => $cart['need_by'],
            'contact' => ['name' => 'Scott Wilson', 'organisation' => 'Replicat', 'email' => 'Client@Example.com', 'phone' => '0400 000 000'],
            'address' => ['unit' => '', 'street' => '12 Smith Street', 'suburb' => 'Collingwood', 'state' => 'VIC', 'postcode' => $cart['delivery']['postcode'] ?? '3066', 'country' => $cart['delivery']['country']],
            'event' => ['name' => 'Summer Fun Run', 'date' => '2026-12-06', 'place' => 'Collingwood'],
            'proof_ack' => '1',
        ], $override);
    }

    protected function placeOrder(array $form)
    {
        return $this->withHeaders(['Origin' => self::ORIGIN, 'Accept' => 'application/json'])->post('/crowdmerch/checkout/order', $form);
    }

    protected function makeOrder(string $email, int $stage = 0, array $attrs = []): WebsiteOrder
    {
        static $n = 20000;
        $n++;

        return WebsiteOrder::create($attrs + [
            'number' => 'CM-'.$n, 'email' => $email, 'contact_name' => 'Pat Client', 'phone' => '0411 222 333',
            'address_street' => '1 Test St', 'address_suburb' => 'Brunswick', 'address_state' => 'VIC', 'address_postcode' => '3056', 'address_country' => 'AU',
            'need_by' => '2026-12-01', 'product_id' => self::MENS, 'supplier_id' => 7, 'freight_service' => 'tnt_road', 'freight_name' => 'TNT Road Express',
            'eta' => '2026-11-20', 'goods' => 100, 'setup' => 0, 'freight' => 20, 'subtotal' => 120, 'gst' => 12, 'total' => 132, 'stage' => $stage,
        ]);
    }
}
