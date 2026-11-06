<?php

namespace App\Shopify;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Shopify Admin API (REST for draft orders, which keeps the same JSON shape
 * the webhooks send). Refuses to run against any store other than the
 * development store unless SHOPIFY_ALLOW_LIVE is true.
 *
 * Access: since 1 Jan 2026 Shopify apps come from the Dev Dashboard and get a
 * 24-hour token by the client credentials grant (SHOPIFY_CLIENT_ID and
 * SHOPIFY_CLIENT_SECRET). A fixed SHOPIFY_ADMIN_TOKEN (older custom apps) still
 * works and takes precedence.
 */
class ShopifyAdmin
{
    public function configured(): bool
    {
        return (string) config('shopify.store') !== ''
            && ((string) config('shopify.token') !== ''
                || ((string) config('shopify.client_id') !== '' && (string) config('shopify.client_secret') !== ''));
    }

    /** The Admin API token: the fixed one, or a cached client-credentials token renewed before it expires. */
    public function accessToken(): string
    {
        $fixed = (string) config('shopify.token');
        if ($fixed !== '') {
            return $fixed;
        }

        $store = mb_strtolower((string) config('shopify.store'));
        $key = 'shopify-token:'.$store.':'.config('shopify.client_id');
        $cached = Cache::get($key);
        if (is_string($cached)) {
            return Crypt::decryptString($cached);
        }

        $response = Http::asForm()->acceptJson()->timeout(20)->post("https://{$store}/admin/oauth/access_token", [
            'grant_type' => 'client_credentials',
            'client_id' => (string) config('shopify.client_id'),
            'client_secret' => (string) config('shopify.client_secret'),
        ]);
        $token = (string) $response->json('access_token');
        if (! $response->successful() || $token === '') {
            $error = (string) ($response->json('error') ?? $response->status());
            throw new RuntimeException("Shopify did not issue an access token for {$store} ({$error}). Check the app is installed on that store, in the same organisation, with its client ID and secret in .env.");
        }

        $ttl = max(60, (int) ($response->json('expires_in') ?? 86399) - 600); // renew 10 minutes early
        Cache::put($key, Crypt::encryptString($token), $ttl);

        return $token;
    }

    /** Throws unless the configured store is one this app may write to. */
    public function assertWritable(): void
    {
        $store = mb_strtolower((string) config('shopify.store'));
        $dev = mb_strtolower((string) config('shopify.dev_store'));

        if (! $this->configured()) {
            throw new RuntimeException('Shopify is not set up (SHOPIFY_SHOP_DOMAIN with SHOPIFY_CLIENT_ID and SHOPIFY_CLIENT_SECRET).');
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

    /**
     * Run a GraphQL Admin query or mutation. Writes (mutations) are checked
     * against the store lock first. Returns the `data` part; throws on errors.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function graphql(string $query, array $variables = []): array
    {
        $mutation = str_starts_with(ltrim($query), 'mutation');
        if ($mutation) {
            $this->assertWritable();
            app(StoreBinding::class)->assertMatches();
        } elseif (! $this->configured()) {
            throw new RuntimeException('Shopify is not set up (SHOPIFY_SHOP_DOMAIN with SHOPIFY_CLIENT_ID and SHOPIFY_CLIENT_SECRET).');
        }
        $json = $this->request()->timeout(90)
            ->post('https://'.config('shopify.store').'/admin/api/'.config('shopify.api_version').'/graphql.json', ['query' => $query, 'variables' => (object) $variables])
            ->throw()->json();
        if (! empty($json['errors'])) {
            throw new RuntimeException('Shopify GraphQL error: '.mb_substr(json_encode($json['errors']), 0, 500));
        }
        if ($mutation) {
            app(StoreBinding::class)->bindIfUnset();
        }

        return $json['data'] ?? [];
    }

    /**
     * Run the same GraphQL mutation for several inputs at once (Http::pool), so the publisher isn't
     * waiting on Shopify one product at a time. Calls Shopify throttles (THROTTLED, 429, 5xx) are sent
     * again after a pause, up to three rounds. Returns each key's `data`, or the error for that key.
     *
     * @param  array<string|int, array<string, mixed>>  $variablesByKey
     * @return array<string|int, array<string, mixed>|\Throwable>
     */
    public function graphqlMany(string $query, array $variablesByKey): array
    {
        $this->assertWritable();
        app(StoreBinding::class)->assertMatches();
        $url = 'https://'.config('shopify.store').'/admin/api/'.config('shopify.api_version').'/graphql.json';
        $headers = ['X-Shopify-Access-Token' => $this->accessToken()];
        $out = [];
        $todo = $variablesByKey;
        for ($round = 0; $todo !== [] && $round < 4; $round++) {
            if ($round > 0) {
                sleep($round); // let the API allowance refill
            }
            $responses = Http::pool(fn ($pool) => collect($todo)->map(fn ($vars, $key) => $pool->as((string) $key)
                ->withHeaders($headers)->acceptJson()->asJson()->timeout(90)
                ->post($url, ['query' => $query, 'variables' => (object) $vars]))->values()->all());
            $again = [];
            foreach ($todo as $key => $vars) {
                $r = $responses[(string) $key] ?? null;
                if (! $r instanceof Response) {
                    $out[$key] = $r instanceof \Throwable ? $r : new RuntimeException('Shopify gave no reply');

                    continue;
                }
                $json = $r->json() ?? [];
                $throttled = in_array($r->status(), [429, 500, 502, 503], true)
                    || collect($json['errors'] ?? [])->contains(fn ($e) => ($e['extensions']['code'] ?? '') === 'THROTTLED');
                if ($throttled && $round < 3) {
                    $again[$key] = $vars;
                } elseif (! $r->successful()) {
                    $out[$key] = new RuntimeException('Shopify HTTP '.$r->status().': '.mb_substr($r->body(), 0, 300));
                } elseif (! empty($json['errors'])) {
                    $out[$key] = new RuntimeException('Shopify GraphQL error: '.mb_substr(json_encode($json['errors']), 0, 500));
                } else {
                    $out[$key] = $json['data'] ?? [];
                }
            }
            $todo = $again;
        }
        if (collect($out)->contains(fn ($o) => is_array($o))) {
            app(StoreBinding::class)->bindIfUnset();
        }

        return $out;
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
            throw new RuntimeException('Shopify is not set up (SHOPIFY_SHOP_DOMAIN with SHOPIFY_CLIENT_ID and SHOPIFY_CLIENT_SECRET).');
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
        return Http::withHeaders(['X-Shopify-Access-Token' => $this->accessToken()])
            ->acceptJson()->asJson()->timeout(30)
            ->retry(2, 1500, fn ($e) => $e instanceof RequestException && in_array($e->response->status(), [429, 500, 502, 503], true), throw: false);
    }
}
