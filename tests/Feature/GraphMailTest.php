<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class GraphMailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'graph', 'mail.mailers.graph' => ['transport' => 'graph', 'tenant' => 't1', 'client_id' => 'c1', 'client_secret' => 's1', 'mailbox' => 'hello@crowdmerch.com.au'],
            'mail.from' => ['address' => 'hello@crowdmerch.com.au', 'name' => 'CrowdMerch', 'quote_name' => 'Scott at CrowdMerch']]);
        Cache::flush();
    }

    public function test_sends_through_graph_as_the_mailbox_and_saves_to_sent_items(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            'graph.microsoft.com/*' => Http::response(null, 202),
        ]);

        Mail::raw('Hello', fn ($m) => $m->to('client@example.com')->replyTo('hello@crowdmerch.com.au')->subject('Hi')->attachData('PDF', 'quote.pdf', ['mime' => 'application/pdf']));

        Http::assertSent(function ($r) {
            if (! str_contains($r->url(), '/users/hello%40crowdmerch.com.au/sendMail')) {
                return false;
            }
            $m = $r['message'];

            return $r->hasHeader('Authorization', 'Bearer tok') && $r['saveToSentItems'] === true && $m['subject'] === 'Hi'
                && $m['toRecipients'][0]['emailAddress']['address'] === 'client@example.com'
                && $m['from']['emailAddress'] === ['address' => 'hello@crowdmerch.com.au', 'name' => 'CrowdMerch']
                && $m['attachments'][0]['name'] === 'quote.pdf' && base64_decode($m['attachments'][0]['contentBytes']) === 'PDF';
        });
    }

    public function test_large_attachments_go_through_a_draft_and_an_upload_session(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'tok']),
            'graph.microsoft.com/v1.0/users/*/messages/m1/attachments/createUploadSession' => Http::response(['uploadUrl' => 'https://upload.example/u1']),
            'graph.microsoft.com/v1.0/users/*/messages/m1/send' => Http::response(null, 202),
            'graph.microsoft.com/v1.0/users/*/messages' => Http::response(['id' => 'm1'], 201),
            'upload.example/*' => Http::response(['ok' => true], 200),
        ]);

        Mail::raw('Templates', fn ($m) => $m->to('client@example.com')->subject('Big')->attachData(str_repeat('x', 4_000_000), 'template.pdf', ['mime' => 'application/pdf']));

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/messages') && ! isset($r['attachments']));
        Http::assertSent(fn ($r) => $r->url() === 'https://upload.example/u1' && $r->hasHeader('Content-Range', 'bytes 0-3276799/4000000'));
        Http::assertSent(fn ($r) => $r->url() === 'https://upload.example/u1' && $r->hasHeader('Content-Range', 'bytes 3276800-3999999/4000000'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/messages/m1/send'));
    }

    public function test_a_sign_in_failure_is_reported(): void
    {
        Http::fake(['login.microsoftonline.com/*' => Http::response(['error_description' => 'AADSTS7000215: Invalid client secret'], 401)]);

        $this->artisan('mail:test', ['to' => 'scott@example.com'])->expectsOutputToContain('Invalid client secret')->assertFailed();
    }
}
