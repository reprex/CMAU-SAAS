<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Sends one test email with the configured mailer (MAIL_MAILER, e.g. graph) to check the set-up. */
class MailTestCommand extends Command
{
    protected $signature = 'mail:test {to : Who gets the test email}';

    protected $description = 'Send a test email with the configured mailer';

    public function handle(): int
    {
        $this->line('Mailer: '.config('mail.default').' · from '.config('mail.from.name').' <'.config('mail.from.address').'>');
        try {
            Mail::raw('This is a test from the CrowdMerch SaaS ('.config('app.url').") using the '".config('mail.default')."' mailer.\n\nIf you can read this, quotes and invoices can be emailed.", function ($m) {
                $m->to((string) $this->argument('to'))->subject('CrowdMerch SaaS: test email');
            });
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info('Sent. Check the inbox (and the mailbox\'s Sent Items).');

        return self::SUCCESS;
    }
}
