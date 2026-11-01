<?php

namespace Tests\Feature;

use App\Checkout\BusinessDays;
use App\Checkout\DeliveryEstimator;
use App\Freight\Consignment;
use App\Freight\EstimateFreightDriver;
use App\Freight\PackData;
use App\Mail\WebsiteInvoiceMail;
use App\Models\PendingEmail;
use App\Models\Product;
use App\Models\ProductDetail;
use App\Models\WebsiteOrder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CheckoutFixtures;
use Tests\TestCase;

class WebsiteCheckoutTest extends TestCase
{
    use CheckoutFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-30 10:00', 'Australia/Melbourne')); // a Friday
        $this->setUpCheckout();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_whole_order_is_priced_at_the_total_quantity_break_with_each_style_keeping_its_own_price(): void
    {
        $r = $this->price($this->cart())->assertOk();

        $r->assertJson(['success' => true, 'can_order' => true, 'issues' => [], 'units' => 110]);
        // 110 units in total: the 100 break for both styles (alone, 60 and 50 would each be at the 50 break).
        $styles = collect($r->json('styles'))->keyBy('product_id');
        $this->assertSame(8.0, (float) $styles[self::MENS]['unbranded_unit']);
        $this->assertSame(7.55, (float) $styles[self::WOMENS]['unbranded_unit']);
        $this->assertSame(15.75, (float) $styles[self::MENS]['unit']);   // + DTF 7.75
        $this->assertSame(15.3, (float) $styles[self::WOMENS]['unit']);
        $this->assertSame(1710.0, (float) $r->json('totals.goods'));     // 60 × 15.75 + 50 × 15.30
        // Clients see our codes only (Scott, 1 Nov 2026): SupplierID-Code and our SKU, never the supplier's SKU or name.
        $this->assertSame('7-5001-BLAC-M', $r->json('lines.0.sku'));
        $this->assertSame('7-5001', $r->json('lines.0.product_code'));
        $this->assertArrayNotHasKey('supplier_sku', $r->json('lines.0'));
        $this->assertArrayNotHasKey('supplier', $r->json('product'));
        $this->assertSame([['product_id' => self::WOMENS, 'code' => '7-4001', 'name' => 'AS Colour Womens Maple Tee', 'label' => 'Womens fit']], $r->json('related_styles'));
    }

    public function test_setup_is_charged_once_per_position_and_methods_price_by_their_own_rules(): void
    {
        $r = $this->price($this->cart(['positions' => [
            ['name' => 'Front centre', 'decID' => self::MENS.'-screen1', 'colours' => 3, 'has_logo' => true], // screen: the 3-colour line
            ['name' => 'Back neck', 'decID' => self::MENS.'-pad1', 'colours' => 2, 'has_logo' => true],        // pad: 1 colour × 2
            ['name' => 'Left chest', 'decID' => self::MENS.'-embroidery', 'colours' => 5, 'has_logo' => true], // embroidery: flat
        ]]))->assertOk();

        $p = collect($r->json('positions'))->keyBy('name');
        $this->assertSame([6.9, 135.0], [(float) $p['Front centre']['unit'], (float) $p['Front centre']['setup']]);
        $this->assertSame(self::MENS.'-screen3', $p['Front centre']['decoration_option_id']);
        $this->assertSame([1.3, 110.0], [(float) $p['Back neck']['unit'], (float) $p['Back neck']['setup']]);
        $this->assertSame([10.15, 52.5], [(float) $p['Left chest']['unit'], (float) $p['Left chest']['setup']]);
        // Two styles, three positions: setup is each position's once, not per style.
        $this->assertSame(297.5, (float) $r->json('totals.setup'));
        $this->assertSame(26.35, (float) collect($r->json('styles'))->firstWhere('product_id', self::MENS)['unit']); // 8.00 + 6.90 + 1.30 + 10.15
        $this->assertSame('Prices subject to the CrowdMerch team reviewing your artwork.', $r->json('artwork_review_note'));
    }

