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

    public function __construct(private readonly ShopifyAdmin $shopify, private readonly ProductPayload $payload) {}

    /** @return array{action: string, ok: bool, message: ?string, payload?: array} */
    public function publish(Product $product, bool $preview = false, bool $force = false, ?int $userId = null): array
    {
        $media = DB::table('shopify_media')->where('product_id', $product->id)->pluck('media_gid', 'url')->all();
        $built = $this->payload->build($product, $media);
        $hash = sha1(json_encode($built['input']));
        $action = $product->shopify_product_id ? ($built['input']['status'] === 'ARCHIVED' ? 'archive' : 'update') : 'create';

        if ($preview) {
            return ['action' => 'preview', 'ok' => true, 'message' => $action.' (not sent)', 'payload' => $built['input']];
        }
        if (! $force && $product->publish_hash === $hash) {
            return ['action' => 'skip', 'ok' => true, 'message' => 'unchanged'];
        }
        if ($action === 'create' && $built['input']['status'] === 'ARCHIVED') {
            return $this->log($product, 'skip', true, 'not on the range: nothing to create', $hash, $userId);
        }

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
            $productGid = $this->send($first, $product->shopify_product_id, $built['files'], $product);

            // Pass 2: new images now have ids, so link them to their colour variants.
            if ($pending) {
                $media = DB::table('shopify_media')->where('product_id', $product->id)->pluck('media_gid', 'url')->all();
                $this->send($this->payload->build($product, $media)['input'], $this->numericId($productGid), null, $product);
            }

            // What an unchanged product will look like next time (images by id), so it is skipped.
            $media = DB::table('shopify_media')->where('product_id', $product->id)->pluck('media_gid', 'url')->all();
            $hash = sha1(json_encode($this->payload->build($product, $media)['input']));
            // productSet does not put a product on any sales channel: without this the
            // storefront and its collections never show it.
            $channel = $action === 'archive' ? null : $this->showOnOnlineStore($productGid);

            $product->forceFill([
                'shopify_product_id' => $this->numericId($productGid),
                'publish_hash' => $hash,
                'published_at' => now(),
            ])->save();

            return $this->log($product, $action, true, count($built['input']['variants']).' variants, '.count($built['files']).' images'.($channel ? '; '.$channel : ''), $hash, $userId);
        } catch (Throwable $e) {
            return $this->log($product, $action, false, mb_substr($e->getMessage(), 0, 2000), $hash, $userId);
        }
    }

    /** @param list<string>|null $files urls in the order sent, to record the media ids Shopify returns */
    private function send(array $input, ?int $shopifyId, ?array $files, Product $product): string
    {
        $identifier = $shopifyId ? ['id' => 'gid://shopify/Product/'.$shopifyId] : ['handle' => (string) $product->id];
        $data = $this->shopify->graphql(self::MUTATION, ['input' => $input, 'identifier' => $identifier]);
        $result = $data['productSet'] ?? [];
        if (! empty($result['userErrors'])) {
            throw new \RuntimeException(collect($result['userErrors'])->map(fn ($e) => implode('.', (array) ($e['field'] ?? [])).': '.($e['message'] ?? ''))->implode('; '));
        }
        $gid = (string) ($result['product']['id'] ?? '');
        if ($gid === '') {
            throw new \RuntimeException('Shopify returned no product id');
        }
        if ($files !== null) {
            // Media come back in the order the files were sent.
            foreach (array_values($result['product']['media']['nodes'] ?? []) as $i => $node) {
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
