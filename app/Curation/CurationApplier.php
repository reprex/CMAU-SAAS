<?php

namespace App\Curation;

use App\Catalogue\ImageTagger;
use App\Catalogue\PositionResolver;
use App\Catalogue\RangeManager;
use App\Http\Controllers\RangeController;
use App\Models\AuditLog;
use App\Models\CurationRequest;
use App\Models\PositionRule;
use App\Models\Product;
use App\Models\ProductOverride;
use App\Shopify\ProductPayload;
use App\Shopify\ProductPublisher;
use Throwable;

/**
 * Range changes from the website's curation pop-up (docs/website-staff.md). Reads the
 * product's current curation for the pop-up, and applies an approved request the same way
 * Range › product does (overrides, positions, on/off the website), then republishes it.
 */
class CurationApplier
{
    /** Override keys the pop-up may change (a subset of RangeController::OVERRIDE_KEYS). */
    public const OVERRIDE_KEYS = ['title', 'description', 'hero_image_url', 'hidden_colours', 'collection_moq', 'actual_moq'];

    public function __construct(
        private readonly ImageTagger $images,
        private readonly PositionResolver $positions,
        private readonly RangeManager $range,
        private readonly ProductPublisher $publisher,
        private readonly ProductPayload $payload,
    ) {}

    /** What the pop-up shows. */
    public function state(Product $product): array
    {
        $product->loadMissing(['images', 'colours', 'override', 'detail', 'webPrice']);
        $o = $product->override?->data ?? [];
        $hidden = (array) ($o['hidden_colours'] ?? []);
        $tags = $this->images->tags($product);
        $rule = PositionRule::query()->where('product_id', $product->id)->first();
        $typeRule = $product->promodata_type_name ? PositionRule::query()->where('product_type', $product->promodata_type_name)->first() : null;

        return [
            'id' => $product->id,
            'name' => $product->name,
            'title' => $o['title'] ?? null,
            'shown_title' => $this->payload->title($product),
            'description' => $o['description'] ?? null,
            'supplier_description' => (string) ($product->detail?->description ?? ''),
            'on_website' => $product->tier === Product::TIER_LIVE,
            'type' => $product->promodata_type_name,
            'images' => $tags->map(fn ($t) => ['url' => $t['url'], 'role' => $t['role'], 'colour' => $t['colour'], 'white_bg' => (bool) optional($product->images->firstWhere('url', $t['url']))->white_bg])->values()->all(),
            'hero_override' => $o['hero_image_url'] ?? null,
            'colours' => $product->colours->unique('name')->map(fn ($c) => ['name' => $c->name, 'image' => $c->image_url, 'hidden' => in_array($c->name, $hidden, true)])->values()->all(),
            'collection_moq' => $o['collection_moq'] ?? null,
            'actual_moq' => $o['actual_moq'] ?? null,
            'moq_now' => ['display' => (int) ($product->webPrice?->collection_moq ?: 0), 'production' => (int) ($product->webPrice?->actual_moq ?: 0)],
            'positions' => array_map(fn ($p) => ['name' => $p['name'] ?? '', 'view' => $p['view'] ?? 'front', 'width_mm' => $p['max_mm'][0] ?? null, 'height_mm' => $p['max_mm'][1] ?? null, 'source' => $p['source'] ?? null], $this->positions->forShopify($product)),
            'position_rule' => $rule?->positions,
            'type_rule' => $typeRule ? ['type' => $typeRule->product_type, 'positions' => $typeRule->positions] : null,
            'pending' => CurationRequest::query()->with('user')->where('product_id', $product->id)->whereIn('status', ['pending', 'approved', 'failed'])->latest('id')->limit(10)->get()
                ->map(fn ($r) => ['id' => $r->id, 'status' => $r->status, 'status_text' => CurationRequest::STATUSES[$r->status] ?? $r->status, 'by' => $r->user?->name, 'at' => $r->created_at->toIso8601String(), 'summary' => $r->summary(), 'note' => $r->note])->all(),
        ];
    }

    /** Applies an approved request and republishes the product. */
    public function apply(CurationRequest $request): CurationRequest
    {
        $product = Product::query()->find($request->product_id);
        if (! $product) {
            $request->update(['status' => 'failed', 'error' => 'Product not found']);

            return $request;
        }
        $changes = (array) $request->edits;
        try {
            $current = $product->override?->data ?? [];
            $next = $current;
            foreach (self::OVERRIDE_KEYS as $key) {
                if (! array_key_exists($key, $changes)) {
                    continue;
                }
                $v = $changes[$key];
                if ($v === null || $v === '' || $v === []) {
                    unset($next[$key]);
                } else {
                    $next[$key] = is_string($v) ? trim($v) : $v;
                }
            }
            if ($next !== $current) {
                if ($next === []) {
                    ProductOverride::query()->whereKey($product->id)->delete();
                } else {
                    ProductOverride::query()->updateOrCreate(['product_id' => $product->id], ['data' => $next, 'updated_by' => $request->decided_by ?? $request->user_id]);
                }
                $product->unsetRelation('override');
                AuditLog::record('range.curated', $product, ['before' => $current, 'after' => $next, 'curation_request' => $request->id]);
            }
            if (array_key_exists('positions', $changes)) {
                $rows = array_values(array_filter((array) $changes['positions'], fn ($p) => trim((string) ($p['name'] ?? '')) !== ''));
                if ($rows === []) {
                    PositionRule::query()->where('product_id', $product->id)->delete();
                } else {
                    PositionRule::query()->updateOrCreate(['product_id' => $product->id], ['positions' => $rows, 'user_id' => $request->decided_by ?? $request->user_id]);
                }
                AuditLog::record('positions.saved', $product, ['product_id' => $product->id, 'positions' => $rows, 'curation_request' => $request->id]);
            }
            if (array_key_exists('on_website', $changes)) {
                $product = $changes['on_website'] ? $this->range->add($product) : $this->range->remove($product);
            }
            $this->images->apply($product);
            $this->range->reprice($product);
            $product->forceFill(['range_changed_at' => now()])->save();

            $result = $this->publisher->publish($product->fresh(), userId: $request->decided_by ?? $request->user_id);
            $request->update([
                'status' => $result['ok'] ? 'applied' : 'failed',
                'applied_at' => now(),
                'publish_result' => mb_substr(($result['action'] ?? '').': '.($result['message'] ?? 'ok'), 0, 300),
                'error' => $result['ok'] ? null : mb_substr((string) ($result['message'] ?? 'Publish failed'), 0, 500),
            ]);
        } catch (Throwable $e) {
            report($e);
            $request->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
        }

        return $request;
    }
}
