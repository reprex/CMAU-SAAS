<?php

namespace App\Mail;

use App\Models\Quote;
use App\Pdf\QuotePdf;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class InstantQuoteMail extends Mailable
{
    public function __construct(public Quote $quote) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your CrowdMerch quote {$this->quote->number}", replyTo: [config('quotes.pdf.email')]);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.instant-quote');
    }

    public function attachments(): array
    {
        $quote = $this->quote;

        return [Attachment::fromData(fn () => app(QuotePdf::class)->quote($quote), $quote->number.'.pdf')->withMime('application/pdf')];
    }
}
