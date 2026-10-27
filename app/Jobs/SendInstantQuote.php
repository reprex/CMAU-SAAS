<?php

namespace App\Jobs;

use App\Mail\InstantQuoteMail;
use App\Models\AuditLog;
use App\Models\Quote;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/** Emails the website's instant quote (with the quote PDF) to the client. */
class SendInstantQuote implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $quoteId) {}

    public function handle(): void
    {
        $quote = Quote::find($this->quoteId);
        if ($quote === null || ! $quote->client_email) {
            return;
        }
        Mail::to($quote->client_email)->bcc(config('mail.from.address'))->send(new InstantQuoteMail($quote));
        $quote->update(['sent_at' => now(), 'status' => $quote->status === 'NEW' ? 'QUOTE' : $quote->status]);
        AuditLog::record('quote.instant_quote_emailed', $quote);
    }
}
