<?php

namespace Tests\Feature;

use App\Mail\PurchaseOrderMail;
use App\Models\PriceOption;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\WebsiteOrder;
use App\Pdf\QuotePdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CheckoutFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

/** Supplier purchase orders from website orders (Scott, 7 Nov 2026; docs/purchase-orders.md). */
class PurchaseOrderTest extends TestCase
{
    use CheckoutFixtures, QuoteFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-30 10:00', 'Australia/Melbourne'));
        $this->setUpCheckout();
        config(['search.turnstile.protect_website_api' => false]);
        Storage::fake('artwork');
        Storage::fake(config('website.orders.artwork_disk'));
        // Supplier costs: tee 6.00 at 100+, DTF 5.00 each.
        PriceOption::query()->where('option_id', self::MENS.'-undecorated')->update(['cost_string' => '25:7.00,100:6.00', 'setup_cost' => 0]);
        PriceOption::query()->where('option_id', self::WOMENS.'-undecorated')->update(['cost_string' => '25:6.80,100:5.80']);
        PriceOption::query()->where('option_id', self::MENS.'-dtf')->update(['cost_string' => '1:5.00', 'setup_cost' => 0]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function order(): WebsiteOrder
    {
        $this->placeOrder($this->orderForm($this->cart()))->assertSuccessful();

        return WebsiteOrder::query()->latest('id')->firstOrFail();
    }

    public function test_staff_create_a_po_at_cost_blind_shipped_with_deadline_event_and_artwork_link(): void
    {
        $order = $this->order();
        $this->staff('sales');

        $this->post(route('purchase-orders.store', $order))->assertRedirect();
        $po = PurchaseOrder::query()->firstOrFail();

        $this->assertSame('PO-'.$order->number, $po->number);
        $this->assertEquals(6.00, $po->lines[0]['unit_cost'], 'tee cost at 110 units');
        $this->assertEquals(60 * 6.00 + 50 * 5.80, (float) $po->goods_cost);
        $this->assertEquals(110 * 5.00, (float) $po->branding_cost);
        $this->assertNull($po->freight_cost);
        $this->assertSame('12 Smith Street', $po->deliver_to['street']);
        $this->assertSame('2026-12-04', $po->need_by->toDateString());
        $this->assertNotNull($po->despatch_by);
        $this->assertStringContainsString('Summer Fun Run', $po->event);

        $html = view('pdf.purchase-order', ['po' => $po->load('supplier'), 'logo' => null, 'signature' => null, 'brand' => config('quotes.pdf'), 'fontDir' => resource_path('fonts')])->render();
        foreach (['Blind ship to', 'Show sender as', 'supplier documentation or identification', 'any prices have changed', 'Despatch by', 'Client needs it by', 'Summer Fun Run', 'to be advised', $po->artworkUrl(), '5001-BLK-M'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertNotEmpty(app(QuotePdf::class)->purchaseOrder($po));
        $this->get(route('purchase-orders.show', $po))->assertOk()->assertSee('Send to supplier')->assertSee('Add the orders email');
        $this->get(route('website-orders.show', $order))->assertOk()->assertSee($po->number);
    }

    public function test_freight_is_added_later_and_the_po_is_emailed_to_the_supplier(): void
    {
        Mail::fake();
        $order = $this->order();
        $this->staff('sales');
        $this->post(route('purchase-orders.store', $order));
        $po = PurchaseOrder::query()->firstOrFail();

        $this->put(route('purchase-orders.update', $po), ['freight_cost' => '24.50', 'notes' => 'Fold and bag each tee'])->assertRedirect();
        $this->assertEquals((float) $po->goods_cost + (float) $po->branding_cost + 24.50, (float) $po->fresh()->total_cost);

        $this->post(route('purchase-orders.send', $po))->assertRedirect();
        Mail::assertNothingSent();

        Supplier::query()->whereKey(7)->update(['orders_email' => 'orders@ascolour.example', 'orders_contact' => 'Sam Lee']);
        $this->post(route('purchase-orders.send', $po))->assertRedirect();
        Mail::assertSent(PurchaseOrderMail::class, fn ($m) => $m->hasTo('orders@ascolour.example') && str_contains($m->envelope()->subject, 'BLIND SHIP'));
        $this->assertSame('sent', $po->fresh()->status);
    }

    public function test_the_supplier_downloads_the_artwork_with_the_token_only(): void
    {
        $order = $this->order();
        $this->staff('sales');
        $this->post(route('purchase-orders.store', $order));
        $po = PurchaseOrder::query()->firstOrFail();
        auth()->logout();

        $this->get($po->artworkUrl())->assertOk()->assertSee('Front centre')->assertSee('blind ship');
        $this->get(route('po-artwork.file', [$po->artwork_token, 0]))->assertOk()->assertDownload();
        $this->get(route('po-artwork.index', str_repeat('a', 40)))->assertNotFound();
        Carbon::setTestNow(now()->addDays(121));
        $this->get($po->artworkUrl())->assertNotFound();
    }

    public function test_supplier_po_details_are_edited_and_listed(): void
    {
        $this->staff('admin');
        $s = Supplier::query()->findOrFail(7);
        $this->get(route('admin.suppliers.edit', $s))->assertOk()->assertSee('Orders email');
        $this->artisan('suppliers:po-check')->expectsOutputToContain('need an orders email')->assertSuccessful();
    }
}
