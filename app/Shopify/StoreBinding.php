<?php

namespace App\Shopify;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Which Shopify store the SaaS's stored ids belong to (docs/publisher.md, "Switching to the live
 * store"). Each product's Shopify id, its pictures' media ids and the matched collections are ids
 * of ONE store. Pointing .env at another store without moving them would send CMAU_DEV's ids to
 * the live store, so every Shopify write is refused until `shopify:switch-store --apply` has run.
 *
 * The switch never calls Shopify. It archives the old store's ids, clears them (so the publisher
 * finds each product on the new store by its handle, APPAID, and updates it in place), restores
 * any ids archived earlier for the new store, and turns automatic publishing off.
 */
class StoreBinding
{
    public const KEY = 'shopify.ids_store';

    /** Set by a switch to a store with no ids kept: products are looked up by handle before their first publish. */
    public const ADOPT = 'shopify.adopt_by_handle';

    public static function adopting(): bool
    {
        return DB::table('app_settings')->where('key', self::ADOPT)->value('value') === '1';
    }

    /** The store .env points at. */
    public function configured(): string
    {
        return mb_strtolower(trim((string) config('shopify.store')));
    }

    /** The store the stored ids belong to; null when none are stored yet. */
    public function bound(): ?string
    {
        $v = DB::table('app_settings')->where('key', self::KEY)->value('value');

        return filled($v) ? (string) $v : null;
    }

    public function matches(): bool
    {
        $bound = $this->bound();

        return $bound === null || $bound === $this->configured();
    }

    /** Throws when the stored ids belong to another store. */
    public function assertMatches(): void
    {
        if (! $this->matches()) {
            throw new RuntimeException("The stored Shopify ids belong to {$this->bound()}, but .env points at {$this->configured()}. Run `shopify:switch-store` to see what will change, then `shopify:switch-store --apply`.");
        }
    }

    /** Called after a successful write: ids now exist for this store. */
    public function bindIfUnset(): void
    {
        if ($this->bound() === null && $this->configured() !== '') {
            $this->set($this->configured());
        }
    }

    /** @return array{products: int, media: int, collections: int} ids stored now */
    public function counts(): array
    {
        return [
            'products' => DB::table('products')->whereNotNull('shopify_product_id')->count(),
            'media' => DB::table('shopify_media')->count(),
            'collections' => DB::table('shopify_collections')->whereNotNull('shopify_gid')->count(),
        ];
    }

    /** @return array{products: int, media: int, collections: int} ids archived for a store */
    public function archived(string $store): array
    {
        $c = DB::table('shopify_store_archive')->where('store', $store)->selectRaw('kind, count(*) n')->groupBy('kind')->pluck('n', 'kind');

        return ['products' => (int) ($c['product'] ?? 0), 'media' => (int) ($c['media'] ?? 0), 'collections' => (int) ($c['collection'] ?? 0)];
    }

    /**
     * Moves the stored ids from the bound store to the configured one.
     *
     * @return array{from: ?string, to: string, archived: array, restored: array}
     */
    public function switchToConfigured(): array
    {
        $to = $this->configured();
        if ($to === '') {
            throw new RuntimeException('SHOPIFY_SHOP_DOMAIN is not set.');
        }
        $from = $this->bound();
        if ($from === $to) {
            throw new RuntimeException("The stored ids already belong to {$to}: nothing to switch.");
        }

        return DB::transaction(function () use ($from, $to) {
            $archived = ['products' => 0, 'media' => 0, 'collections' => 0];
            if ($from !== null) {
                DB::table('shopify_store_archive')->where('store', $from)->delete();
                $archived = $this->archive($from);
            }
            $this->clear();
            $restored = $this->restore($to);
            $this->set($to);
            DB::table('app_settings')->updateOrInsert(['key' => self::ADOPT], ['value' => array_sum($restored) > 0 ? '0' : '1', 'updated_at' => now(), 'created_at' => now()]);
            Cache::forever('shopify:auto_publish', false);
            Cache::forget('shopify:publish_paused_until');
            Cache::forget('range-collections:tags');

            return ['from' => $from, 'to' => $to, 'archived' => $archived, 'restored' => $restored];
        });
    }

