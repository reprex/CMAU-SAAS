<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Shopify\ProductPublisher;
use Illuminate\Console\Command;
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
        {--force : Send even if nothing changed}';

    protected $description = 'Publish changed range products to Shopify (development store unless SHOPIFY_ALLOW_LIVE)';

    public function handle(ProductPublisher $publisher): int
    {
        $ids = array_map('intval', (array) $this->option('product'));
        $q = Product::query()->with(['supplier', 'detail', 'webPrice', 'override', 'images', 'colours'])
            ->when($ids !== [], fn ($w) => $w->whereIn('id', $ids), fn ($w) => $w->where(fn ($w) => $w
                ->where(fn ($w) => $w->where('tier', Product::TIER_LIVE)->where(fn ($w) => $w->whereNull('published_at')->orWhereColumn('range_changed_at', '>', 'published_at')))
                ->orWhere(fn ($w) => $w->whereNotNull('shopify_product_id')->where(fn ($w) => $w->where('tier', '!=', Product::TIER_LIVE)->orWhere('discontinued', true))->whereColumn('updated_at', '>', 'published_at'))))
            ->orderBy('id')->limit((int) $this->option('limit'));

        $done = ['create' => 0, 'update' => 0, 'archive' => 0, 'skip' => 0, 'preview' => 0, 'failed' => 0];
        foreach ($q->get() as $product) {
            $r = $publisher->publish($product, (bool) $this->option('preview'), (bool) $this->option('force'));
            if (isset($r['payload'])) {
                Storage::put("publish-preview/{$product->id}.json", json_encode($r['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
            $r['ok'] ? $done[$r['action']]++ : $done['failed']++;
            $this->line(sprintf('%-9d %-8s %s %s', $product->id, $r['action'], $r['ok'] ? 'ok' : 'FAILED', $r['message'] ?? ''));
        }
        $this->info(collect($done)->filter()->map(fn ($n, $k) => "{$n} {$k}")->implode(', ') ?: 'Nothing to publish.');

        return $done['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
