<?php

namespace App\Checkout;

use App\Checkout\Sms\SmsSender;
use App\Mail\AccountCodeMail;
use App\Models\AccountCode;
use App\Models\WebsiteOrder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * My Orders sign-in: a 6-digit one-time code by email or SMS, only for emails
 * that have orders. Stored as an HMAC, valid 10 minutes, 5 attempts. Callers
 * always answer the same way so the endpoint never reveals who has orders.
 *
 * Security emails are sent straight away (not drafts): an exception to
 * REPSTACK principle 6 for Scott to confirm (docs/website-checkout.md).
 */
class AccountCodes
{
    public function __construct(private readonly SmsSender $sms) {}

    /** Sends a code if the email has orders. Returns whether one was sent (for logs and tests only). */
    public function request(string $email, string $via): bool
    {
        $email = mb_strtolower(trim($email));
        $key = 'account-code-email:'.hash('sha256', $email);
        if (RateLimiter::tooManyAttempts($key, (int) config('website.account.codes_per_email_per_hour'))) {
            return false;
        }
        RateLimiter::hit($key, 3600);

        $latest = WebsiteOrder::query()->where('email', $email)->latest('id')->first();
        if ($latest === null) {
            return false;
        }
        if ($via === 'sms' && blank($latest->phone)) {
            return false;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        AccountCode::query()->where('email', $email)->whereNull('used_at')->update(['used_at' => now()]); // one live code at a time
        AccountCode::query()->create([
            'email' => $email,
            'code_hash' => $this->hash($email, $code),
            'via' => $via,
            'expires_at' => now()->addMinutes((int) config('website.account.code_minutes')),
        ]);

        if ($via === 'sms') {
            $this->sms->send((string) $latest->phone, "Your CrowdMerch My Orders code is {$code}. It expires in ".config('website.account.code_minutes').' minutes.');
        } else {
            Mail::to($email)->send(new AccountCodeMail($code));
        }

        return true;
    }

    /** True when the code is right, unexpired and within the attempt limit; the code is then used up. */
    public function verify(string $email, string $code): bool
    {
        $email = mb_strtolower(trim($email));
        $row = AccountCode::query()->where('email', $email)->whereNull('used_at')->latest('id')->first();
        if ($row === null || $row->expires_at->isPast() || $row->attempts >= (int) config('website.account.max_attempts')) {
            return false;
        }
        $row->increment('attempts');
        if (! hash_equals($row->code_hash, $this->hash($email, preg_replace('/\D/', '', $code)))) {
            return false;
        }
        $row->update(['used_at' => now()]);

        return true;
    }

    private function hash(string $email, string $code): string
    {
        return hash_hmac('sha256', $email.'|'.$code, 'account-code|'.config('app.key'));
    }
}
