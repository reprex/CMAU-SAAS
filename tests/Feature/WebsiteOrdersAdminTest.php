<?php

namespace Tests\Feature;

use App\Checkout\OutboundEmails;
use App\Mail\WebsiteInvoiceMail;
use App\Models\PendingEmail;
use App\Models\Product;
use App\Models\PublicHoliday;
use App\Models\RelatedStyle;
use App\Models\Supplier;
use App\Models\WebsiteOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CheckoutFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class WebsiteOrdersAdminTest extends TestCase
{
    use CheckoutFixtures, QuoteFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckout();
    }

    public function test_staff_see_orders_with_stage_and_need_by_and_send_the_pending_invoice(): void
    {
        Storage::fake('local');
        Mail::fake();
        $order = $this->makeOrder('pat@example.com', 0, ['need_by' => '2026-12-04']);
        $email = app(OutboundEmails::class)->prepareInvoice($order);
        $this->staff('sales');

        $this->get(route('website-orders.index'))->assertOk()->assertSee($order->number)->assertSee('Order received')->assertSee('Fri 4 Dec 2026')->assertSee('invoice to send');
        $order->changeRequests()->create(['message' => 'Please add 10 more size L', 'stage_at_request' => 0]);
        $this->get(route('website-orders.show', $order))->assertOk()->assertSee('Review & send invoice')->assertSee(route('emails.review', $email))->assertSee('Please add 10 more size L')->assertSee('Mark handled');

        $this->post(route('website-orders.send', [$order, $email]))->assertRedirect(route('website-orders.show', $order));
        Mail::assertSent(WebsiteInvoiceMail::class, fn ($m) => $m->hasTo('pat@example.com'));
        $mail = (new WebsiteInvoiceMail($order))->render();
        foreach (['Invoice PDF attached.', 'Please pay in full to the bank account listed on the invoice.</p>', '<p>On receipt of payment, we will prepare a digital proof', 'need to make changes prior to payment, please contact us via return email.', 'Scott Wilson'] as $text) {
            $this->assertStringContainsString($text, $mail);
        }
        $this->assertSame('sent', $email->fresh()->status);
        $this->assertSame(WebsiteOrder::STAGE_INVOICE_SENT, $order->fresh()->stage);
        $this->get(route('website-orders.invoice', $order))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_staff_set_the_stage_carrier_and_consignment_and_download_logos(): void
    {
        Storage::fake('artwork');
        $order = $this->makeOrder('pat@example.com', 4);
        Storage::disk('artwork')->putFileAs('orders/'.$order->number, UploadedFile::fake()->image('a.png'), '1-x.png');
        $position = $order->positions()->create(['position' => 'Front centre', 'method' => 'dtf', 'unit_ex_gst' => 7.75, 'setup_ex_gst' => 0, 'logo_path' => 'orders/'.$order->number.'/1-x.png', 'logo_name' => 'mine.png']);
        $this->staff('sales');

        $this->put(route('website-orders.update', $order), ['stage' => 5, 'carrier' => 'TNT', 'consignment' => 'GEA412387690'])->assertRedirect();
        $order->refresh();
        $this->assertSame([5, 'TNT', 'GEA412387690'], [$order->stage, $order->carrier, $order->consignment]);
        $this->get(route('website-orders.show', $order))->assertSee('Track GEA412387690');

        $this->get(route('website-orders.logo', [$order, $position]))->assertOk()->assertDownload($order->number.' Front centre.png');
    }

    public function test_read_only_staff_cannot_change_orders(): void
    {
        $order = $this->makeOrder('pat@example.com');
        $this->staff('read-only');
        $this->get(route('website-orders.show', $order))->assertOk();
        $this->put(route('website-orders.update', $order), ['stage' => 6])->assertForbidden();
    }

    public function test_related_styles_are_vetted_on_the_range_and_must_share_the_supplier(): void
    {
        $this->staff('sales');
        $this->post(route('range.related.store', self::MENS), ['related_product_id' => self::CAP])->assertSessionHasErrors('related_product_id');
        $this->assertSame(1, RelatedStyle::count());
        Product::whereKey(self::MENS)->update(['hydrated_at' => now()]);
        $this->get(route('range.show', self::MENS))->assertOk()->assertSee('Related styles')->assertSee('Womens fit');
    }

    public function test_admins_set_a_suppliers_state_stock_lead_time_and_payment_terms(): void
    {
        $this->staff('admin');
        $this->put(route('admin.suppliers.update', 7), ['freight_basis' => 'per_delivery', 'state' => 'NSW', 'stock_lead_days' => 4, 'payment_terms' => 'full'])->assertRedirect();
        $s = Supplier::find(7);
        $this->assertSame(['NSW', 4, 'full', 4], [$s->state, $s->stock_lead_days, $s->payment_terms, $s->stockLeadDays()]);
        $this->assertSame(2, Supplier::find(53)->stockLeadDays()); // default
    }

    public function test_admins_set_overseas_transit_and_closures(): void
    {
        $this->staff('admin');
        $this->put(route('admin.suppliers.update', 7), ['freight_basis' => 'per_delivery', 'state' => 'NZ', 'transit_days' => 4, 'ships_from' => 'NZ',
            'closures' => "2026-12-18 to 2027-01-11 Christmas shutdown\n"])->assertRedirect();
        $s = Supplier::find(7);
        $this->assertSame(['NZ', 4, 'NZ'], [$s->state, $s->transit_days, $s->ships_from]);
        $this->assertSame('Christmas shutdown', \DB::table('supplier_closures')->where('supplier_id', 7)->value('note'));
        $this->get(route('admin.suppliers.edit', 7))->assertSee('2026-12-18 to 2027-01-11 Christmas shutdown');

        $this->put(route('admin.suppliers.update', 7), ['freight_basis' => 'per_delivery', 'closures' => '18 Dec to 11 Jan'])->assertSessionHasErrors('closures');
        $this->assertSame(1, \DB::table('supplier_closures')->where('supplier_id', 7)->count());
        $this->assertTrue(PublicHoliday::where('state', 'NZ')->whereDate('date', '2027-06-25')->exists()); // Matariki
    }

    public function test_holidays_are_seeded_and_can_be_imported_from_csv(): void
    {
        $this->assertSame('Melbourne Cup', PublicHoliday::where('state', 'VIC')->whereDate('date', '2026-11-03')->value('name'));
        $this->assertTrue(PublicHoliday::where('state', 'VIC')->whereDate('date', '2026-12-28')->exists());
        $this->assertFalse(PublicHoliday::where('state', 'VIC')->whereDate('date', '2027-04-26')->exists()); // no VIC Anzac Day substitute in 2027

        $dir = sys_get_temp_dir().'/holidays-'.uniqid();
        mkdir($dir);
        file_put_contents("{$dir}/h.csv", "date,state,name\n2027-09-24,VIC,Friday before the AFL Grand Final\n2026-11-03,VIC,Melbourne Cup Day\nbad,VIC,x\n");
        $this->artisan('holidays:import', ['file' => "{$dir}/h.csv"])->expectsOutputToContain('1 added, 1 updated, 1 skipped')->assertSuccessful();
        unlink("{$dir}/h.csv");
        rmdir($dir);

        $this->assertSame('Friday before the AFL Grand Final', PublicHoliday::where('state', 'VIC')->whereDate('date', '2027-09-24')->value('name'));
        $this->assertSame('Melbourne Cup Day', PublicHoliday::where('state', 'VIC')->whereDate('date', '2026-11-03')->value('name'));
        $this->assertSame(1, PublicHoliday::where('state', 'VIC')->whereDate('date', '2026-11-03')->count());
        $this->assertSame(0, PendingEmail::count());
    }
}
