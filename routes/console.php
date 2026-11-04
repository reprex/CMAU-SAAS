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

// Decoration types from the supplier rules and pages, so new descriptions aren't classified by hand
// (docs/decoration-types.md). Only fills in types not yet set; disagreements are left for staff.
Schedule::command('catalogue:classify --apply --show=0')
    ->dailyAt('03:45')
    ->withoutOverlapping(30);

// Price check from the suppliers' own pages (blank prices), before the patterns are recounted.
Schedule::command('pricing:verify-pages')
    ->dailyAt('03:55')
    ->withoutOverlapping(30);

// Price check: recount price patterns and screengrab examples (docs/price-check.md).
Schedule::command('pricing:patterns')
    ->dailyAt('04:00')
    ->withoutOverlapping();

// Image check: Claude looks at unsure images on the web range, a few products a minute (docs/images.md).
Schedule::command('catalogue:check-images')
    ->everyMinute()
    ->withoutOverlapping(5);

// Range publishing to Shopify (docs/publisher.md): on with shopify:auto-publish on. Up to 60
// changed products a minute, failed ones retried after an hour, paused by Shopify's variant limit.
Schedule::command('shopify:publish --limit=250 --seconds=45 --retry-after=60 --parallel=5')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->when(fn () => (bool) cache()->get('shopify:auto_publish') && ! cache()->get('shopify:publish_paused_until'));

// Range collections: keep the product order in each subcategory collection (docs/publisher.md).
Schedule::command('shopify:collections --sort --seconds=45')
    ->everyThirtyMinutes()
    ->withoutOverlapping(10)
    ->when(fn () => (bool) cache()->get('shopify:auto_publish'));

// Logo areas: re-learn when staff approved one and apply to the range (docs/logo-preview.md).
// Optional: coverage comes from templates and Promodata print areas; the --sample step is not scheduled.
Schedule::command('catalogue:logo-areas')
    ->everyTenMinutes()
    ->withoutOverlapping(10);

// Supplier branding templates: find them, and read up to 20 a minute, 5 at a time (docs/branding-templates.md).
Schedule::command('catalogue:read-templates --limit=20 --parallel=5 --seconds=50')
    ->everyMinute()
    ->withoutOverlapping(5);

// Range › Changes to vet (docs/range.md): suppliers' new products to approve and discontinued
// products to follow up, fetched and checked a few at a time; checks re-run nightly.
Schedule::command('range:changes --limit=10')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);
Schedule::command('range:changes --recheck --limit=0')
    ->dailyAt('04:45')
    ->withoutOverlapping(60);

// Branding positions: where they come from, for Range › Positions (read-only).
Schedule::command('catalogue:positions-report')
    ->dailyAt('04:30')
    ->withoutOverlapping(30);

// Quote desk: bring in new/changed Shopify draft orders (backup to webhooks).
Schedule::command('shopify:pull-drafts')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

// Process queued jobs (webhooks, Shopify publishing, emails) in short bursts.
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping(2);
