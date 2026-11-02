<?php

namespace App\Mail;

use App\Models\ProjectRequest;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** To staff: a new project request, with a link to it in the SaaS. */
class ProjectRequestStaffMail extends Mailable
{
    public function __construct(public ProjectRequest $request) {}

    public function envelope(): Envelope
    {
        $r = $this->request;

        return new Envelope(
            subject: "New project request {$r->number}: ".($r->organisation ?: $r->name()).' (reply by '.$r->reply_by->format('D j M').')',
            replyTo: [$r->email],
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.project-request', with: ['r' => $this->request, 'forStaff' => true]);
    }
}
