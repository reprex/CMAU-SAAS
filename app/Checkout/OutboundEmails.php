<?php

namespace App\Checkout;

use App\Jobs\SendPendingEmail;
use App\Mail\WebsiteChangeRequestMail;
use App\Mail\WebsiteInvoiceMail;
use App\Models\AuditLog;
use App\Models\PendingEmail;
use App\Models\User;
use App\Models\WebsiteOrder;
use App\Models\WebsiteOrderChange;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Website order emails (docs/website-checkout.md).
 *
 * REPSTACK principle 6 (client emails go to drafts and are never sent
 * automatically) stands until Scott lifts it, so website.orders.auto_send_invoice
 * defaults to false: the invoice email is saved as a draft that staff send with
 * one click under Website orders. When it is true, a queued job sends it within
 * the minute cron. Internal change-request notices follow the same switch.
 */
class OutboundEmails
{
    public function prepareInvoice(WebsiteOrder $order): PendingEmail
    {
        return $this->prepare(PendingEmail::query()->create([
            'kind' => PendingEmail::KIND_INVOICE,
            'website_order_id' => $order->id,
            'recipient' => $order->email,
            'subject' => WebsiteInvoiceMail::subjectFor($order),
            'status' => 'draft',
        ]));
    }

    public function prepareChangeNotice(WebsiteOrderChange $change): PendingEmail
    {
        return $this->prepare(PendingEmail::query()->create([
            'kind' => PendingEmail::KIND_CHANGE,
            'website_order_id' => $change->website_order_id,
            'website_order_change_id' => $change->id,
            'recipient' => (string) config('website.orders.staff_email'),
            'subject' => WebsiteChangeRequestMail::subjectFor($change),
            'status' => 'draft',
        ]));
    }

    /** Sends it now (staff's Send button, or the queued job). */
    public function send(PendingEmail $email, ?User $by = null): bool
    {
        if ($email->status === 'sent') {
            return true;
        }
        $order = $email->order;
        try {
            $mail = match ($email->kind) {
                PendingEmail::KIND_INVOICE => new WebsiteInvoiceMail($order),
                PendingEmail::KIND_CHANGE => new WebsiteChangeRequestMail($email->change),
            };
            $pending = Mail::to($email->recipient);
            if ($email->kind === PendingEmail::KIND_INVOICE && config('mail.from.address')) {
                $pending->bcc(config('mail.from.address'));
            }
            $pending->send($mail);
        } catch (Throwable $e) {
            report($e);
            $email->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);

            return false;
        }

        $email->update(['status' => 'sent', 'sent_at' => now(), 'sent_by' => $by?->id, 'error' => null]);
        if ($email->kind === PendingEmail::KIND_INVOICE && $order) {
            $order->forceFill(['invoice_sent_at' => now()] + ($order->stage < WebsiteOrder::STAGE_INVOICE_SENT
                ? ['stage' => WebsiteOrder::STAGE_INVOICE_SENT, 'stage_changed_at' => now()] : []))->save();
        }
        AuditLog::record('website_order.email_sent', $order, ['kind' => $email->kind, 'to' => $email->recipient]);

        return true;
    }

    private function prepare(PendingEmail $email): PendingEmail
    {
        if (config('website.orders.auto_send_invoice')) {
            $email->update(['status' => 'queued']);
            SendPendingEmail::dispatch($email->id);
        }

        return $email;
    }
}
