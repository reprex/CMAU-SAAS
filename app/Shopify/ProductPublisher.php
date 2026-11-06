<?php

namespace App\Shopify;

use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Publishes products to the CMAU Shopify store (docs/publisher.md) with the
 * GraphQL productSet mutation: product, colour variants, images and metafields
 * in one call per product. Locked to the development store unless
 * SHOPIFY_ALLOW_LIVE is set (ShopifyAdmin::assertWritable).
 *
 * - Only products whose payload changed since the last publish are sent.
 * - Images: existing Shopify media are referenced by id (never re-uploaded);
 *   new images are uploaded from their public URL. A first publish takes two
 *   calls: upload the images, then link each colour variant to its image.
 * - Products off the range or discontinued are archived, not deleted.
 * - Every attempt is logged in shopify_publish_log with Shopify's error text.
 */
class ProductPublisher
{
    private const MUTATION = <<<'GQL'
    mutation productSet($input: ProductSetInput!, $identifier: ProductSetIdentifiers) {
      productSet(input: $input, identifier: $identifier, synchronous: true) {
        product { id media(first: 250) { nodes { id } } }
        userErrors { field message code }
      }
    }
    GQL;

    /** The same call without the picture list: for updates that upload no new pictures. Asking for
     * media(first: 250) makes Shopify reserve ~260 points of the API allowance per call (refunded
     * afterwards), which would throttle several calls at once on the Basic plan's 100 points a second. */
    private const MUTATION_LITE = <<<'GQL'
    mutation productSet($input: ProductSetInput!, $identifier: ProductSetIdentifiers) {
      productSet(input: $input, identifier: $identifier, synchronous: true) {
        product { id }
        userErrors { field message code }
      }
    }
    GQL;

    public function __construct(private readonly ShopifyAdmin $shopify, private readonly ProductPayload $payload) {}

    /** @return array{action: string, ok: bool, message: ?string, payload?: array} */
    public function publish(Product $product, bool $preview = false, bool $force = false, ?int $userId = null, bool $retried = false): array
    {
        $p = $this->prepare($product, $preview, $force, $userId);
        if (isset($p['result'])) {
            return $p['result'];
        }
        ['built' => $built, 'hash' => $hash, 'action' => $action] = $p;

        try {
            $this->shopify->assertWritable();
            // Pass 1: everything, with variant images only where the image already exists in Shopify.
            $first = $built['input'];
            $pending = false;
            foreach ($first['variants'] as &$v) {
                if (isset($v['file']['originalSource'])) {
                    unset($v['file']);
                    $pending = true;
                }
            }
            unset($v);
            $productGid = $this->send($first, $product->shopify_product_id, $this->uploads($built) ? $built['files'] : null, $product);

            // Pass 2: new images now have ids, so link them to their colour variants.
            if ($pending) {
                $media = DB::table('shopify_media')->where('product_id', $product->id)->pluck('media_gid', 'url')->all();
                $this->send($this->payload->build($product, $media)['input'], $this->numericId($productGid), null, $product);
            }

            // productSet does not put a product on any sales channel: without this the
            // storefront and its collections never show it.
            $channel = $action === 'archive' ? null : $this->showOnOnlineStore($productGid);

            return $this->done($product, $action, $built, $productGid, $channel, $userId);
        } catch (Throwable $e) {
            // A picture Shopify couldn't fetch earlier stays in the FAILED state and can't be reused:
            // forget it, keep that picture off the website (staff see it on Range › product), and try once more.
            if (! $retried && preg_match_all('/File with id (\d+) is in the FAILED state/i', $e->getMessage(), $m)) {
                $bad = DB::table('shopify_media')->where('product_id', $product->id)
                    ->where(fn ($w) => collect($m[1])->each(fn ($id) => $w->orWhere('media_gid', 'like', '%/'.$id)))->get();
                if ($bad->isNotEmpty()) {
                    DB::table('shopify_media')->whereIn('id', $bad->pluck('id'))->delete();
                    $product->images()->whereIn('url', $bad->pluck('url'))->update(['hide_web' => true]);
                    $result = $this->publish($product->fresh(), false, true, $userId, true);
                    if ($result['ok']) {
                        $result['message'] = trim(($result['message'] ?? '').'; left out '.$bad->count().' '.($bad->count() === 1 ? 'picture' : 'pictures').' Shopify could not load');
                    }

                    return $result;
                }
            }

            return $this->log($product, $action, false, mb_substr($e->getMessage(), 0, 2000), $hash, $userId);
        }
    }