    public function test_gst_is_charged_in_australia_and_exports_are_gst_free_with_the_duties_note(): void
    {
        $au = $this->price($this->cart())->assertOk();
        $this->assertSame(28.51, (float) $au->json('totals.freight'));   // TNT Road VIC 11.50 + 0.36 × 47.25 kg
        $this->assertSame(1738.51, (float) $au->json('totals.subtotal'));
        $this->assertSame(173.85, (float) $au->json('totals.gst'));
        $this->assertSame(1912.36, (float) $au->json('totals.total'));
        $this->assertTrue($au->json('totals.inc_gst'));
        $this->assertFalse($au->json('export'));
        $this->assertNull($au->json('duty_note'));

        $nz = $this->price($this->cart(['delivery' => ['country' => 'NZ', 'postcode' => '1010'], 'freight_service' => 'fedex_ip', 'need_by' => '2027-01-29']))->assertOk();
        $this->assertTrue($nz->json('export'));
        $this->assertSame(0.0, (float) $nz->json('totals.gst'));
        $this->assertSame(0, $nz->json('totals.gst_rate'));
        $this->assertFalse($nz->json('totals.inc_gst'));
        $this->assertSame((float) $nz->json('totals.subtotal'), (float) $nz->json('totals.total'));
        $this->assertStringContainsString('pay any local taxes and duties directly to the carrier on arrival', $nz->json('duty_note'));
        $this->assertContains('export', array_column($nz->json('alerts'), 'code'));
        $this->assertSame(['fedex_ip', 'fedex_ie'], array_column($nz->json('freight.options'), 'id'));
    }

    public function test_freight_charges_the_greater_of_cubic_and_dead_weight(): void
    {
        $driver = new EstimateFreightDriver;
        $packs = new PackData;

        // 100 tees: 2 cartons 60 × 45 × 35 cm, 20.6 kg dead, 0.189 m³ = 47.25 kg cubic.
        $tees = Consignment::from([['pack' => $packs->defaults('t-shirts'), 'units' => 100]]);
        $au = $driver->quote($tees, 'AU', '3000');
        $this->assertSame([2, 20.6, 0.189, 47.25], [$au['cartons'], $au['kg'], $au['m3'], $au['chargeable_kg']]);
        $this->assertStringContainsString('cubic', $au['basis']);
        $this->assertSame(28.51, collect($au['options'])->firstWhere('id', 'tnt_road')['price']);
        $intl = $driver->quote($tees, 'US', '10001');
        $this->assertSame(37.8, $intl['chargeable_kg']); // 189,000 cm³ ÷ 5000
        $this->assertStringContainsString('volumetric', $intl['basis']);

        // Heavy, compact goods from Promodata's packing details: dead weight wins.
        $pack = $packs->fromDetails([['name' => 'Carton Quantity', 'detail' => '10 per carton'], ['name' => 'Carton Dimensions', 'detail' => '30 x 30 x 30cm'], ['name' => 'Item Weight', 'detail' => '2kg']]);
        $this->assertSame(['units_per_carton' => 10, 'carton_cm' => [30.0, 30.0, 30.0], 'unit_kg' => 2.0], $pack);
        $heavy = $driver->quote(Consignment::from([['pack' => $pack + ['tare_kg' => 0.8], 'units' => 10]]), 'AU', '3000');
        $this->assertSame([20.8, 'dead weight'], [$heavy['chargeable_kg'], $heavy['basis']]);

        ProductDetail::create(['product_id' => self::MENS, 'details' => [['name' => 'Carton Quantity', 'detail' => '10'], ['name' => 'Item Weight', 'detail' => '2kg']]]);
        $this->assertSame('promodata', $packs->for(Product::find(self::MENS))['source']);

        $this->assertFalse($driver->quote($tees, 'AU', '99')['ok']);
        $this->assertFalse($driver->quote($tees, 'FR', '75001')['ok']);
    }

