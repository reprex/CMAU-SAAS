<?php

namespace App\Mail;

use App\Models\ProjectRequest;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** To the client: thanks, here's what you sent, and we'll reply by the end of the next working day. */
class ProjectRequestConfirmationMail extends Mailable
{
    public function __construct(public ProjectRequest $request) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "We've got your project request {$this->request->number}",
            replyTo: array_filter([config('quotes.pdf.email')]),
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.project-request', with: ['r' => $this->request, 'forStaff' => false]);
    }
}
