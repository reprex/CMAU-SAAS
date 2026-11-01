<?php

namespace App\Jobs;

use App\Checkout\OutboundEmails;
use App\Models\PendingEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends a queued website order email (only used when
 * website.orders.auto_send_invoice is on). Runs in the minute cron's short
 * queue:work burst; one email per job.
 */
class SendPendingEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $pendingEmailId) {}

    public function handle(OutboundEmails $emails): void
    {
        $email = PendingEmail::query()->find($this->pendingEmailId);
        if ($email && $email->status === 'queued') {
            $emails->send($email);
        }
    }
}
