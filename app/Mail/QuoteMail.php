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

/** A quote staff send from the SaaS (Quotes › quote › Email quote), with the quote PDF and the products' branding templates. */
class QuoteMail extends Mailable
{
    use AttachesTemplates;

    public function __construct(public Quote $quote) {}

    public static function subjectFor(Quote $quote): string
    {
        return "Your CrowdMerch quote {$quote->number}";
    }

    public function envelope(): Envelope
    {
        return new Envelope(from: new Address((string) config('mail.from.address'), (string) config('mail.from.quote_name')), subject: self::subjectFor($this->quote), replyTo: [config('quotes.pdf.email')]);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.quote', with: ['templates' => $this->templates()]);
    }

    protected function templateProductIds(): array
    {
        return $this->quote->items()->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    public function attachments(): array
    {
        $quote = $this->quote;

        return [Attachment::fromData(fn () => app(QuotePdf::class)->quote($quote), $quote->number.'.pdf')->withMime('application/pdf'), ...$this->templateAttachments()];
    }
}
