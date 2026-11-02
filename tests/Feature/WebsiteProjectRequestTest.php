<?php

namespace Tests\Feature;

use App\Mail\ProjectRequestConfirmationMail;
use App\Mail\ProjectRequestStaffMail;
use App\Models\ProjectRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class WebsiteProjectRequestTest extends TestCase
{
    use QuoteFixtures, RefreshDatabase;

    private const ORIGIN = 'https://cmau-dev.myshopify.com';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-02 10:00', 'Australia/Melbourne')); // Monday; Tuesday is Melbourne Cup
        config(['website.orders.allowed_origins' => [self::ORIGIN], 'search.turnstile.protect_website_api' => false, 'app.admin_allowed_ips' => [],
            'website.projects.staff_email' => 'sales@example.com']);
        Storage::fake('artwork');
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function form(array $extra = []): array
    {
        return $extra + [
            'brief' => 'Custom lanyards with our logo for a 3-day conference, plus printed name cards.',
            'kinds' => ['events', 'custom'],
            'quantity' => 'About 400',
            'budget' => '$3,000',
            'need_by' => '2026-12-01',
            'event' => 'Melbourne Tech Week',
            'first_name' => 'Alex',
            'last_name' => 'Ng',
            'organisation' => 'Acme Events',
            'email' => 'Alex@Example.com',
            'phone' => '0400 000 000',
            'contact_by' => 'email',
            'website' => '',
        ];
    }

    public function test_request_is_saved_with_files_and_both_emails_go_out(): void
    {
        $res = $this->withHeaders(['Origin' => self::ORIGIN])->post('/crowdmerch/projects', $this->form([
            'files' => [UploadedFile::fake()->image('sketch.png', 400, 300), UploadedFile::fake()->create('brief.pdf', 120, 'application/pdf')],
        ]));
        $res->assertOk()->assertJson(['success' => true, 'number' => 'PR-10001', 'reply_by' => '2026-11-04', 'reply_by_text' => 'Wednesday 4 November'])
            ->assertHeader('Access-Control-Allow-Origin', self::ORIGIN);

        $r = ProjectRequest::first();
        $this->assertSame('alex@example.com', $r->email);
        $this->assertSame(['events', 'custom'], $r->kinds);
        $this->assertSame('2026-11-04', $r->reply_by->toDateString()); // one working day, Melbourne Cup skipped
        $this->assertCount(2, $r->files);
        Storage::disk('artwork')->assertExists($r->files[0]['path']);
        $this->assertSame('sketch.png', $r->files[0]['name']);

        Mail::assertSent(ProjectRequestConfirmationMail::class, fn ($m) => $m->hasTo('alex@example.com'));
        Mail::assertSent(ProjectRequestStaffMail::class, fn ($m) => $m->hasTo('sales@example.com'));
        $r->refresh();
        $this->assertNotNull($r->confirmation_sent_at);
        $this->assertNotNull($r->staff_notified_at);

        $text = (new ProjectRequestConfirmationMail($r))->render();
        $this->assertStringContainsString("We'll be back in touch by the end of Wednesday 4 November (one working day)", $text);
        $this->assertStringContainsString('Custom lanyards with our logo', $text);
        $this->assertStringContainsString('Quantity: About 400', $text);
        $this->assertStringContainsString('Files: sketch.png, brief.pdf', $text);
    }

    public function test_confirmation_can_be_switched_off(): void
    {
        config(['website.projects.send_confirmation' => false]);
        $this->withHeaders(['Origin' => self::ORIGIN])->post('/crowdmerch/projects', $this->form())->assertOk()->assertJson(['confirmation' => false]);
        Mail::assertNotSent(ProjectRequestConfirmationMail::class);
        Mail::assertSent(ProjectRequestStaffMail::class);
    }

    public function test_validation_honeypot_and_origin(): void
    {
        $this->withHeaders(['Origin' => self::ORIGIN])->postJson('/crowdmerch/projects', $this->form(['brief' => '', 'phone' => 'call me']))
            ->assertStatus(422)->assertJsonValidationErrors(['brief', 'phone']);
        $this->withHeaders(['Origin' => self::ORIGIN])->postJson('/crowdmerch/projects', $this->form(['website' => 'spam']))->assertOk()->assertJson(['success' => true]);
        $this->withHeaders(['Origin' => 'https://evil.example'])->postJson('/crowdmerch/projects', $this->form())->assertForbidden();
        $this->withHeaders(['Origin' => self::ORIGIN])->options('/crowdmerch/projects')->assertNoContent();
        $this->assertSame(0, ProjectRequest::count());
    }

    public function test_staff_list_show_update_and_file_download(): void
    {
        $this->withHeaders(['Origin' => self::ORIGIN])->post('/crowdmerch/projects', $this->form(['files' => [UploadedFile::fake()->image('sketch.png')]]))->assertOk();
        $r = ProjectRequest::first();
        $this->staff('admin');
        $this->get(route('project-requests.index'))->assertOk()->assertSee('PR-10001')->assertSee('Acme Events')->assertSee('Wed 4 Nov');
        $this->get(route('project-requests.show', $r))->assertOk()->assertSee('Custom lanyards')->assertSee('sketch.png');
        $this->get(route('project-requests.file', [$r, 0]))->assertOk();
        $this->put(route('project-requests.update', $r), ['status' => 'replied', 'staff_notes' => 'Sent options'])->assertRedirect();
        $this->assertSame('replied', $r->fresh()->status);
    }
}
