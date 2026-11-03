<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Is the range ready to publish, and how far has it got? (docs/publisher.md) */
class ShopifyRangeReportCommand extends Command
{
    protected $signature = 'shopify:range-report';

    protected $description = 'Readiness and progress of publishing the web range to Shopify';

    /** Shopify (non-Plus): over this many variants, new variants are limited to 1,000 a day. */
    public const VARIANT_LIMIT = 50000;

    public function handle(): int
    {
        $live = Product::query()->where('tier', Product::TIER_LIVE)->where('discontinued', false);
        $ids = (clone $live)->pluck('id');
        $priced = DB::table('product_web_prices')->whereIn('product_id', $ids)->whereNotNull('collection_variant_price')->count();
        $hydrated = (clone $live)->whereNotNull('hydrated_at')->count();
        $published = (clone $live)->whereNotNull('shopify_product_id')->count();
        $waiting = (clone $live)->where(fn ($w) => $w->whereNull('published_at')->orWhereColumn('range_changed_at', '>', 'published_at'))->count();
        $failedIds = DB::table('shopify_publish_log as l')->where('ok', false)->where('created_at', '>', now()->subDay())
            ->whereNotExists(fn ($q) => $q->from('shopify_publish_log as l2')->whereColumn('l2.product_id', 'l.product_id')->where('l2.ok', true)->whereColumn('l2.id', '>', 'l.id'))
            ->distinct()->pluck('product_id');

        // Variants: one per colour (hidden colours left out), or one "Custom Print" variant.
        $colours = DB::table('product_colours')->whereIn('product_id', $ids)->selectRaw('product_id, count(distinct name) as n')->groupBy('product_id')->pluck('n', 'product_id');
        $hidden = DB::table('product_overrides')->whereIn('product_id', $ids)->pluck('data', 'product_id')
            ->map(fn ($d) => count((array) (json_decode((string) $d, true)['hidden_colours'] ?? [])));
        $variants = $ids->sum(fn ($id) => max(1, (int) ($colours[$id] ?? 0) - (int) ($hidden[$id] ?? 0)));
        $variantsOn = (clone $live)->whereNotNull('shopify_product_id')->pluck('id')->sum(fn ($id) => max(1, (int) ($colours[$id] ?? 0) - (int) ($hidden[$id] ?? 0)));

        $n = fn ($v) => number_format((int) $v);
        $this->table(['', 'Products'], [
            ['Web range (live, not discontinued)', $n($ids->count())],
            ['  with full Promodata detail', $n($hydrated)],
            ['  priced (a price Shopify can show)', $n($priced)],
            ['  on the store now', $n($published)],
            ['  waiting to publish (new or changed)', $n($waiting)],
            ['  failed in the last day (not since fixed)', $n($failedIds->count())],
            ['Variants for the whole range (one per colour)', $n($variants)],
            ['  on the store now', $n($variantsOn)],
        ]);
        if ($variants > self::VARIANT_LIMIT) {
            $this->warn("The range needs {$n($variants)} variants. Shopify limits stores over ".$n(self::VARIANT_LIMIT).' variants to 1,000 new variants a day (except Shopify Plus): the last '.$n($variants - self::VARIANT_LIMIT).' would take about '.ceil(($variants - self::VARIANT_LIMIT) / 1000).' days.');
        }
        if ($failedIds->isNotEmpty()) {
            $this->line('Failed: '.$failedIds->take(20)->implode(', ').($failedIds->count() > 20 ? ' …' : '').'  (see shopify_publish_log, or shopify:publish --product=ID)');
        }
        $this->info('Automatic publishing: '.(Cache::get('shopify:auto_publish') ? 'ON' : 'OFF').(Cache::get('shopify:publish_paused_until') ? ' (paused until '.Cache::get('shopify:publish_paused_until').')' : '').'. Store: '.config('shopify.store'));

        return self::SUCCESS;
    }
}
