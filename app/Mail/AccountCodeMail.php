<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** My Orders one-time code. A security email: sent immediately, never a draft. */
class AccountCodeMail extends Mailable
{
    public function __construct(public string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your CrowdMerch code: {$this->code}");
    }

    public function content(): Content
    {
        return new Content(text: 'mail.account-code');
    }
}
