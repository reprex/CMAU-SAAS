<?php

namespace Tests\Feature;

use App\Jobs\SendInstantQuote;
use App\Mail\InstantQuoteMail;
use App\Models\BrandingTemplate;
use App\Models\Product;
use App\Models\Quote;
use App\Pdf\QuotePdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\CheckoutFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

/** The shopper's quote and mockup PDF (docs/quote-mockup-pdf.md; Scott, 7 Nov 2026). */
class WebsiteQuotePdfTest extends TestCase
{
    use CheckoutFixtures, QuoteFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-30 10:00', 'Australia/Melbourne'));
        $this->setUpCheckout();
        config(['search.turnstile.protect_website_api' => false, 'shopify.website_api.enabled' => true,
            'shopify.website_api.allowed_origins' => [self::ORIGIN], 'shopify.website_api.email_instant_quote' => true]);
        Storage::fake('artwork');
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function send(array $extra = [], ?array $delivery = null): TestResponse
    {
        $c = $this->cart();
        $cart = ['product_id' => $c['product_id'], 'lines' => $c['lines'], 'positions' => [['name' => 'Front centre', 'decID' => self::MENS.'-dtf', 'width_mm' => 280]]];
        if ($delivery !== null) {
            $cart['delivery'] = $delivery;
        }

        return $this->withHeaders(['Origin' => self::ORIGIN, 'Accept' => 'application/json'])->post('/crowdmerch/homepage/quote-pdf', $extra + [
            'name' => 'Alex Ng', 'email' => 'alex@example.com', 'phone' => '0400 111 222',
            'cart' => json_encode($cart),
            'logos' => [0 => UploadedFile::fake()->image('front.png', 300, 200)],
            'mockups' => [UploadedFile::fake()->image('mockup-front.jpg', 600, 600)],
        ]);
    }

    public function test_quote_is_made_priced_and_emailed_with_its_mockup(): void
    {
        $res = $this->send()->assertOk()->assertJson(['success' => true, 'emailed' => true]);

        $q = Quote::query()->where('number', $res->json('quoteNumber'))->firstOrFail();
        $this->assertSame(['website', 'QUOTE', 'alex@example.com'], [$q->source, $q->status, $q->client_email]);
        $this->assertCount(1, $q->mockups);
        Storage::disk('artwork')->assertExists($q->mockups[0]['path']);
        Storage::disk('artwork')->assertExists($q->artwork[0]['path']);
        $this->assertGreaterThan(0, (float) $q->total);
        Queue::assertPushed(SendInstantQuote::class);

        $html = app(QuotePdf::class)->quoteHtml($q);
        $this->assertStringContainsString('Your mockup', $html);
        $this->assertStringContainsString('approximate only', $html);
    }

    public function test_without_a_mockup_the_artwork_stands_in_and_page_two_lists_similar_and_saved(): void
    {
        $c = $this->cart();
        $other = Product::query()->whereKeyNot($c['product_id'])->first() ?? $this->catalogueProduct(99001, 88, 'Other Tee', 'O1');
        $other->update(['tier' => Product::TIER_LIVE, 'discontinued' => false]);

        $res = $this->send(['mockups' => [], 'similar' => [$other->id], 'saved' => [$other->id, $c['product_id']]])->assertOk();

        $q = Quote::query()->where('number', $res->json('quoteNumber'))->firstOrFail();
        $this->assertSame([$other->id], $q->similar_products);
        $this->assertSame([$other->id], $q->saved_products, 'the quoted product itself is not repeated');
        $html = app(QuotePdf::class)->quoteHtml($q);
        $this->assertStringContainsString('Your artwork', $html);
        $this->assertStringContainsString('You might also consider', $html);
        $this->assertStringContainsString($other->name, $html);
        $this->assertStringContainsString('page-break-before', $html);
        $this->assertNotEmpty($pdf = app(QuotePdf::class)->quote($q));
        if (getenv('SAVE_PDF')) {
            file_put_contents(getenv('SAVE_PDF').'/quote.pdf', $pdf);
        }
    }

