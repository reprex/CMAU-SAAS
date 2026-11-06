<?php

namespace App\Http\Controllers;

use App\Checkout\OutboundEmails;
use App\Mail\PurchaseOrderMail;
use App\Mail\QuoteMail;
use App\Mail\WebsiteChangeRequestMail;
use App\Mail\WebsiteInvoiceMail;
use App\Models\PendingEmail;
use App\Models\PurchaseOrder;
use Illuminate\Http\Request;

/**
 * Review & send (Scott, 7 Nov 2026; docs/desk.md): one screen for every document email (quote, invoice,
 * purchase order), with the PDF and the email exactly as they go out. Nothing is sent until staff press
 * Send (REPSTACK principle 6).
 */
class EmailReviewController extends Controller
{
    public function show(PendingEmail $email)
    {
        return view('emails.review', ['email' => $email, 'pdf' => $this->pdfUrl($email), 'back' => $this->backUrl($email), 'job' => $this->jobName($email)]);
    }

    /** The email's HTML, for the preview frame. */
    public function preview(PendingEmail $email)
    {
        return response($this->mailable($email)->render())->header('Content-Security-Policy', "default-src 'none'; img-src data: https: http:; style-src 'unsafe-inline'");
    }

    public function send(Request $request, PendingEmail $email, OutboundEmails $emails)
    {
        $data = $request->validate(['recipient' => ['required', 'email:rfc', 'max:190']]);
        if ($email->status !== 'sent' && $data['recipient'] !== $email->recipient) {
            $email->update(['recipient' => $data['recipient']]);
        }
        $ok = $emails->send($email, $request->user());

        return redirect($this->backUrl($email))->with('status', $ok ? "Sent to {$email->recipient}." : 'Sending failed: '.$email->fresh()->error);
    }

    /** Prepare (or reuse) a purchase order's email, then review it. */
    public function purchaseOrder(PurchaseOrder $po, OutboundEmails $emails)
    {
        $po->load('supplier');
        if (! filter_var((string) $po->supplier?->orders_email, FILTER_VALIDATE_EMAIL)) {
            return redirect()->route('purchase-orders.show', $po)->with('status', 'Add the supplier\'s orders email first (Admin › Suppliers).');
        }
        $email = PendingEmail::query()->where('purchase_order_id', $po->id)->whereIn('status', ['draft', 'failed'])->latest('id')->first()
            ?? $emails->preparePurchaseOrder($po);

        return redirect()->route('emails.review', $email);
    }

    private function mailable(PendingEmail $email)
    {
        return match ($email->kind) {
            PendingEmail::KIND_INVOICE => new WebsiteInvoiceMail($email->order),
            PendingEmail::KIND_QUOTE => new QuoteMail($email->quote),
            PendingEmail::KIND_PO => new PurchaseOrderMail($email->purchaseOrder),
            default => new WebsiteChangeRequestMail($email->change),
        };
    }

    private function pdfUrl(PendingEmail $email): ?string
    {
        return match ($email->kind) {
            PendingEmail::KIND_INVOICE => route('website-orders.invoice', $email->website_order_id),
            PendingEmail::KIND_QUOTE => route('quotes.pdf', $email->quote_id),
            PendingEmail::KIND_PO => route('purchase-orders.pdf', $email->purchase_order_id),
            default => null,
        };
    }

    private function backUrl(PendingEmail $email): string
    {
        return match (true) {
            $email->kind === PendingEmail::KIND_QUOTE && $email->quote_id !== null => route('quotes.show', $email->quote_id),
            $email->kind === PendingEmail::KIND_PO && $email->website_order_id === null && $email->purchase_order_id !== null => route('purchase-orders.show', $email->purchase_order_id),
            $email->website_order_id !== null => route('website-orders.show', $email->website_order_id),
            default => route('desk'),
        };
    }

    private function jobName(PendingEmail $email): string
    {
        return match ($email->kind) {
            PendingEmail::KIND_INVOICE => 'invoice',
            PendingEmail::KIND_QUOTE => 'quote',
            PendingEmail::KIND_PO => 'purchase order',
            default => 'email',
        };
    }
}
