<?php

namespace App\Mail;

use App\Models\WebsiteOrderChange;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Internal: a client asked to change a website order (My Orders). */
class WebsiteChangeRequestMail extends Mailable
{
    public function __construct(public WebsiteOrderChange $change) {}

    public static function subjectFor(WebsiteOrderChange $change): string
    {
        return "Change request for website order {$change->order->number}";
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: self::subjectFor($this->change), replyTo: [$this->change->order->email]);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.website-change-request');
    }
}
