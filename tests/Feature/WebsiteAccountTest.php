<?php

namespace Tests\Feature;

use App\Checkout\AccountTokens;
use App\Checkout\Sms\SmsSender;
use App\Mail\AccountCodeMail;
use App\Models\AccountCode;
use App\Models\PendingEmail;
use App\Models\WebsiteOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CheckoutFixtures;
use Tests\TestCase;

class WebsiteAccountTest extends TestCase
{
    use CheckoutFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckout();
    }

    private function api(string $method, string $uri, array $body = [], ?string $token = null)
    {
        $headers = ['Origin' => self::ORIGIN] + ($token ? ['Authorization' => 'Bearer '.$token] : []);

        return $this->withHeaders($headers)->json($method, $uri, $body);
    }

    /** Asks for a code and returns it from the email. */
    private function codeFor(string $email): string
    {
        $code = null;
        Mail::assertSent(AccountCodeMail::class, function (AccountCodeMail $m) use ($email, &$code) {
            if ($m->hasTo($email)) {
                $code = $m->code;
            }

            return true;
        });

        return (string) $code;
    }

    public function test_a_code_is_sent_only_for_emails_with_orders_and_the_reply_never_says_which(): void
    {
        Mail::fake();
        $this->makeOrder('pat@example.com');

        $known = $this->api('POST', '/crowdmerch/account/code', ['email' => 'Pat@Example.com', 'via' => 'email'])->assertOk();
        $unknown = $this->api('POST', '/crowdmerch/account/code', ['email' => 'nobody@example.com', 'via' => 'email'])->assertOk();
        $this->assertSame($known->json(), $unknown->json());

        Mail::assertSent(AccountCodeMail::class, 1); // a security email: sent straight away, not a draft
        $this->assertMatchesRegularExpression('/^\d{6}$/', $this->codeFor('pat@example.com'));
        $stored = AccountCode::sole();
        $this->assertSame('pat@example.com', $stored->email);
        $this->assertNotSame($this->codeFor('pat@example.com'), $stored->code_hash); // stored hashed
        $this->assertSame(64, strlen($stored->code_hash));
        $this->assertSame(0, PendingEmail::count());
    }

    public function test_the_right_code_returns_a_90_day_token_that_lists_the_orders(): void
    {
        Mail::fake();
        $this->makeOrder('pat@example.com', 1);
        $this->api('POST', '/crowdmerch/account/code', ['email' => 'pat@example.com']);

        $r = $this->api('POST', '/crowdmerch/account/verify', ['email' => 'pat@example.com', 'code' => $this->codeFor('pat@example.com')])->assertOk();
        $token = $r->json('token');
        $this->assertSame('pat@example.com', $r->json('email'));
        $this->assertEqualsWithDelta(now()->addDays(90)->timestamp, strtotime($r->json('expires_at')), 5);

        $list = $this->api('GET', '/crowdmerch/account/orders', [], $token)->assertOk();
        $this->assertCount(1, $list->json('orders'));
        $list->assertJson(['orders' => [['stage' => 1, 'stage_name' => 'Invoice sent', 'total' => 132, 'inc_gst' => true, 'total_label' => 'inc GST', 'delivery_date' => '2026-11-20', 'can_request_change' => true]]]);
        $this->assertSame(WebsiteOrder::STAGES, $list->json('stages'));

        // A code works once.
        $this->api('POST', '/crowdmerch/account/verify', ['email' => 'pat@example.com', 'code' => $this->codeFor('pat@example.com')])->assertStatus(422);
    }

    public function test_codes_expire_after_ten_minutes(): void
    {
        Mail::fake();
        $this->makeOrder('pat@example.com');
        $this->api('POST', '/crowdmerch/account/code', ['email' => 'pat@example.com']);
        $code = $this->codeFor('pat@example.com');

        $this->travel(11)->minutes();
        $this->api('POST', '/crowdmerch/account/verify', ['email' => 'pat@example.com', 'code' => $code])->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_five_wrong_attempts_lock_the_code(): void
    {
        Mail::fake();
        $this->makeOrder('pat@example.com');
        $this->api('POST', '/crowdmerch/account/code', ['email' => 'pat@example.com']);
        $code = $this->codeFor('pat@example.com');
        $wrong = str_pad((string) (((int) $code + 1) % 1000000), 6, '0', STR_PAD_LEFT);

        for ($i = 0; $i < 5; $i++) {
            $this->api('POST', '/crowdmerch/account/verify', ['email' => 'pat@example.com', 'code' => $wrong])->assertStatus(422);
        }
        $this->api('POST', '/crowdmerch/account/verify', ['email' => 'pat@example.com', 'code' => $code])->assertStatus(422);
        $this->assertSame(5, AccountCode::sole()->attempts);
    }

    public function test_codes_by_sms_go_to_the_phone_on_the_most_recent_order(): void
    {
        Mail::fake();
        $sent = [];
        $this->app->instance(SmsSender::class, new class($sent) implements SmsSender
        {
            public function __construct(private array &$sent) {}

            public function send(string $to, string $message): void
            {
                $this->sent[] = [$to, $message];
            }
        });
        $this->makeOrder('pat@example.com', 0, ['phone' => '0400 111 111']);
        $this->makeOrder('pat@example.com', 0, ['phone' => '0499 999 999']);

        $this->api('POST', '/crowdmerch/account/code', ['email' => 'pat@example.com', 'via' => 'sms'])->assertOk();

        $this->assertCount(1, $sent);
        $this->assertSame('0499 999 999', $sent[0][0]);
        $this->assertMatchesRegularExpression('/code is \d{6}\./', $sent[0][1]);
        Mail::assertNothingSent();
    }

    public function test_requests_for_codes_are_rate_limited_per_email_without_saying_so(): void
    {
        Mail::fake();
        $this->makeOrder('pat@example.com');
        for ($i = 0; $i < 5; $i++) {
            $this->api('POST', '/crowdmerch/account/code', ['email' => 'pat@example.com'])->assertOk();
        }
        Mail::assertSent(AccountCodeMail::class, 3); // config website.account.codes_per_email_per_hour
    }

    public function test_a_token_only_ever_shows_its_own_emails_orders(): void
    {
        $mine = $this->makeOrder('pat@example.com');
        $this->makeOrder('someone.else@example.com');
        $tokens = app(AccountTokens::class);

        $list = $this->api('GET', '/crowdmerch/account/orders', [], $tokens->issue('pat@example.com'))->assertOk();
        $this->assertSame([$mine->number], array_column($list->json('orders'), 'number'));

        // Someone else's order can't be changed with my token.
        $other = WebsiteOrder::where('email', 'someone.else@example.com')->first();
        $this->api('POST', '/crowdmerch/account/change', ['order_number' => $other->number, 'message' => 'Add 10 more'], $tokens->issue('pat@example.com'))->assertNotFound();

        // Forged, tampered and expired tokens, and none at all.
        $forged = base64_encode('{"e":"someone.else@example.com","x":9999999999}').'.abc';
        $good = $tokens->issue('pat@example.com');
        $tampered = rtrim(strtr(base64_encode('{"e":"someone.else@example.com","x":'.(time() + 999).'}'), '+/', '-_'), '=').'.'.explode('.', $good)[1];
        foreach ([$forged, $tampered, $tokens->issue('pat@example.com', time() - 91 * 86400), null] as $bad) {
            $this->api('GET', '/crowdmerch/account/orders', [], $bad)->assertUnauthorized();
        }
    }

    public function test_tracking_links_follow_the_carrier(): void
    {
        $tnt = $this->makeOrder('pat@example.com', 5, ['carrier' => 'TNT', 'consignment' => 'GEA412387690']);
        $fedex = $this->makeOrder('pat@example.com', 5, ['carrier' => 'FedEx', 'consignment' => '7712 3456']);
        $this->assertSame('https://www.tnt.com/express/en_au/site/shipping-tools/tracking.html?searchType=con&cons=GEA412387690', $tnt->trackingUrl());
        $this->assertSame('https://www.fedex.com/fedextrack/?trknbr=7712%203456', $fedex->trackingUrl());
        $this->assertNull($this->makeOrder('pat@example.com', 2, ['carrier' => 'TNT'])->trackingUrl());

        $list = $this->api('GET', '/crowdmerch/account/orders', [], app(AccountTokens::class)->issue('pat@example.com'))->assertOk();
        $this->assertContains($tnt->trackingUrl(), array_column($list->json('orders'), 'tracking_url'));
    }

    public function test_change_requests_are_taken_until_dispatched_and_staff_are_told_internally(): void
    {
        Mail::fake();
        $token = app(AccountTokens::class)->issue('pat@example.com');
        $open = $this->makeOrder('pat@example.com', 4);
        $dispatched = $this->makeOrder('pat@example.com', WebsiteOrder::STAGE_DISPATCHED);

        $this->api('POST', '/crowdmerch/account/change', ['order_number' => $open->number, 'message' => 'Add 10 more size L'], $token)->assertCreated();
        $this->assertSame('Add 10 more size L', $open->changeRequests()->sole()->message);
        $notice = PendingEmail::sole();
        $this->assertSame(['change_request', 'draft', config('website.orders.staff_email')], [$notice->kind, $notice->status, $notice->recipient]);
        Mail::assertNothingSent(); // auto_send_invoice is off: the notice waits under Website orders

        $this->api('POST', '/crowdmerch/account/change', ['order_number' => $dispatched->number, 'message' => 'Change the address'], $token)->assertStatus(409);
        $this->assertSame(0, $dispatched->changeRequests()->count());

        $list = $this->api('GET', '/crowdmerch/account/orders', [], $token)->json('orders');
        $this->assertSame([true, false], [collect($list)->firstWhere('number', $open->number)['can_request_change'], collect($list)->firstWhere('number', $dispatched->number)['can_request_change']]);
    }

    public function test_an_order_returns_a_token_for_my_orders(): void
    {
        Storage::fake('artwork');
        Storage::fake('local');
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-30 10:00', 'Australia/Melbourne'));

        $token = $this->withHeaders(['Origin' => self::ORIGIN, 'Accept' => 'application/json'])
            ->post('/crowdmerch/checkout/order', $this->orderForm($this->cart()))->assertCreated()->json('account_token');
        $this->api('GET', '/crowdmerch/account/orders', [], $token)->assertOk()->assertJsonPath('orders.0.number', 'CM-10001')
            ->assertJsonPath('orders.0.items', 'AS Colour Mens Staple Tee 5001 × 60, AS Colour Womens Maple Tee 4001 × 50 · 1 branding position');
        Carbon::setTestNow();
    }
}
