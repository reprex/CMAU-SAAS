<?php

namespace Tests\Feature;

use App\Checkout\OutboundEmails;
use App\Desk\JobState;
use App\Mail\WebsiteInvoiceMail;
use App\Models\ProjectRequest;
use App\Models\Quote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CheckoutFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class DeskTest extends TestCase
{
    use CheckoutFixtures, QuoteFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckout();
        Storage::fake('local');
    }

    public function test_desk_lists_every_input_with_its_next_step(): void
    {
        $new = $this->makeOrder('pat@example.com', 0, ['organisation' => 'Northside Netball', 'need_by' => now()->addDays(5)->toDateString()]);
        app(OutboundEmails::class)->prepareInvoice($new);
        $paid = $this->makeOrder('kim@example.com', 2, ['organisation' => 'Kestrel Physio', 'need_by' => now()->addDays(40)->toDateString()]);
        $waiting = $this->makeOrder('lee@example.com', 1, ['organisation' => 'Gully Road', 'need_by' => now()->addDays(40)->toDateString()]);
        Quote::query()->create(['number' => 'Q-2041', 'revision' => 1, 'status' => 'NEW', 'source' => 'website', 'client_company' => 'Harbourlight Events', 'client_email' => 'priya@example.com', 'total' => 3412.75]);
        Quote::query()->create(['number' => 'Q-2039', 'revision' => 1, 'status' => 'QUOTE', 'source' => 'website', 'client_name' => 'Monique Tran', 'sent_at' => now()->subDays(5), 'total' => 1540]);
        Quote::query()->create(['number' => 'Q-2000', 'revision' => 1, 'kind' => 'wishlist', 'status' => 'SAVELIST', 'source' => 'website', 'client_name' => 'List Only']);
        ProjectRequest::query()->create(['number' => 'PR-311', 'brief' => 'Shaped enamel pins', 'quantity' => '500', 'first_name' => 'Ari', 'organisation' => 'Wildfern Records',
            'email' => 'ari@example.com', 'phone' => '0400', 'reply_by' => now()->addDay()->toDateString(), 'status' => 'new']);
        $this->staff('sales');

        $r = $this->get(route('desk'))->assertOk()
            ->assertSee('Northside Netball')->assertSee('Review &amp; send invoice', false)
            ->assertSee('Kestrel Physio')->assertSee('Create purchase order')
            ->assertSee('Harbourlight Events')->assertSee('Review &amp; send quote', false)
            ->assertSee('Monique Tran')->assertSee('Follow up')
            ->assertSee('Wildfern Records')->assertSee('Reply to request')
            ->assertDontSee('Gully Road')->assertDontSee('List Only');
        // Urgent (need by in 5 days) comes first.
        $html = $r->getContent();
        $this->assertLessThan(strpos($html, 'Kestrel Physio'), strpos($html, 'Northside Netball'));

        $this->get(route('desk', ['tab' => 'waiting']))->assertOk()->assertSee('Gully Road')->assertDontSee('Northside Netball');
        $this->assertSame('awaiting_payment', JobState::forOrder($waiting->fresh())['bucket']);
        $this->assertSame('proof_po', JobState::forOrder($paid->fresh())['bucket']);
    }

    public function test_review_and_send_shows_the_pdf_and_email_and_sends_only_on_send(): void
    {
        Mail::fake();
        $order = $this->makeOrder('pat@example.com', 0);
        $email = app(OutboundEmails::class)->prepareInvoice($order);
        $this->staff('sales');

        $this->get(route('emails.review', $email))->assertOk()->assertSee(route('website-orders.invoice', $order))->assertSee(route('emails.preview', $email))->assertSee('Send invoice');
        $this->get(route('emails.preview', $email))->assertOk()->assertSee('Please pay in full');
        Mail::assertNothingSent();

        $this->post(route('emails.send', $email), ['recipient' => 'accounts@example.com'])->assertRedirect(route('website-orders.show', $order));
        Mail::assertSent(WebsiteInvoiceMail::class, fn ($m) => $m->hasTo('accounts@example.com'));
        $this->assertSame('sent', $email->fresh()->status);
        $this->assertSame(1, $order->fresh()->stage);
    }

    public function test_viewers_cannot_send(): void
    {
        $order = $this->makeOrder('pat@example.com', 0);
        $email = app(OutboundEmails::class)->prepareInvoice($order);
        $this->staff('sales')->update(['divisions' => ['crowdmerch' => 'view', 'shared' => 'view']]);
        $this->get(route('emails.review', $email))->assertOk()->assertDontSee('Send invoice</button>', false);
        $this->post(route('emails.send', $email), ['recipient' => 'x@example.com'])->assertForbidden();
    }
}
