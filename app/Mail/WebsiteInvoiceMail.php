<?php

namespace App\Mail;

use App\Checkout\InvoiceFiles;
use App\Mail\Concerns\AttachesTemplates;
use App\Models\WebsiteOrder;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The client's invoice for a website order (CM-xxxxx), with the invoice PDF. */
class WebsiteInvoiceMail extends Mailable
{
    use AttachesTemplates;

    public function __construct(public WebsiteOrder $order) {}

    public static function subjectFor(WebsiteOrder $order): string
    {
        return "Your CrowdMerch invoice for order {$order->number}";
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: self::subjectFor($this->order), replyTo: [config('quotes.pdf.email')]);
    }

    public function content(): Content
    {
        return new Content(html: 'mail.html.website-invoice', text: 'mail.website-invoice', with: ['templates' => $this->templates()]);
    }

    public function attachments(): array
    {
        $order = $this->order;

        return [Attachment::fromData(fn () => app(InvoiceFiles::class)->pdf($order), 'Invoice '.$order->number.'.pdf')->withMime('application/pdf'), ...$this->templateAttachments()];
    }

    /** The branded products on the order (not samples, which are unbranded). */
    protected function templateProductIds(): array
    {
        return $this->order->isSamples() ? [] : $this->order->lines()->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
    }
}