    public function test_the_checkout_link_rebuilds_the_cart_with_its_logos(): void
    {
        $state = ['product' => ['appaid' => self::MENS, 'title' => 'AS Colour Mens Staple Tee'], 'colour' => 'Black', 'pqty' => 110,
            'branding' => ['positions' => [['name' => 'Front centre', 'decID' => self::MENS.'-dtf', 'logoId' => 'Labc', 's' => 0.8]]], 'lines' => [], 'rel' => []];
        $res = $this->send(['cart_state' => json_encode($state)])->assertOk();
        $q = Quote::query()->where('number', $res->json('quoteNumber'))->firstOrFail();

        $url = $q->checkoutUrl();
        $this->assertSame('https://crowdmerch.com.au/pages/cart?quote='.$q->public_token, $url);
        $this->assertStringContainsString(e($url), app(QuotePdf::class)->quoteHtml($q));
        $this->assertStringContainsString('Simply reply to this email', app(QuotePdf::class)->quoteHtml($q));

        $cart = $this->withHeaders(['Origin' => self::ORIGIN, 'Accept' => 'application/json'])->get('/crowdmerch/homepage/quote-cart?quote='.$q->public_token)
            ->assertOk()->assertJsonPath('cart.branding.positions.0.logoId', 'Labc')->assertJsonPath('contact.email', 'alex@example.com');
        $this->assertSame('Front centre', $cart->json('logos.0.position'));
        $this->assertStringStartsWith('data:image/png;base64,', $cart->json('logos.0.data'));

        $this->withHeaders(['Origin' => self::ORIGIN, 'Accept' => 'application/json'])->get('/crowdmerch/homepage/quote-cart?quote='.str_repeat('a', 40))->assertNotFound();
    }

    public function test_without_a_cart_the_quote_just_asks_for_a_reply(): void
    {
        $q = Quote::query()->where('number', $this->send()->json('quoteNumber'))->firstOrFail();

        $this->assertNull($q->checkoutUrl());
        $this->assertStringNotContainsString('online checkout', app(QuotePdf::class)->quoteHtml($q));
    }

    private function quoteFrom(TestResponse $res): Quote
    {
        return Quote::query()->where('number', $res->assertOk()->json('quoteNumber'))->firstOrFail()->load('extras');
    }

    public function test_delivery_in_australia_is_priced_for_the_postcode(): void
    {
        $q = $this->quoteFrom($this->send([], ['wanted' => true, 'country' => 'AU', 'postcode' => '3066']));

        $freight = $q->extras->where('kind', 'freight');
        $this->assertCount(1, $freight, 'the carrier price only, no standard supplier freight');
        $this->assertStringContainsString('Delivery to 3066 VIC: TNT Road Express', $freight->first()->description);
        $this->assertGreaterThan(0, (float) $freight->first()->unit_price);
        $this->assertSame('carrier', $q->freight_source);
        $this->assertFalse($q->gst_free_export);
        $this->assertGreaterThan(0, (float) $q->gst);
        $this->assertMatchesRegularExpression('/\(estimate based on [\d.]+ kg, subject to confirming final weight\)/', $freight->first()->description, 'placeholder rates are an estimate');
    }

    public function test_overseas_delivery_is_gst_free_and_says_duties_are_the_clients(): void
    {
        $q = $this->quoteFrom($this->send([], ['wanted' => true, 'country' => 'NZ', 'postcode' => '1010']));

        $this->assertTrue($q->gst_free_export);
        $this->assertSame(0.0, (float) $q->gst);
        $this->assertSame((float) $q->subtotal, (float) $q->total);
        $this->assertStringContainsString('New Zealand', $q->extras->firstWhere('kind', 'freight')->description);
        $html = app(QuotePdf::class)->quoteHtml($q);
        $this->assertStringContainsString('GST (not charged on exports)', $html);
        $this->assertStringContainsString('duties and taxes are payable by you to the carrier', $html);
    }

    public function test_no_delivery_wanted(): void
    {
        $q = $this->quoteFrom($this->send([], ['wanted' => false]));

        $this->assertSame('none', $q->freight_source);
        $this->assertCount(0, $q->extras->where('kind', 'freight'));
        $this->assertStringContainsString('Delivery is not included', app(QuotePdf::class)->quoteHtml($q));
    }

