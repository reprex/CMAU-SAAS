<?php

namespace Tests\Feature;

use App\Mail\WebsiteInvoiceMail;
use App\Models\PendingEmail;
use App\Models\PriceOption;
use App\Models\Product;
use App\Models\ProductWebPrice;
use App\Models\Supplier;
use App\Models\WebsiteOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CheckoutFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

/**
 * Website samples (Scott, 1 Nov 2026): one samples cart and one invoice,
 * grouped by factory without naming the supplier, the supplier's sample
 * freight once per factory, GST on the lot, Australia only.
 * Invented test products; Trends and Dex are the suppliers that offer samples.
 */
class WebsiteSamplesTest extends TestCase
{
    use CheckoutFixtures, QuoteFixtures, RefreshDatabase;

    private const TOTE = 4126;

    private const BEANIE = 4243;

    private const DEX = 9101;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-30 10:00', 'Australia/Melbourne')); // a Friday
        config(['app.admin_allowed_ips' => []]);
        $this->setUpCheckout();
        Supplier::create(['id' => 3, 'name' => 'Trends Collection', 'state' => 'VIC', 'offers_samples' => true, 'sample_freight' => 12.50]);
        Supplier::create(['id' => 20, 'name' => 'Dex Collection', 'state' => 'NSW', 'offers_samples' => true, 'sample_freight' => 14, 'sample_lead_days' => 3]);
        $this->product(self::TOTE, 3, '4126', 'Cotton tote bag', '25:4.00,100:3.50', ['Natural', 'Black']);
        $this->product(self::BEANIE, 3, '4243', 'Knitted beanie', '50:3.20,250:2.90', ['Black']);
        $this->product(self::DEX, 20, 'M101', 'Aluminium sports bottle', '100:1.80', ['Blue']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(int $id, int $supplier, string $code, string $name, string $prices, array $colours): void
    {
        $p = Product::create(['id' => $id, 'supplier_id' => $supplier, 'code' => $code, 'name' => $name, 'tier' => 'live']);
        foreach ($colours as $i => $c) {
            $p->colours()->create(['name' => $c, 'position' => $i]);
        }
        PriceOption::create(['product_id' => $id, 'option_id' => "{$id}-undecorated", 'code' => 'undecorated', 'kind' => 'UNDEC', 'type_code' => 12, 'description' => 'Unbranded', 'price_string' => $prices]);
        ProductWebPrice::create(['product_id' => $id, 'decoration_product_id' => $id, 'undecorated_option_id' => "{$id}-undecorated"]);
    }

    private function items(): array
    {
        return [
            ['product' => self::TOTE, 'colour' => 'Natural'],
            ['product' => self::DEX, 'colour' => 'Blue'],
            ['product' => self::BEANIE, 'colour' => 'Black'],
            ['product' => self::TOTE, 'colour' => 'natural'], // the same sample again: one only
        ];
    }

    private function samplesPrice(array $body)
    {
        return $this->withHeaders(['Origin' => self::ORIGIN])->postJson('/crowdmerch/checkout/samples/price', $body);
    }

    private function samplesOrder(array $override = [])
    {
        return $this->withHeaders(['Origin' => self::ORIGIN])->postJson('/crowdmerch/checkout/samples/order', array_replace_recursive([
            'items' => $this->items(),
            'contact' => ['name' => 'Scott Wilson', 'organisation' => 'Replicat', 'email' => 'Client@Example.com', 'phone' => '0400 000 000'],
            'address' => ['street' => '12 Smith Street', 'suburb' => 'Collingwood', 'state' => 'VIC', 'postcode' => '3066', 'country' => 'AU'],
        ], $override));
    }

    public function test_samples_are_grouped_by_factory_with_freight_once_each_and_gst_on_the_lot(): void
    {
        $r = $this->samplesPrice(['items' => $this->items(), 'postcode' => '3066'])->assertOk();
        $r->assertJson(['success' => true, 'can_order' => true, 'issues' => [], 'samples' => 3]);

        $f = $r->json('factories');
        $this->assertSame(['From factory 1', 'From factory 2'], array_column($f, 'label')); // in order of first appearance
        $this->assertSame(['3-4126', '3-4243'], array_column($f[0]['items'], 'code'));
        $this->assertSame('3-4126-NATU', $f[0]['items'][0]['sku']);
        // Unbranded price at the MOQ: tote 25 @ 4.00, beanie 50 @ 3.20; the bottle 100 @ 1.80.
        $this->assertSame([4.0, 3.2], array_map('floatval', array_column($f[0]['items'], 'unit')));
        $this->assertSame([7.2, 12.5, 19.7], [(float) $f[0]['goods'], (float) $f[0]['freight'], (float) $f[0]['subtotal']]);
        $this->assertSame([1.8, 14.0, 15.8], [(float) $f[1]['goods'], (float) $f[1]['freight'], (float) $f[1]['subtotal']]);
        $this->assertSame(['goods' => 9.0, 'freight' => 26.5, 'subtotal' => 35.5, 'gst_rate' => 0.1, 'gst' => 3.55, 'total' => 39.05, 'inc_gst' => true, 'currency' => 'AUD'],
            array_map(fn ($v) => is_numeric($v) && ! is_bool($v) ? (float) $v : $v, $r->json('totals')));

        // Order check 1, the slowest factory's supply (Dex 3 days, NSW holidays), then VIC road freight's slowest day (2).
        $this->assertSame([['order_check', 1, '2026-11-02'], ['supply', 3, '2026-11-05'], ['freight', 2, '2026-11-09']],
            array_map(fn ($s) => [$s['key'], $s['days'], $s['ends']], $r->json('delivery_estimate.stages')));
        $this->assertSame('2026-11-09', $r->json('delivery_estimate.delivery_date'));

        // The client never sees which supplier a factory is.
        $body = $r->getContent();
        foreach (['Trends', 'Dex', 'supplier'] as $secret) {
            $this->assertStringNotContainsStringIgnoringCase($secret, $body);
        }
    }

    public function test_each_samples_rule_has_its_issue(): void
    {
        $codes = fn ($r) => array_column($r->assertOk()->json('issues'), 'code');

        $this->assertSame(['no_samples'], $codes($this->samplesPrice(['items' => [['product' => self::MENS, 'colour' => 'Black']], 'postcode' => '3066'])));
        $this->assertContains('not_australia', $codes($this->samplesPrice(['items' => $this->items(), 'postcode' => '0600', 'country' => 'NZ'])));
        $this->assertSame(['empty'], $codes($this->samplesPrice(['items' => [], 'postcode' => '3066'])));
        $this->assertSame(['colour'], $codes($this->samplesPrice(['items' => [['product' => self::TOTE, 'colour' => 'Purple'], ['product' => self::BEANIE]], 'postcode' => '3066'])));
        $this->assertSame(['postcode'], $codes($this->samplesPrice(['items' => $this->items()])));
        Supplier::find(20)->update(['sample_freight' => null]);
        $this->assertSame(['sample_freight'], $codes($this->samplesPrice(['items' => $this->items(), 'postcode' => '3066'])));

        $r = $this->samplesPrice(['items' => [['product' => self::MENS, 'colour' => 'Black']], 'postcode' => '3066']);
        $this->assertSame('7-5001', $r->json('issues.0.product_code')); // our code, never the supplier's
    }

    public function test_a_samples_order_is_one_order_and_one_invoice_reusing_the_order_machinery(): void
    {
        Storage::fake('local');
        Mail::fake();
        $r = $this->samplesOrder()->assertCreated();
        $r->assertJson(['success' => true, 'kind' => 'samples', 'order_number' => 'CM-10001', 'total' => 39.05, 'inc_gst' => true, 'eta' => '2026-11-09', 'invoice' => 'pending_review']);
        $this->assertNotEmpty($r->json('account_token'));

        $order = WebsiteOrder::query()->with('lines')->sole();
        $this->assertSame(['samples', null, null, 0], [$order->kind, $order->need_by, $order->supplier_id, $order->stage]);
        $this->assertSame([[1, 3, '3-4126', '4126', 'Natural'], [1, 3, '3-4243', '4243', 'Black'], [2, 20, '20-M101', 'M101', 'Blue']],
            $order->lines->map(fn ($l) => [$l->factory, $l->supplier_id, $l->our_code, $l->product_code, $l->colour])->all());
        $this->assertSame(['From factory 1', 'From factory 2'], array_column($order->factoryGroups(), 'label'));
        $this->assertSame([12.5, 14.0], array_column($order->factoryGroups(), 'freight'));

        // The invoice email waits for staff (principle 6), as for an order; the invoice PDF is kept privately.
        $this->assertSame(['invoice', 'draft'], [PendingEmail::sole()->kind, PendingEmail::sole()->status]);
        Storage::disk('local')->assertExists($order->invoice_path);
        Mail::assertNothingSent();

        // Invoice and email: factories, our codes, never a supplier's name.
        $html = view('pdf.website-samples-invoice', ['order' => $order, 'factories' => $order->factoryGroups(), 'logo' => null, 'signature' => null, 'brand' => config('quotes.pdf'), 'fontDir' => resource_path('fonts')])->render();
        $this->assertStringContainsString('From factory 2', $html);
        $this->assertStringContainsString('Code 20-M101', $html);
        $this->assertStringContainsString('Factory 1 subtotal ex GST', $html);
        $mail = (new WebsiteInvoiceMail($order))->render();
        $this->assertStringContainsString('sample order CM-10001', $mail);
        foreach (['Trends', 'Dex'] as $name) {
            $this->assertStringNotContainsString($name, $html);
            $this->assertStringNotContainsString($name, $mail);
        }
    }

    public function test_a_samples_order_that_fails_a_rule_is_refused(): void
    {
        $this->samplesOrder(['address' => ['country' => 'NZ', 'state' => null, 'postcode' => '0600']])->assertStatus(422)
            ->assertJsonPath('issues.0.code', 'not_australia');
        $this->samplesOrder(['items' => [['product' => self::MENS, 'colour' => 'Black']]])->assertStatus(422)->assertJsonPath('issues.0.code', 'no_samples');
        $this->samplesOrder(['contact' => ['email' => 'not-an-email']])->assertStatus(422)->assertJsonValidationErrors('contact.email');
        $this->assertSame(0, WebsiteOrder::count());
    }

    public function test_my_orders_shows_sample_stages_and_takes_changes_until_dispatched(): void
    {
        Storage::fake('local');
        Mail::fake();
        $token = $this->samplesOrder()->assertCreated()->json('account_token');
        $orders = fn () => $this->withHeaders(['Origin' => self::ORIGIN, 'Authorization' => 'Bearer '.$token])->getJson('/crowdmerch/account/orders')->assertOk();

        $o = $orders()->json('orders.0');
        $this->assertSame(['samples', ['Order received', 'Invoice sent', 'Paid', 'Dispatched', 'Delivered'], 0, null], [$o['kind'], $o['stages'], $o['stage'], $o['need_by']]);
        $this->assertSame('Cotton tote bag 3-4126 · Natural, Knitted beanie 3-4243 · Black, Aluminium sports bottle 20-M101 · Blue', $o['items']);
        $this->assertSame(['From factory 1', 'From factory 2'], array_column($o['factories'], 'label'));
        $this->assertTrue($o['can_request_change']);

        $change = fn () => $this->withHeaders(['Origin' => self::ORIGIN, 'Authorization' => 'Bearer '.$token])->postJson('/crowdmerch/account/change', ['order_number' => 'CM-10001', 'message' => 'Please make the tote black']);
        $change()->assertCreated();

        WebsiteOrder::query()->update(['stage' => WebsiteOrder::STAGE_DISPATCHED, 'carrier' => 'TNT', 'consignment' => 'GEA1']);
        $o = $orders()->json('orders.0');
        $this->assertSame([3, 'Dispatched', false], [$o['stage'], $o['stage_name'], $o['can_request_change']]);
        $this->assertStringContainsString('GEA1', (string) $o['tracking_url']);
        $change()->assertStatus(409);
    }

    public function test_staff_see_the_samples_tag_and_each_factorys_supplier(): void
    {
        Storage::fake('local');
        Mail::fake();
        $this->samplesOrder()->assertCreated();
        $order = WebsiteOrder::sole();
        $this->staff('sales');

        $this->get(route('website-orders.index'))->assertOk()->assertSee('CM-10001')->assertSee('Samples');
        $this->get(route('website-orders.index', ['kind' => 'order']))->assertOk()->assertDontSee('CM-10001');
        $this->get(route('website-orders.show', $order))->assertOk()->assertSee('Samples by factory')
            ->assertSee('From factory 1 · Trends Collection')->assertSee('From factory 2 · Dex Collection')->assertSee('20-M101')->assertDontSee('Proof approved');

        $this->put(route('website-orders.update', $order), ['stage' => 3])->assertSessionHasErrors('stage'); // no proof stage for samples
        $this->put(route('website-orders.update', $order), ['stage' => WebsiteOrder::STAGE_DISPATCHED, 'carrier' => 'TNT', 'consignment' => 'GEA2'])->assertRedirect();
        $this->assertSame(WebsiteOrder::STAGE_DISPATCHED, $order->fresh()->stage);
    }

    public function test_admins_set_which_suppliers_offer_samples_and_their_sample_freight(): void
    {
        $this->staff('admin');
        $this->get(route('admin.suppliers.edit', 7))->assertOk()->assertSee('Offers samples on the website')->assertSee('Sample freight per consignment ex GST');
        $this->put(route('admin.suppliers.update', 7), ['freight_basis' => 'per_delivery', 'offers_samples' => '1', 'sample_freight' => '11.00', 'sample_lead_days' => 4])->assertRedirect();
        $s = Supplier::find(7);
        $this->assertSame([true, '11.00', 4, 4], [$s->offers_samples, $s->sample_freight, $s->sample_lead_days, $s->sampleLeadDays()]);
        $this->assertSame(2, Supplier::find(53)->sampleLeadDays()); // the stock lead time default
    }

    public function test_the_order_invoice_shows_our_codes_not_the_suppliers(): void
    {
        Storage::fake('artwork');
        Storage::fake('local');
        Mail::fake();
        $this->placeOrder($this->orderForm($this->cart()))->assertCreated();
        $order = WebsiteOrder::query()->with(['lines', 'positions'])->sole();
        $this->assertSame(['5001', '7-5001', '5001-BLK-M', '7-5001-BLAC-M'], [$order->lines[0]->product_code, $order->lines[0]->our_code, $order->lines[0]->supplier_sku, $order->lines[0]->sku]);

        $html = view('pdf.website-invoice', ['order' => $order, 'image' => null, 'styles' => $order->lines->groupBy('product_id'), 'logo' => null, 'signature' => null, 'brand' => config('quotes.pdf'), 'fontDir' => resource_path('fonts')])->render();
        $this->assertStringContainsString('Code 7-5001', $html);
        $this->assertStringContainsString('7-5001-BLAC-M', $html);
        $this->assertStringNotContainsString('5001-BLK-M', $html);
        $this->assertStringNotContainsString('Stock from AS Colour', $html);
    }
}
