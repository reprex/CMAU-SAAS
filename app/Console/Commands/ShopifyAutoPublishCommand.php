<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Turns the automatic range publishing on or off (docs/publisher.md). When on, the minute cron
 * publishes up to 60 changed range products a run (45 seconds), skipping any that failed in the
 * last hour, until the whole range is on the development store; then it keeps it up to date.
 */
class ShopifyAutoPublishCommand extends Command
{
    protected $signature = 'shopify:auto-publish {state? : on or off (leave out to see the state)}';

    protected $description = 'Turn automatic publishing of the range to Shopify on or off';

    public function handle(): int
    {
        $state = $this->argument('state');
        if (in_array($state, ['on', 'off'], true)) {
            Cache::forever('shopify:auto_publish', $state === 'on');
            if ($state === 'on') {
                Cache::forget('shopify:publish_paused_until');
            }
        } elseif ($state !== null) {
            $this->error('Use: shopify:auto-publish on | off');

            return self::INVALID;
        }
        $on = (bool) Cache::get('shopify:auto_publish', false);
        $paused = Cache::get('shopify:publish_paused_until');
        $this->info('Automatic publishing is '.($on ? 'ON' : 'OFF').' (store: '.config('shopify.store').').'.($paused ? " Paused until {$paused} (Shopify's daily new-variant limit)." : ''));

        return self::SUCCESS;
    }
}