    public function test_page_two_prices_each_product_at_the_quotes_quantity(): void
    {
        $q = $this->quoteFrom($this->send(['saved' => [self::CAP]]));

        $html = app(QuotePdf::class)->quoteHtml($q);
        $this->assertStringContainsString('Prices to compare', $html);
        $this->assertStringContainsString('Unbranded (no standard branding listed)', $html);
        $this->assertStringContainsString('Qty 110 × $5.00', $html);
        $this->assertStringContainsString('$550.00</b> ex GST · <b>$605.00</b> inc GST', $html);
    }

    public function test_the_branding_template_is_linked_and_the_link_downloads_it(): void
    {
        Storage::fake('templates');
        Storage::disk('templates')->put('t/5001.pdf', str_repeat('%PDF-template ', 30));
        BrandingTemplate::query()->create(['product_id' => self::MENS, 'filename' => '5001 Staple.pdf', 'file_path' => 't/5001.pdf', 'url' => 'file:5001 Staple.pdf', 'status' => 'imported']);
        $q = $this->quoteFrom($this->send());

        $html = app(QuotePdf::class)->quoteHtml($q);
        $this->assertMatchesRegularExpression('#Branding template: <a href="([^"]+)"#', $html);
        preg_match('#Branding template: <a href="([^"]+)"#', $html, $m);
        $url = html_entity_decode($m[1]);
        $this->assertStringNotContainsString('AS Colour', $m[0]);
        $this->get($url)->assertOk()->assertHeader('Content-Disposition');
        $this->get(preg_replace('/signature=[^&]+/', 'signature=nope', $url))->assertForbidden();
    }

    public function test_the_quote_email_is_a_short_note_with_the_signature(): void
    {
        $q = $this->quoteFrom($this->send());

        $html = (new InstantQuoteMail($q))->render();
        foreach (['Hi Alex,', 'Please find the quote PDF attached.', 'including detailed pricing, order instructions, and mock-ups.', 'follow the links in the quote document', 'Scott Wilson', 'font-family:Arial'] as $text) {
            $this->assertStringContainsString($text, $html);
        }

        // No embroidery: no stitch-count note; with embroidery it's there (Scott, 7 Nov 2026).
        $this->assertStringNotContainsString('5000 stitch count', $html);
        $q->items()->first()->update(['decoration_name' => 'Embroidery (to pocket size)']);
        $this->assertStringContainsString('based on a 5000 stitch count (small) embroidery', (new InstantQuoteMail($q->fresh()))->render());

        // Only what the quote has: no mock-ups, no templates.
        $plain = $this->quoteFrom($this->send(['mockups' => []]));
        $this->assertStringContainsString('including detailed pricing and order instructions.', (new InstantQuoteMail($plain))->render());

        // A branding template on the product.
        Storage::fake('templates');
        Storage::disk('templates')->put('t/5001.pdf', str_repeat('%PDF-template ', 30));
        BrandingTemplate::query()->create(['product_id' => self::MENS, 'filename' => '5001 Staple.pdf', 'file_path' => 't/5001.pdf', 'url' => 'file:5001', 'status' => 'imported']);
        $this->assertStringContainsString('including detailed pricing, order instructions, mock-ups, and branding templates.', (new InstantQuoteMail($q))->render());
    }

    public function test_an_unknown_country_is_refused(): void
    {
        $this->send([], ['wanted' => true, 'country' => 'ZZ', 'postcode' => '1'])->assertStatus(422);
    }

    public function test_one_line_per_style_and_the_next_price_breaks_kept(): void
    {
        $q = $this->quoteFrom($this->send());

        $this->assertSame(250, $q->price_breaks[0]['units']);
        $this->assertEquals(15.25, $q->price_breaks[0]['styles'][self::MENS], '7.50 tee + 7.75 DTF at 250');
        $html = app(QuotePdf::class)->quoteHtml($q);
        $this->assertStringNotContainsString('250 units', $html, 'breaks are kept with the quote but not shown (Scott, 7 Nov 2026)');
        $this->assertStringContainsString('AS Colour Mens Staple Tee, branded', $html);
    }

    public function test_bad_requests_are_refused(): void
    {
        $this->send(['email' => 'nope'])->assertStatus(422);
        $this->withHeaders(['Origin' => 'https://evil.example', 'Accept' => 'application/json'])->post('/crowdmerch/homepage/quote-pdf', [])->assertStatus(403);
    }
}
