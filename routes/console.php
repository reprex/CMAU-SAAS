<?php

use Illuminate\Support\Facades\Schedule;

/*
| All scheduled work runs from ONE cPanel cron entry, every minute
| (account saasreplicat584, cPanel → Cron Jobs → Once Per Minute):
|   cd /home/saasreplicat584/saas-app && /usr/local/bin/ea-php84 artisan schedule:run >> /dev/null 2>&1
| Each task below does a short batch and exits, to suit shared hosting.
*/

// Heartbeat: the Promodata sync screen shows when the scheduler last ran, so a
// stopped cron (e.g. the server's command-line PHP changing) is visible.
Schedule::call(fn () => cache()->forever('scheduler:last_run', now()->toIso8601String()))
    ->everyMinute()->name('scheduler-heartbeat');

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

// Price check: recount price patterns and screengrab examples (docs/price-check.md).
Schedule::command('pricing:patterns')
    ->dailyAt('04:00')
    ->withoutOverlapping();

// Quote desk: bring in new/changed Shopify draft orders (backup to webhooks).
Schedule::command('shopify:pull-drafts')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

// Process queued jobs (webhooks, Shopify publishing, emails) in short bursts.
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping(2);