    /**
     * Publish several products, sending the plain updates (no new pictures, already on Shopify) to
     * Shopify $parallel at a time. New products and products with new pictures take the usual
     * one-by-one path (upload, then link pictures to colours).
     *
     * @param  iterable<Product>  $products
     * @param  callable(Product, array): void|null  $each  called with each result as it is known
     * @return array<int, array{action: string, ok: bool, message: ?string, payload?: array}> by product id
     */
    public function publishMany(iterable $products, int $parallel = 5, bool $preview = false, bool $force = false, ?int $userId = null, ?callable $each = null): array
    {
        $results = [];
        $report = function (Product $product, array $r) use (&$results, $each) {
            $results[$product->id] = $r;
            if ($each) {
                $each($product, $r);
            }
        };
        $batch = [];
        foreach ($products as $product) {
            $p = $this->prepare($product, $preview, $force, $userId);
            if (isset($p['result'])) {
                $report($product, $p['result']);
            } elseif ($p['action'] === 'create' || $this->uploads($p['built'])) {
                $report($product, $this->publish($product, false, $force, $userId));
            } else {
                $batch[$product->id] = [$product, $p];
            }
        }
        if ($batch === []) {
            return $results;
        }
        try {
            $this->shopify->assertWritable();
        } catch (Throwable $e) {
            foreach ($batch as [$product, $p]) {
                $report($product, $this->log($product, $p['action'], false, mb_substr($e->getMessage(), 0, 2000), $p['hash'], $userId));
            }

            return $results;
        }
        foreach (array_chunk($batch, max(1, $parallel), true) as $chunk) {
            $vars = array_map(fn ($item) => ['input' => $item[1]['built']['input'], 'identifier' => ['id' => 'gid://shopify/Product/'.$item[0]->shopify_product_id]], $chunk);
            foreach ($this->shopify->graphqlMany(self::MUTATION_LITE, $vars) as $id => $data) {
                [$product, $p] = $chunk[$id];
                try {
                    if ($data instanceof Throwable) {
                        throw $data;
                    }
                    $report($product, $this->done($product, $p['action'], $p['built'], $this->result($data, null, $product), null, $userId));
                } catch (Throwable $e) {
                    // A picture Shopify couldn't load: the one-by-one path forgets it and tries again.
                    $report($product, preg_match('/is in the FAILED state/i', $e->getMessage())
                        ? $this->publish($product, false, true, $userId)
                        : $this->log($product, $p['action'], false, mb_substr($e->getMessage(), 0, 2000), $p['hash'], $userId));
                }
            }
        }

        return $results;
    }

    /**
     * What publishing this product needs: the payload, its hash and the action, or the final
     * result when nothing is sent (preview, unchanged, off the range and never created).
     *
     * @return array{result?: array, built?: array, hash?: string, action?: string}
     */
    private function prepare(Product $product, bool $preview, bool $force, ?int $userId): array
    {
        if (! $preview && ! $product->shopify_product_id && StoreBinding::adopting()) {
            $this->adopt($product, $userId);
        }
        $media = DB::table('shopify_media')->where('product_id', $product->id)->pluck('media_gid', 'url')->all();
        $built = $this->payload->build($product, $media);
        $hash = sha1(json_encode($built['input']));
        $action = $product->shopify_product_id ? ($built['input']['status'] === 'ARCHIVED' ? 'archive' : 'update') : 'create';

        if ($preview) {
            return ['result' => ['action' => 'preview', 'ok' => true, 'message' => $action.' (not sent)', 'payload' => $built['input']]];
        }
        if (! $force && $product->publish_hash === $hash) {
            // Flagged as changed, but nothing Shopify sees differs: mark it in sync so the
            // queue moves on instead of picking the same products every minute.
            if ($product->exists && $product->shopify_product_id) {
                DB::table('products')->where('id', $product->id)->update(['published_at' => now()]);
            }

            return ['result' => ['action' => 'skip', 'ok' => true, 'message' => 'unchanged']];
        }
        if ($action === 'create' && $built['input']['status'] === 'ARCHIVED') {
            return ['result' => $this->log($product, 'skip', true, 'not on the range: nothing to create', $hash, $userId)];
        }

        return ['built' => $built, 'hash' => $hash, 'action' => $action];
    }

