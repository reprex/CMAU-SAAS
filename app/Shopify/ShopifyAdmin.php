<?php

namespace App\Shopify;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Shopify Admin API (REST for draft orders, which keeps the same JSON shape
 * the webhooks send). Refuses to run against any store other than the
 * development store unless SHOPIFY_ALLOW_LIVE is true.
 */
class ShopifyAdmin
{
    public function configured(): bool
    {
        return (string) config('shopify.store') !== '' && (string) config('shopify.token') !== '';
    }

    /** Throws unless the configured store is one this app may write to. */
    public function assertWritable(): void
    {
        $store = mb_strtolower((string) config('shopify.store'));
        $dev = mb_strtolower((string) config('shopify.dev_store'));

        if (! $this->configured()) {
            throw new RuntimeException('Shopify is not set up (SHOPIFY_SHOP_DOMAIN / SHOPIFY_ADMIN_TOKEN).');
        }
        if (config('shopify.allow_live')) {
            return;
        }
        if ($dev === '' || $store !== $dev) {
            throw new RuntimeException("Writes to {$store} are blocked: only the development store ({$dev}) is allowed until SHOPIFY_ALLOW_LIVE is turned on.");
        }
    }

    /** @return list<array<string, mixed>> */
    public function draftOrdersUpdatedSince(?string $since, int $limit = 50): array
    {
        $query = ['limit' => $limit, 'status' => 'any'];
        if ($since) {
            $query['updated_at_min'] = $since;
        }

        return $this->get('draft_orders.json', $query)->json('draft_orders') ?? [];
    }

    /** @return array<string, mixed> */
    public function draftOrder(int $id): array
    {
        return $this->get("draft_orders/{$id}.json")->json('draft_order') ?? [];
    }

    /**
     * @param  array<string, mixed>  $draftOrder
     * @return array<string, mixed>
     */
    public function createDraftOrder(array $draftOrder): array
    {
        $this->assertWritable();

        return $this->request()->post($this->url('draft_orders.json'), ['draft_order' => $draftOrder])
            ->throw()->json('draft_order') ?? [];
    }

    /**
     * @param  array<string, mixed>  $draftOrder
     * @return array<string, mixed>
     */
    public function updateDraftOrder(int $id, array $draftOrder): array
    {
        $this->assertWritable();

        return $this->request()->put($this->url("draft_orders/{$id}.json"), ['draft_order' => $draftOrder])
            ->throw()->json('draft_order') ?? [];
    }

    /**
     * Create a draft order through GraphQL, which (unlike REST) can set a line's
     * price on a real variant (priceOverride). Returns the numeric draft order id.
     *
     * @param  array<string, mixed>  $input  DraftOrderInput
     */
    public function createDraftOrderGraphql(array $input): int
    {
        $this->assertWritable();

        $query = <<<'GQL'
        mutation draftOrderCreate($input: DraftOrderInput!) {
          draftOrderCreate(input: $input) {
            draftOrder { id legacyResourceId name invoiceUrl }
            userErrors { field message }
          }
        }
        GQL;

        $json = $this->request()
            ->post('https://'.config('shopify.store').'/admin/api/'.config('shopify.api_version').'/graphql.json', ['query' => $query, 'variables' => ['input' => $input]])
            ->throw()->json();

        $errors = $json['data']['draftOrderCreate']['userErrors'] ?? [];
        if (! empty($json['errors']) || $errors !== []) {
            throw new RuntimeException('Shopify draftOrderCreate failed: '.json_encode($json['errors'] ?? $errors));
        }

        return (int) $json['data']['draftOrderCreate']['draftOrder']['legacyResourceId'];
    }

    /** @return array<string, mixed>|null {id, sku, product_id, title, price} */
    public function variant(int $id): ?array
    {
        $response = $this->get("variants/{$id}.json", [], false);

        return $response->successful() ? $response->json('variant') : null;
    }

    private function get(string $path, array $query = [], bool $throw = true): Response
    {
        if (! $this->configured()) {
            throw new RuntimeException('Shopify is not set up (SHOPIFY_SHOP_DOMAIN / SHOPIFY_ADMIN_TOKEN).');
        }
        $response = $this->request()->get($this->url($path), $query);

        return $throw ? $response->throw() : $response;
    }

    private function url(string $path): string
    {
        return 'https://'.config('shopify.store').'/admin/api/'.config('shopify.api_version').'/'.$path;
    }

    private function request()
    {
        return Http::withHeaders(['X-Shopify-Access-Token' => (string) config('shopify.token')])
            ->acceptJson()->asJson()->timeout(30)
            ->retry(2, 1500, fn ($e) => $e instanceof RequestException && in_array($e->response->status(), [429, 500, 502, 503], true), throw: false);
    }
}