    public function test_business_days_skip_weekends_and_the_vic_melbourne_cup(): void
    {
        $days = app(BusinessDays::class);
        $this->assertFalse($days->isBusinessDay(CarbonImmutable::parse('2026-11-03'), 'VIC')); // Melbourne Cup
        $this->assertTrue($days->isBusinessDay(CarbonImmutable::parse('2026-11-03'), 'NSW'));
        $this->assertSame('2026-11-04', $days->add(CarbonImmutable::parse('2026-11-02'), 1, 'VIC')->toDateString());
        $this->assertSame('2026-12-29', $days->add(CarbonImmutable::parse('2026-12-24'), 1, 'VIC')->toDateString()); // Christmas, weekend, Boxing Day (additional)

        $e = app(DeliveryEstimator::class)->estimate('VIC', 'AS Colour', 2, 'dtf', ['id' => 'tnt_road', 'name' => 'TNT Road Express', 'days' => [1, 2]], CarbonImmutable::parse('2026-10-30'));
        $this->assertSame(['2026-11-02', '2026-11-05', '2026-11-12', '2026-11-16'], array_column($e['stages'], 'ends'));
        $this->assertSame([1, 2, 5, 2], array_column($e['stages'], 'days'));
        $this->assertSame('Stock from AS Colour', $e['stages'][1]['name']);
        $this->assertSame('DTF decoration', $e['stages'][2]['name']);
        $this->assertSame(10, $e['business_days']);
        $this->assertSame('2026-11-16', $e['delivery_date']);
        $this->assertSame([['date' => '2026-11-03', 'name' => 'Melbourne Cup']], $e['holidays_skipped']);

        // The slowest method sets the decoration time.
        $r = $this->price($this->cart(['positions' => [
            ['name' => 'Front', 'decID' => self::MENS.'-dtf', 'has_logo' => true],
            ['name' => 'Back', 'decID' => self::MENS.'-oversizedtf', 'has_logo' => true],
        ]]))->assertOk();
        $this->assertSame(['dtf_oversize', 6], [$r->json('delivery_estimate.stages.2.method'), $r->json('delivery_estimate.stages.2.days')]);
    }

    public function test_the_deadline_check_suggests_a_faster_service_and_flags_little_time_to_spare(): void
    {
        $met = $this->price($this->cart())->assertOk();
        $this->assertSame(['met' => true, 'spare' => 14], ['met' => $met->json('deadline.met'), 'spare' => $met->json('deadline.spare_business_days')]);

        // Road arrives Mon 16 Nov; Overnight (1 day) arrives Fri 13 Nov.
        $r = $this->price($this->cart(['need_by' => '2026-11-13']))->assertOk();
        $r->assertJson(['can_order' => false, 'deadline' => ['met' => false, 'faster_service' => ['id' => 'tnt_overnight', 'name' => 'TNT Overnight Express', 'delivery_date' => '2026-11-13']]]);
        $this->assertSame('deadline', $r->json('issues.0.code'));
        $this->assertStringContainsString('Choose TNT Overnight Express', $r->json('issues.0.message'));
        $this->assertTrue($this->price($this->cart(['need_by' => '2026-11-13', 'freight_service' => 'tnt_overnight']))->json('can_order'));

        // Only one business day to spare: allowed, with a pre-payment alert.
        $risk = $this->price($this->cart(['need_by' => '2026-11-17']))->assertOk();
        $this->assertTrue($risk->json('can_order'));
        $this->assertSame(1, $risk->json('deadline.spare_business_days'));
        $this->assertSame('deadline_at_risk', $risk->json('alerts.0.code'));

        // Nothing meets it.
        $late = $this->price($this->cart(['need_by' => '2026-11-10']))->assertOk();
        $this->assertSame('late', $late->json('issues.0.code'));
        $this->assertNull($late->json('deadline.faster_service'));
        $this->assertSame('2026-11-13', $late->json('deadline.earliest.delivery_date'));
    }