    /**
     * After switching to a store that already has these products (the live store, filled by TOD's
     * Matrixify imports; docs/publisher.md): find the product by its handle (APPAID) and take over
     * its id and pictures, so the first publish updates it instead of re-uploading every picture.
     * Its pictures are matched to the SaaS's by file name; any left over are kept (merged, never
     * replaced). Read-only on Shopify.
     */
    public function adopt(Product $product, ?int $userId = null): bool
    {
        try {
            $q = 'query($q: String!) { products(first: 3, query: $q) { nodes { id handle status media(first: 250) { nodes { id ... on MediaImage { image { url } } } } } } }';
            $nodes = $this->shopify->graphql($q, ['q' => 'handle:'.$product->id])['products']['nodes'] ?? [];
        } catch (Throwable $e) {
            $this->log($product, 'adopt', false, 'lookup by handle failed: '.mb_substr($e->getMessage(), 0, 500), null, $userId);

            return false;
        }
        $node = collect($nodes)->first(fn ($n) => (string) ($n['handle'] ?? '') === (string) $product->id);
        if (! $node) {
            return false; // not on the store: the publish creates it
        }
        $live = [];
        foreach ($node['media']['nodes'] ?? [] as $m) {
            if (! empty($m['id']) && ! empty($m['image']['url'])) {
                $live[] = ['id' => $m['id'], 'url' => (string) $m['image']['url'], 'key' => self::fileKey((string) $m['image']['url'])];
            }
        }
        $built = $this->payload->build($product, []);
        $ours = $built['files'];
        foreach ($built['input']['variants'] ?? [] as $v) {
            if (isset($v['file']['originalSource'])) {
                $ours[] = $v['file']['originalSource'];
            }
        }
        $used = [];
        $rows = [];
        foreach (array_unique($ours) as $url) {
            $key = self::fileKey($url);
            foreach ($live as $i => $l) {
                if (! isset($used[$i]) && $key !== '' && $l['key'] === $key) {
                    $used[$i] = true;
                    $rows[] = ['product_id' => $product->id, 'url_hash' => sha1($url), 'url' => $url, 'media_gid' => $l['id']];

                    break;
                }
            }
        }
        foreach ($live as $i => $l) { // pictures only the store has (staff added them): kept
            if (! isset($used[$i])) {
                $rows[] = ['product_id' => $product->id, 'url_hash' => sha1($l['url']), 'url' => mb_substr($l['url'], 0, 1024), 'media_gid' => $l['id']];
            }
        }
        DB::table('shopify_media')->where('product_id', $product->id)->delete();
        foreach ($rows as $r) {
            DB::table('shopify_media')->insert($r + ['created_at' => now(), 'updated_at' => now()]);
        }
        $product->forceFill(['shopify_product_id' => $this->numericId((string) $node['id'])]);
        DB::table('products')->where('id', $product->id)->update(['shopify_product_id' => $product->shopify_product_id]);
        $this->log($product, 'adopt', true, 'found by handle ('.strtolower((string) ($node['status'] ?? '')).'); '.count($used).' of '.count($live).' pictures matched, '.(count($live) - count($used)).' kept', null, $userId);

        return true;
    }

    /** "https://cdn.shopify.com/s/files/1/…/LL0007_White_3f2a…-….jpg?v=1" and "https://res.cloudinary.com/…/LL0007_White.jpg" -> "ll0007_white" */
    public static function fileKey(string $url): string
    {
        $name = mb_strtolower(rawurldecode(basename((string) parse_url($url, PHP_URL_PATH))));
        $name = preg_replace('/\.(jpe?g|png|gif|webp|avif)$/', '', $name);
        $name = preg_replace('/_[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', '', $name); // Shopify's suffix on a repeated file name

        return (string) $name;
    }

    /** Does this payload upload any picture Shopify doesn't have yet? */
    private function uploads(array $built): bool
    {
        foreach ($built['input']['files'] ?? [] as $f) {
            if (isset($f['originalSource'])) {
                return true;
            }
        }
        foreach ($built['input']['variants'] ?? [] as $v) {
            if (isset($v['file']['originalSource'])) {
                return true;
            }
        }

        return false;
    }

