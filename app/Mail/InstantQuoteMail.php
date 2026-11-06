<?php

namespace App\Mail;

use App\Catalogue\TemplateFiles;
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
        return new Content(html: 'mail.html.instant-quote', text: 'mail.instant-quote', with: ['templates' => $this->templates(), 'contains' => $this->contains()]);
    }

    public function attachments(): array
    {
        $quote = $this->quote;

        return [Attachment::fromData(fn () => app(QuotePdf::class)->quote($quote), $quote->number.'.pdf')->withMime('application/pdf'), ...$this->templateAttachments()];
    }

    /**
     * What the quote PDF holds, for the email's one line (Scott, 7 Nov 2026): mock-ups and branding templates
     * only when the quote has them, e.g. "detailed pricing, order instructions, mock-ups, and branding templates".
     */
    public function contains(): string
    {
        $parts = ['detailed pricing', 'order instructions'];
        if (! empty($this->quote->mockups)) {
            $parts[] = 'mock-ups';
        }
        if (app(TemplateFiles::class)->forProducts($this->templateProductIds())->isNotEmpty()) {
            $parts[] = 'branding templates';
        }
        $last = array_pop($parts);

        return implode(', ', $parts).(count($parts) > 1 ? ', and ' : ' and ').$last;
    }

    protected function templateProductIds(): array
    {
        return $this->quote->items()->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
    }
}
