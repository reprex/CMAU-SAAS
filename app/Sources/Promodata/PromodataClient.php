<?php

namespace App\Sources\Promodata;

use DateTimeInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin HTTP client for the Promodata API. Mirrors the calls TOD's frmApi made:
 * list pages, "changed since" pages, and single products.
 */
class PromodataClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly int $timeout = 60,
    ) {}

    /** @return array<string, mixed> */
    public function listProducts(int $page, ?DateTimeInterface $since = null, ?int $supplierId = null): array
    {
        $query = ['page' => $page];
        if ($supplierId !== null) {
            $query['supplier_id'] = $supplierId;
        }

        if ($since === null) {
            return $this->send('get', '/products', $query);
        }

        $query = ['since' => $since->format('Y-m-d H:i')] + $query;

        return $this->send('post', '/products/changed', $query);
    }

    /** @return array<string, mixed>|null */
    public function getProduct(int $productId): ?array
    {
        try {
            return $this->send('get', '/products/'.$productId);
        } catch (RequestException $e) {
            if ($e->response->status() === 404) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $query = []): array
    {
        if ($this->token === '') {
            throw new RuntimeException('PROMODATA_TOKEN is not set in .env.');
        }

        $url = $this->baseUrl.$path.($query ? '?'.http_build_query($query) : '');

        $response = $this->request()->{$method}($url)->throw();

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException("Promodata returned a non-JSON response for {$path}.");
        }

        return $json;
    }

    private function request(): PendingRequest
    {
        return Http::withHeaders(['x-auth-token' => $this->token])
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            ->retry(3, 2000, function ($exception) {
                // Retry on network errors, rate limits and server errors only.
                if (! $exception instanceof RequestException) {
                    return true;
                }

                $status = $exception->response->status();

                return $status === 429 || $status >= 500;
            }, throw: true);
    }
}
