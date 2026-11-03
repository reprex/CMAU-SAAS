<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Shopify\ProductPublisher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Publish products to Shopify (docs/publisher.md). By default: web-range
 * products changed since they were last published, plus products that left the
 * range and need archiving. --preview writes what would be sent to
 * storage/app/publish-preview/ instead of sending.
 */
class ShopifyPublishCommand extends Command
{
    protected $signature = 'shopify:publish
        {--product=* : Only these product ids}
        {--limit=50 : At most this many products this run}
        {--preview : Write the payloads to storage/app/publish-preview, send nothing}
        {--force : Send even if nothing changed}
        {--seconds=0 : Stop after this many seconds (0: no limit); the minute cron uses 45}
        {--retry-after=0 : Skip products whose last publish failed within this many minutes}
        {--parallel=5 : Send this many plain updates to Shopify at once}';

    protected $description = 'Publish changed range products to Shopify (development store unless SHOPIFY_ALLOW_LIVE)';

    public function handle(ProductPublisher $publisher): int
    {
        $ids = array_map('intval', (array) $this->option('product'));
        $q = Product::query()->with(['supplier', 'detail', 'webPrice', 'override', 'images', 'colours', 'priceOptions'])
            ->when($ids !== [], fn ($w) => $w->whereIn('id', $ids), fn ($w) => $w->where(fn ($w) => $w
                ->where(fn ($w) => $w->where('tier', Product::TIER_LIVE)->where(fn ($w) => $w->where(fn ($w) => $w->whereNull('published_at')->whereNull('shopify_product_id')->where('discontinued', false))->orWhereColumn('range_changed_at', '>', 'published_at')))
                ->orWhere(fn ($w) => $w->whereNotNull('shopify_product_id')->where(fn ($w) => $w->where('tier', '!=', Product::TIER_LIVE)->orWhere('discontinued', true))->whereColumn('updated_at', '>', 'published_at'))))
            ->when($ids === [] && (int) $this->option('retry-after') > 0, fn ($w) => $w->whereNotIn('id', DB::table('shopify_publish_log')
                ->where('ok', false)->where('created_at', '>', now()->subMinutes((int) $this->option('retry-after')))->select('product_id')))
            // New products first (finish the range), then republishes of products already on the store.
            ->orderByRaw('case when published_at is null then 0 else 1 end')->orderBy('id')->limit((int) $this->option('limit'));

        $started = microtime(true);
        $budget = (int) $this->option('seconds');
        $done = ['create' => 0, 'update' => 0, 'archive' => 0, 'skip' => 0, 'preview' => 0, 'failed' => 0];
        $parallel = max(1, (int) $this->option('parallel'));
        $stop = false;
        foreach ($q->get()->chunk($parallel * 2) as $group) {
            if ($stop || ($budget > 0 && microtime(true) - $started > $budget)) {
                break;
            }
            $publisher->publishMany($group, $parallel, (bool) $this->option('preview'), (bool) $this->option('force'), null, function (Product $product, array $r) use (&$done, &$stop) {
                if (isset($r['payload'])) {
                    Storage::put("publish-preview/{$product->id}.json", json_encode($r['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                }
                $r['ok'] ? $done[$r['action']]++ : $done['failed']++;
                $this->line(sprintf('%-9d %-8s %s %s', $product->id, $r['action'], $r['ok'] ? 'ok' : 'FAILED', $r['message'] ?? ''));
                // Shopify's daily limit on new variants (stores over 50,000 variants): stop and pause
                // the automatic publishing for a few hours rather than fail product after product.
                if (! $stop && ! $r['ok'] && preg_match('/VARIANT_THROTTLE|variant creation/i', (string) ($r['message'] ?? ''))) {
                    Cache::put('shopify:publish_paused_until', now()->addHours(6)->toIso8601String(), now()->addHours(6));
                    $this->warn('Shopify\'s daily new-variant limit reached: automatic publishing paused for 6 hours.');
                    $stop = true;
                }
            });
        }
        $this->info(collect($done)->filter()->map(fn ($n, $k) => "{$n} {$k}")->implode(', ') ?: 'Nothing to publish.');

        return $done['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