    public function test_each_direct_order_rule_is_rejected_with_its_issue(): void
    {
        $codes = fn ($r) => array_column($r->assertOk()->json('issues'), 'code');

        // Not enough stock (XL has 20).
        $lines = $this->cart()['lines'];
        $lines[] = ['product_id' => self::MENS, 'colour' => 'Black', 'size' => 'XL', 'qty' => 25];
        $r = $this->price($this->cart(['lines' => $lines]));
        $this->assertSame(['stock'], $codes($r));
        $this->assertSame(20, $r->json('issues.0.available'));

        // A position that needs artwork review (screen print), and one without the client's logo.
        $this->assertSame(['review'], $codes($this->price($this->cart(['positions' => [['name' => 'Front', 'decID' => self::MENS.'-screen1', 'colours' => 1, 'has_logo' => true]]]))));
        $this->assertSame(['review'], $codes($this->price($this->cart(['positions' => [['name' => 'Front', 'decID' => self::MENS.'-embroidery', 'has_logo' => true]]]))));
        $this->assertSame(['logo'], $codes($this->price($this->cart(['positions' => [['name' => 'Front', 'decID' => self::MENS.'-dtf', 'has_logo' => false]]]))));

        // Too late for the need-by date, and no need-by date.
        $this->assertSame(['late'], $codes($this->price($this->cart(['need_by' => '2026-11-06']))));
        $this->assertSame(['need_by'], $codes($this->price($this->cart(['need_by' => null]))));

        // An unrelated product (another supplier) in the same order.
        $lines = $this->cart()['lines'];
        $lines[] = ['product_id' => self::CAP, 'colour' => 'Navy', 'size' => 'OSFA', 'qty' => 10];
        $this->assertSame(['unrelated'], $codes($this->price($this->cart(['lines' => $lines]))));

        // A decoration that belongs to another product.
        $this->assertSame(['decoration'], $codes($this->price($this->cart(['positions' => [['name' => 'Front', 'decID' => self::CAP.'-dtf', 'has_logo' => true]]]))));
    }

    public function test_stock_is_checked_live_on_every_request(): void
    {
        $this->price($this->cart())->assertOk();
        $this->price($this->cart())->assertOk();
        Http::assertSentCount(4); // two products, twice: never from the 5-minute stock cache

        // When the supplier can't be reached, the order can't go ahead.
        Http::swap(new Factory);
        Http::fake(['api.promodata.test/*' => Http::response(['message' => 'down'], 500)]);
        $this->assertContains('stock_unavailable', array_column($this->price($this->cart())->json('issues'), 'code'));
    }

    public function test_an_order_is_created_from_the_server_prices_not_the_clients(): void
    {
        Storage::fake('artwork');
        Storage::fake('local');
        Mail::fake();

        $form = $this->orderForm($this->cart(), [
            'total' => '0.01', 'price' => '0.01',
            'lines' => [['unit' => '0.01', 'price' => '0.01']],
            'positions' => [['unit' => '0.01', 'setup' => '0']],
        ]);
        $r = $this->placeOrder($form)->assertCreated();

        $r->assertJson(['success' => true, 'order_number' => 'CM-10001', 'email' => 'client@example.com', 'total' => 1912.36, 'inc_gst' => true, 'invoice' => 'pending_review']);
        $this->assertNotEmpty($r->json('account_token'));
        $order = WebsiteOrder::with(['lines', 'positions'])->first();
        $this->assertSame(['1710.00', '0.00', '28.51', '173.85', '1912.36'], [$order->goods, $order->setup, $order->freight, $order->gst, $order->total]);
        $this->assertSame('15.75', $order->lines->first()->unit_ex_gst);
        $this->assertSame(['5001-BLK-M', 'Black', 'M', 30], [$order->lines[0]->supplier_sku, $order->lines[0]->colour, $order->lines[0]->size, $order->lines[0]->qty]);
        $this->assertSame(['Front centre', 'dtf', 280], [$order->positions[0]->position, $order->positions[0]->method, $order->positions[0]->width_mm]);
        $this->assertSame(['2026-11-16', '2026-12-04', 'Summer Fun Run', 'tnt_road', 'TNT'], [$order->eta->toDateString(), $order->need_by->toDateString(), $order->event_name, $order->freight_service, $order->carrier]);
        $this->assertSame(2, $order->freight_breakdown['cartons']);
        $this->assertNotNull($order->proof_acknowledged_at);
        Storage::disk('local')->assertExists($order->invoice_path);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($order->invoice_path));

