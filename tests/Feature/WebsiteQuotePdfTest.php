<?php

namespace Tests\Feature;

use App\Jobs\SendInstantQuote;
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

    private function send(array $extra = []): TestResponse
    {
        $c = $this->cart();

        return $this->withHeaders(['Origin' => self::ORIGIN, 'Accept' => 'application/json'])->post('/crowdmerch/homepage/quote-pdf', $extra + [
            'name' => 'Alex Ng', 'email' => 'alex@example.com', 'phone' => '0400 111 222',
            'cart' => json_encode(['product_id' => $c['product_id'], 'lines' => $c['lines'], 'positions' => [['name' => 'Front centre', 'decID' => self::MENS.'-dtf', 'width_mm' => 280]]]),
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

    public function test_bad_requests_are_refused(): void
    {
        $this->send(['email' => 'nope'])->assertStatus(422);
        $this->withHeaders(['Origin' => 'https://evil.example', 'Accept' => 'application/json'])->post('/crowdmerch/homepage/quote-pdf', [])->assertStatus(403);
    }
}
