<?php

namespace App\Mail;

use App\Mail\Concerns\AttachesTemplates;
use App\Models\Quote;
use App\Pdf\QuotePdf;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class InstantQuoteMail extends Mailable
{
    use AttachesTemplates;

    public function __construct(public Quote $quote) {}

    public function envelope(): Envelope
    {
        return new Envelope(from: new Address((string) config('mail.from.address'), (string) config('mail.from.quote_name')), subject: "Your CrowdMerch quote {$this->quote->number}", replyTo: [config('quotes.pdf.email')]);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.instant-quote', with: ['templates' => $this->templates()]);
    }

    public function attachments(): array
    {
        $quote = $this->quote;

        return [Attachment::fromData(fn () => app(QuotePdf::class)->quote($quote), $quote->number.'.pdf')->withMime('application/pdf'), ...$this->templateAttachments()];
    }

    protected function templateProductIds(): array
    {
        return $this->quote->items()->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
    }
}
