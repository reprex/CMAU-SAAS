<?php

namespace App\Console\Commands;

use App\Shopify\StoreBinding;
use Illuminate\Console\Command;
use Throwable;

/**
 * Moves the SaaS's stored Shopify ids to the store .env now points at (docs/publisher.md, "Switching
 * to the live store"). Never calls Shopify. Without --apply it only reports.
 */
class ShopifySwitchStoreCommand extends Command
{
    protected $signature = 'shopify:switch-store {--apply : Archive the old store\'s ids, clear them and bind to the configured store}';

    protected $description = 'Move the stored Shopify ids to the store in .env (report only without --apply)';

    public function handle(StoreBinding $binding): int
    {
        $to = $binding->configured();
        $from = $binding->bound();
        $now = $binding->counts();
        $this->line('Store in .env (SHOPIFY_SHOP_DOMAIN): '.($to ?: '(not set)'));
        $this->line('Stored ids belong to: '.($from ?? '(none recorded)'));
        $this->line("Stored now: {$now['products']} products, {$now['media']} pictures, {$now['collections']} collections.");
        if ($to === '') {
            $this->error('Set SHOPIFY_SHOP_DOMAIN first, then run config:cache.');

            return self::FAILURE;
        }
        if ($from === $to) {
            $this->info('Nothing to switch: the stored ids already belong to this store.');

            return self::SUCCESS;
        }
        $back = $binding->archived($to);
        $this->newLine();
        $this->line('With --apply:');
        $this->line('  1. '.($from ? "Keep {$from}'s ids aside (to restore if you switch back)" : 'Nothing to keep aside').'; clear them here.');
        $this->line('  2. '.(array_sum($back) ? "Restore the ids kept earlier for {$to}: {$back['products']} products, {$back['media']} pictures, {$back['collections']} collections." : "Start {$to} with no ids: each product is found by its handle (APPAID) when published and updated in place; products not on the store are created."));
        $this->line('  3. Turn automatic publishing OFF. Nothing is sent to Shopify by this command.');
        $this->line('  Then: shopify:metafield-definitions, shopify:collections --sync, shopify:publish --limit=50 (check), shopify:auto-publish on.');
        if (! $this->option('apply')) {
            $this->warn('Report only. Run again with --apply to switch.');

            return self::SUCCESS;
        }

        try {
            $r = $binding->switchToConfigured();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $a = $r['archived'];
        $b = $r['restored'];
        $this->info("Switched to {$r['to']}. Kept aside from ".($r['from'] ?? 'none').": {$a['products']} products, {$a['media']} pictures, {$a['collections']} collections. Restored: {$b['products']} products, {$b['media']} pictures, {$b['collections']} collections. Automatic publishing is OFF.");

        return self::SUCCESS;
    }
}