        // The next order gets the next number.
        $this->placeOrder($this->orderForm($this->cart()))->assertCreated()->assertJson(['order_number' => 'CM-10002']);
    }

    public function test_an_order_that_fails_a_rule_is_refused_and_nothing_is_saved(): void
    {
        Storage::fake('artwork');
        $r = $this->placeOrder($this->orderForm($this->cart(['positions' => [['name' => 'Front', 'decID' => self::MENS.'-screen2', 'colours' => 2]]])));
        $r->assertStatus(422)->assertJson(['success' => false, 'can_order' => false]);
        $this->assertSame('review', $r->json('issues.0.code'));
        $this->assertSame(0, WebsiteOrder::count());
        $this->assertSame([], Storage::disk('artwork')->allFiles());

        // Validation: the proof acknowledgement, a logo per position, an address.
        $form = $this->orderForm($this->cart(), ['proof_ack' => '0']);
        unset($form['positions'][0]['logo'], $form['address']['street']);
        $this->placeOrder($form)->assertStatus(422)->assertJsonValidationErrors(['proof_ack', 'positions.0.logo', 'address.street']);
    }

    public function test_logos_are_stored_privately_and_never_given_a_public_url(): void
    {
        Storage::fake('artwork');
        Storage::fake('local');
        Mail::fake();

        $r = $this->placeOrder($this->orderForm($this->cart()))->assertCreated();
        $position = WebsiteOrder::first()->positions->first();

        Storage::disk('artwork')->assertExists($position->logo_path);
        $this->assertStringStartsWith('orders/CM-10001/1-', $position->logo_path);
        $this->assertSame('logo.png', $position->logo_name);
        $this->assertArrayNotHasKey('logo_path', $position->toArray());
        $this->assertStringNotContainsString('orders/', $r->getContent());
        $this->assertFileDoesNotExist(public_path('storage/'.$position->logo_path));

        // The artwork disk itself is private and outside the web root.
        $disk = config('filesystems.disks.artwork');
        $this->assertSame('private', $disk['visibility']);
        $this->assertFalse($disk['serve']);
        $this->assertArrayNotHasKey('url', $disk);
        $this->assertStringStartsWith(storage_path('app/private'), $disk['root']);

        // Staff download it only when signed in.
        $order = WebsiteOrder::first();
        $this->flushHeaders()->get(route('website-orders.logo', [$order, $position]))->assertRedirect('/login');
    }

    public function test_with_auto_send_off_the_invoice_waits_as_a_draft_and_nothing_is_sent(): void
    {
        Storage::fake('artwork');
        Storage::fake('local');
        Mail::fake();
        Queue::fake();
        config(['website.orders.auto_send_invoice' => false]);

        $this->placeOrder($this->orderForm($this->cart()))->assertCreated()->assertJson(['invoice' => 'pending_review']);

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Queue::assertNothingPushed();
        $email = PendingEmail::sole();
        $this->assertSame(['invoice', 'draft', 'client@example.com'], [$email->kind, $email->status, $email->recipient]);
        $this->assertSame(0, WebsiteOrder::first()->stage);
    }

    public function test_with_auto_send_on_a_queued_job_sends_the_invoice(): void
    {
        Storage::fake('artwork');
        Storage::fake('local');
        Mail::fake();
        config(['website.orders.auto_send_invoice' => true]); // the test queue runs jobs straight away

        $this->placeOrder($this->orderForm($this->cart()))->assertCreated()->assertJson(['invoice' => 'sending']);

        Mail::assertSent(WebsiteInvoiceMail::class, fn ($m) => $m->hasTo('client@example.com'));
        $this->assertSame('sent', PendingEmail::sole()->status);
        $order = WebsiteOrder::first();
        $this->assertSame(WebsiteOrder::STAGE_INVOICE_SENT, $order->stage);
        $this->assertNotNull($order->invoice_sent_at);
    }

    public function test_the_api_checks_the_origin_and_answers_preflight(): void
    {
        $this->withHeaders(['Origin' => 'https://evil.example'])->postJson('/crowdmerch/checkout/price', $this->cart())->assertForbidden();
        $this->withHeaders(['Origin' => self::ORIGIN])->options('/crowdmerch/checkout/order')
            ->assertNoContent()->assertHeader('Access-Control-Allow-Origin', self::ORIGIN);
        $this->withHeaders(['Origin' => self::ORIGIN])->getJson('/crowdmerch/address?q=12 Smith St')->assertOk()->assertExactJson(['success' => true, 'results' => []]);
        $this->price($this->cart(['product_id' => 999]))->assertNotFound();
    }
}
