<?php

namespace Tests\Feature;

use App\Models\Quote;
use App\Models\WebsiteOrder;
use App\Staff\StaffPasses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CheckoutFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class WebsiteStaffAssistTest extends TestCase
{
    use CheckoutFixtures, QuoteFixtures, RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-30 10:00', 'Australia/Melbourne'));
        $this->setUpCheckout();
        config(['app.admin_allowed_ips' => [], 'search.turnstile.protect_website_api' => false]);
        Storage::fake('artwork');
        $this->token = app(StaffPasses::class)->issue($this->staff('sales'), self::ORIGIN)['token'];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function api(): static
    {
        return $this->withHeaders(['Origin' => self::ORIGIN, 'Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json']);
    }

    private function staffCart(): array
    {
        $c = $this->cart();

        return ['product_id' => $c['product_id'], 'lines' => $c['lines'], 'positions' => [
            ['name' => 'Front centre', 'decID' => self::MENS.'-dtf', 'width_mm' => 280],
            ['name' => 'Back', 'decID' => self::MENS.'-screen1', 'colours' => 1],
        ]];
    }

    public function test_new_client_quote_from_the_cart(): void
    {
        $res = $this->api()->post('/crowdmerch/staff/quotes', [
            'cart' => json_encode($this->staffCart()),
            'client' => json_encode(['name' => 'Alex Ng', 'organisation' => 'Acme', 'email' => 'Alex@Example.com', 'phone' => '0400 111 222',
                'address' => ['street' => '1 High St', 'suburb' => 'Kew', 'state' => 'VIC', 'postcode' => '3101', 'country' => 'AU']]),
            'note' => 'Phone order',
            'logos' => [0 => UploadedFile::fake()->image('front.png', 300, 200)],
        ]);
        $res->assertOk()->assertJson(['success' => true, 'added' => 3, 'existing' => false]);
        $q = Quote::with('items.skus')->where('number', $res->json('number'))->first();
        $this->assertSame('alex@example.com', $q->client_email);
        $this->assertSame('PHONE', $q->status);
        $this->assertSame('Kew', $q->delivery_city);
        // Mens tee (60 units, sizes as SKUs), Womens tee (50), and the Back branding line for all 110.
        $this->assertSame([60, 50, 110], $q->items->pluck('quantity')->all());
        $this->assertSame(['M', 'L'], $q->items[0]->skus->pluck('size')->all());
        $this->assertStringContainsString('Front centre', $q->items[0]->decoration_name);
        $this->assertSame(0.0, (float) $q->items[2]->product_unit_price);
        $this->assertStringContainsString('Back', $q->items[2]->name);
        $this->assertGreaterThan(0, (float) $q->total);
        $this->assertCount(1, $q->artwork);
        Storage::disk('artwork')->assertExists($q->artwork[0]['path']);
        $this->assertStringContainsString('From the website by', $q->internal_notes);

        $this->get(route('quotes.show', $q))->assertOk()->assertSee('front.png');
        $this->get(route('quotes.artwork', [$q, 0]))->assertOk();

        // Found again by name, with the open quote; then a second product added to it.
        $this->api()->getJson('/crowdmerch/staff/clients?q=acme')->assertOk()->assertJsonPath('clients.0.email', 'alex@example.com')->assertJsonPath('clients.0.quotes', 1);
        $this->api()->getJson('/crowdmerch/staff/clients/quotes?email=alex@example.com')->assertOk()->assertJsonPath('quotes.0.number', $q->number);
        $more = ['product_id' => self::CAP, 'lines' => [['product_id' => self::CAP, 'colour' => 'Navy', 'size' => 'OSFA', 'qty' => 25]], 'positions' => []];
        $this->api()->post('/crowdmerch/staff/quotes', ['cart' => json_encode($more), 'quote' => $q->number])->assertOk()->assertJson(['existing' => true, 'added' => 1]);
        $this->assertCount(4, $q->fresh()->items);
    }

    public function test_quotes_need_a_client_and_a_pass(): void
    {
        $this->api()->post('/crowdmerch/staff/quotes', ['cart' => json_encode($this->staffCart())])->assertStatus(422)->assertJsonValidationErrors(['client']);
        $this->flushHeaders()->withHeaders(['Origin' => self::ORIGIN, 'Accept' => 'application/json'])->post('/crowdmerch/staff/quotes', ['cart' => json_encode($this->staffCart())])->assertStatus(401);
        $this->flushHeaders();
        $this->api()->post('/crowdmerch/staff/quotes', ['cart' => json_encode($this->staffCart()), 'quote' => 'MR0'])->assertNotFound();
    }

    public function test_order_placed_by_staff_is_marked(): void
    {
        $this->withHeaders(['Origin' => self::ORIGIN, 'Accept' => 'application/json', 'Authorization' => 'Bearer '.$this->token])
            ->post('/crowdmerch/checkout/order', $this->orderForm($this->cart()))->assertSuccessful();
        $o = WebsiteOrder::first();
        $this->assertNotNull($o->placed_by);
        $this->staff('admin');
        $this->get(route('website-orders.show', $o))->assertOk()->assertSee('placed by');
    }
}
