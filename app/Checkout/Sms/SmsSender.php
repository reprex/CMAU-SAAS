<?php

namespace App\Checkout\Sms;

/** Sends a text message. The provider is chosen later; "log" is the default driver. */
interface SmsSender
{
    public function send(string $to, string $message): void;
}
