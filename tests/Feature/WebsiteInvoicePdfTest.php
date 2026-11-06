<?php

namespace Tests\Feature;

use App\Models\WebsiteOrder;
use App\Pdf\QuotePdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CheckoutFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

/** The website order invoice: everything from the checkout on one page (Scott, 7 Nov 2026). */
class WebsiteInvoicePdfTest extends TestCase
{
    use CheckoutFixtures, QuoteFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-30 10:00', 'Australia/Melbourne'));
        $this->setUpCheckout();
        config(['search.turnstile.protect_website_api' => false, 'website.orders.payment_details' => "Bank: Example Bank\nBSB 000-000 · Account 12345678\nReference: your invoice number"]);
        Storage::fake('artwork');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_invoice_fits_one_page_with_the_checkout_details(): void
    {
        $this->placeOrder($this->orderForm($this->cart()))->assertSuccessful();
        $order = WebsiteOrder::query()->latest('id')->firstOrFail();

        $html = app(QuotePdf::class)->websiteInvoice($order);
        $this->assertSame(1, preg_match_all('#/Type\s*/Page[^s]#', $html), 'one page');
        $view = view('pdf.website-invoice', ['order' => $order->load(['lines', 'positions']), 'logos' => [], 'payment' => config('website.orders.payment_details'), 'image' => null,
            'styles' => $order->lines->groupBy('product_id'), 'logo' => null, 'signature' => null, 'brand' => config('quotes.pdf'), 'fontDir' => resource_path('fonts')])->render();
        foreach (['Summer Fun Run', 'BSB 000-000', 'Branding', 'Delivery'] as $text) {
            $this->assertStringContainsString($text, $view);
        }
        if (getenv('SAVE_PDF')) {
            file_put_contents(getenv('SAVE_PDF').'/invoice.pdf', $html);
        }
    }
}
