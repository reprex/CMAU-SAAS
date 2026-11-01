<?php

namespace App\Checkout\Sms;

use Illuminate\Support\Facades\Log;

/** Development driver: writes the text to the log instead of sending it. Not for production. */
class LogSmsSender implements SmsSender
{
    public function send(string $to, string $message): void
    {
        $digits = preg_replace('/\D/', '', $to);
        Log::info('SMS (log driver, not sent)', ['to' => substr($digits, 0, 2).str_repeat('•', max(0, strlen($digits) - 5)).substr($digits, -3), 'message' => $message]);
    }
}
