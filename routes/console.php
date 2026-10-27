<?php

use Illuminate\Support\Facades\Schedule;

/*
| All scheduled work runs from ONE cPanel cron entry, every minute
| (account saasreplicat584, cPanel → Cron Jobs → Once Per Minute):
|   cd /home/saasreplicat584/saas-app && /usr/local/bin/ea-php84 artisan schedule:run >> /dev/null 2>&1
| Each task below does a short batch and exits, to suit shared hosting.
*/

// Supplier data: start a "changed since last run" sync overnight...
Schedule::command('promodata:sync')
    ->dailyAt('01:30')
    ->withoutOverlapping()
    ->onOneServer();

// ...and work through it a few pages per minute until it finishes.
Schedule::command('promodata:sync-step')
    ->everyMinute()
    ->withoutOverlapping(10);

// Keep hosted data compact: unused active products drop to the summary tier.
Schedule::command('catalogue:demote')
    ->dailyAt('03:30')
    ->withoutOverlapping();

// Quote desk: bring in new/changed Shopify draft orders (backup to webhooks).
Schedule::command('shopify:pull-drafts')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

// Process queued jobs (webhooks, Shopify publishing, emails) in short bursts.
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping(2);