    private function done(Product $product, string $action, array $built, string $productGid, ?string $channel, ?int $userId): array
    {
        // A run that started before shopify:switch-store must not write the old store's id back.
        app(StoreBinding::class)->assertMatches();
        // What an unchanged product will look like next time (images by id), so it is skipped.
        $media = DB::table('shopify_media')->where('product_id', $product->id)->pluck('media_gid', 'url')->all();
        $hash = sha1(json_encode($this->payload->build($product, $media)['input']));
        $product->forceFill([
            'shopify_product_id' => $this->numericId($productGid),
            'publish_hash' => $hash,
            'published_at' => now(),
        ])->save();

        return $this->log($product, $action, true, count($built['input']['variants']).' variants, '.count($built['files']).' images'.($channel ? '; '.$channel : ''), $hash, $userId);
    }

    /** @param list<string>|null $files urls in the order sent, to record the media ids Shopify returns */
    private function send(array $input, ?int $shopifyId, ?array $files, Product $product): string
    {
        $identifier = $shopifyId ? ['id' => 'gid://shopify/Product/'.$shopifyId] : ['handle' => (string) $product->id];
        $data = $this->shopify->graphql($files !== null ? self::MUTATION : self::MUTATION_LITE, ['input' => $input, 'identifier' => $identifier]);

        return $this->result($data, $files, $product);
    }

    /** @param list<string>|null $files */
    private function result(array $data, ?array $files, Product $product): string
    {
        $result = $data['productSet'] ?? [];
        if (! empty($result['userErrors'])) {
            throw new \RuntimeException(collect($result['userErrors'])->map(fn ($e) => implode('.', (array) ($e['field'] ?? [])).': '.($e['message'] ?? ''))->implode('; '));
        }
        $gid = (string) ($result['product']['id'] ?? '');
        if ($gid === '') {
            throw new \RuntimeException('Shopify returned no product id');
        }
        if ($files !== null && app(StoreBinding::class)->matches()) {
            // Media come back in the order the files were sent. A product found by its handle on a
            // store we have no ids for (the live store after shopify:switch-store) may carry older
            // pictures too: those come first, so the ones just sent are the last count($files).
            $nodes = array_values($result['product']['media']['nodes'] ?? []);
            if (count($nodes) > count($files)) {
                $nodes = array_slice($nodes, -count($files));
            }
            foreach ($nodes as $i => $node) {
                if (isset($files[$i], $node['id'])) {
                    DB::table('shopify_media')->updateOrInsert(
                        ['product_id' => $product->id, 'url_hash' => sha1($files[$i])],
                        ['url' => $files[$i], 'media_gid' => $node['id'], 'updated_at' => now(), 'created_at' => now()],
                    );
                }
            }
        }

        return $gid;
    }

    /**
     * Puts the product on the Online Store sales channel. Needs the app scopes
     * read_publications and write_publications; without them the publish still
     * succeeds and the reply says what is missing.
     */
    public function showOnOnlineStore(string $productGid): ?string
    {
        try {
            $publication = Cache::remember('shopify:online-store-publication:'.config('shopify.store'), 86400, function () {
                $nodes = $this->shopify->graphql('query { publications(first: 25) { nodes { id name } } }')['publications']['nodes'] ?? [];
                foreach ($nodes as $n) {
                    if (strcasecmp((string) ($n['name'] ?? ''), 'Online Store') === 0) {
                        return (string) $n['id'];
                    }
                }

                return '';
            });
            if ($publication === '') {
                return 'Online Store channel not found';
            }
            $data = $this->shopify->graphql(
                'mutation publish($id: ID!, $input: [PublicationInput!]!) { publishablePublish(id: $id, input: $input) { userErrors { field message } } }',
                ['id' => $productGid, 'input' => [['publicationId' => $publication]]],
            );
            $errors = $data['publishablePublish']['userErrors'] ?? [];

            return $errors ? 'Online Store: '.collect($errors)->pluck('message')->implode('; ') : 'on Online Store';
        } catch (Throwable $e) {
            return 'not put on Online Store ('.mb_substr($e->getMessage(), 0, 200).'). Add the app scopes read_publications and write_publications.';
        }
    }

    private function numericId(string $gid): int
    {
        return (int) substr($gid, strrpos($gid, '/') + 1);
    }

    private function log(Product $product, string $action, bool $ok, ?string $message, ?string $hash, ?int $userId): array
    {
        DB::table('shopify_publish_log')->insert([
            'product_id' => $product->id, 'store' => (string) config('shopify.store'), 'action' => $action, 'ok' => $ok,
            'message' => $message, 'payload_hash' => $hash, 'user_id' => $userId, 'created_at' => now(),
        ]);

        return ['action' => $action, 'ok' => $ok, 'message' => $message];
    }
}