    private function archive(string $store): array
    {
        $now = now();
        $n = ['products' => 0, 'media' => 0, 'collections' => 0];
        DB::table('products')->whereNotNull('shopify_product_id')->select(['id', 'shopify_product_id', 'publish_hash', 'published_at'])->orderBy('id')
            ->chunk(500, function ($rows) use ($store, $now, &$n) {
                DB::table('shopify_store_archive')->insert($rows->map(fn ($r) => ['store' => $store, 'kind' => 'product', 'ref' => (string) $r->id,
                    'data' => json_encode(['shopify_product_id' => $r->shopify_product_id, 'publish_hash' => $r->publish_hash, 'published_at' => $r->published_at]), 'created_at' => $now])->all());
                $n['products'] += $rows->count();
            });
        DB::table('shopify_media')->orderBy('id')->chunk(500, function ($rows) use ($store, $now, &$n) {
            DB::table('shopify_store_archive')->insert($rows->map(fn ($r) => ['store' => $store, 'kind' => 'media', 'ref' => $r->product_id.':'.$r->url_hash,
                'data' => json_encode(['product_id' => $r->product_id, 'url_hash' => $r->url_hash, 'url' => $r->url, 'media_gid' => $r->media_gid]), 'created_at' => $now])->all());
            $n['media'] += $rows->count();
        });
        foreach (DB::table('shopify_collections')->get() as $r) {
            DB::table('shopify_store_archive')->insert(['store' => $store, 'kind' => 'collection', 'ref' => $r->product_type,
                'data' => json_encode(['tag' => $r->tag, 'title' => $r->title, 'handle' => $r->handle, 'shopify_gid' => $r->shopify_gid, 'created_by_saas' => (bool) $r->created_by_saas]), 'created_at' => $now]);
            $n['collections']++;
        }

        return $n;
    }

    /** No store ids left: each product is found on the new store by its handle (APPAID) and republished. */
    private function clear(): void
    {
        DB::table('products')->where(fn ($q) => $q->whereNotNull('shopify_product_id')->orWhereNotNull('published_at')->orWhereNotNull('publish_hash'))
            ->update(['shopify_product_id' => null, 'publish_hash' => null, 'published_at' => null]);
        DB::table('shopify_media')->delete();
        // Keep each type's tag (products keep their tags) until `shopify:collections --sync` matches the new store's.
        DB::table('shopify_collections')->update(['shopify_gid' => null, 'handle' => null, 'created_by_saas' => false, 'sorted_at' => null, 'updated_at' => now()]);
    }

    private function restore(string $store): array
    {
        $n = ['products' => 0, 'media' => 0, 'collections' => 0];
        DB::table('shopify_store_archive')->where('store', $store)->orderBy('id')->chunk(500, function ($rows) use (&$n) {
            foreach ($rows as $r) {
                $d = json_decode($r->data, true);
                if ($r->kind === 'product') {
                    $n['products'] += DB::table('products')->where('id', (int) $r->ref)->update($d);
                } elseif ($r->kind === 'media') {
                    DB::table('shopify_media')->insert($d + ['created_at' => now(), 'updated_at' => now()]);
                    $n['media']++;
                } elseif ($r->kind === 'collection') {
                    $n['collections'] += DB::table('shopify_collections')->where('product_type', $r->ref)->update($d + ['updated_at' => now()]);
                }
            }
        });
        DB::table('shopify_store_archive')->where('store', $store)->delete();

        return $n;
    }

    private function set(string $store): void
    {
        DB::table('app_settings')->updateOrInsert(['key' => self::KEY], ['value' => $store, 'updated_at' => now(), 'created_at' => now()]);
    }
}
