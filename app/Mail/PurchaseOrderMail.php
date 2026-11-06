<?php

namespace App\Mail;

use App\Models\PurchaseOrder;
use App\Pdf\QuotePdf;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A purchase order to the supplier's orders email, with the PO PDF (docs/purchase-orders.md). Staff send it. */
class PurchaseOrderMail extends Mailable
{
    public function __construct(public PurchaseOrder $po) {}

    public static function subjectFor(PurchaseOrder $po): string
    {
        return "Purchase order {$po->number} from CrowdMerch".($po->despatch_by ? ' - despatch by '.$po->despatch_by->format('D j M') : '').' - BLIND SHIP';
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: self::subjectFor($this->po), replyTo: [config('quotes.pdf.email')]);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.purchase-order');
    }

    public function attachments(): array
    {
        $po = $this->po;

        return [Attachment::fromData(fn () => app(QuotePdf::class)->purchaseOrder($po), $po->number.'.pdf')->withMime('application/pdf')];
    }
}
