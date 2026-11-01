<?php

namespace App\Mail;

use App\Checkout\InvoiceFiles;
use App\Models\WebsiteOrder;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The client's invoice for a website order (CM-xxxxx), with the invoice PDF. */
class WebsiteInvoiceMail extends Mailable
{
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
        return new Content(text: 'mail.website-invoice');
    }

    public function attachments(): array
    {
        $order = $this->order;

        return [Attachment::fromData(fn () => app(InvoiceFiles::class)->pdf($order), 'Invoice '.$order->number.'.pdf')->withMime('application/pdf')];
    }
}
