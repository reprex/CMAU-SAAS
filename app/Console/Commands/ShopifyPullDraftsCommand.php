<?php

namespace App\Console\Commands;

use App\Quotes\DraftOrderIntake;
use App\Shopify\ShopifyAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fetches draft orders changed since the last run (a backup to webhooks, and
 * the only route until webhooks are registered). One page per run.
 */
class ShopifyPullDraftsCommand extends Command
{
    protected $signature = 'shopify:pull-drafts {--since= : ISO date to start from instead of the saved cursor}';

    protected $description = 'Bring new and changed Shopify draft orders into the quote desk';

    public function handle(ShopifyAdmin $shopify, DraftOrderIntake $intake): int
    {
        if (! config('shopify.pull_drafts') || ! $shopify->configured()) {
            $this->line('Shopify is not set up; nothing to pull.');

            return self::SUCCESS;
        }

        $cursor = DB::table('counters')->where('name', 'shopify_drafts_since')->value('value');
        $since = $this->option('since') ?: ($cursor ? gmdate('c', (int) $cursor) : now()->subDays(30)->toIso8601String());

        $drafts = $shopify->draftOrdersUpdatedSince($since, 50);
        $latest = $cursor ? (int) $cursor : 0;
        foreach ($drafts as $raw) {
            $intake->receive($raw);
            $latest = max($latest, strtotime((string) ($raw['updated_at'] ?? 'now')));
        }
        if ($latest > 0) {
            DB::table('counters')->updateOrInsert(['name' => 'shopify_drafts_since'], ['value' => $latest]);
        }

        $this->info(count($drafts).' draft order(s) processed.');

        return self::SUCCESS;
    }
}
