<?php

namespace App\Stock;

use App\Catalogue\SupplierCodes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Live stock from Promodata: POST /products/{id}/check-stock-levels.
 * The reply is { data: { stock: [[colour, size, qty, supplierSKU], ...] } }
 * or a message such as "Queried the supplier but they returned no information".
 * Each product's reply is cached for a few minutes, for both the quote desk
 * and the public relay the CMAU product page uses.
 */
class PromodataStock
{
    /** @return array{status: int, body: string} Promodata's reply, unchanged. */
    public function raw(int $productId): array
    {
        $key = "stock:{$productId}";
        if ($hit = Cache::get($key)) {
            return $hit + ['cached' => true];
        }

        $token = (string) config('promodata.token');
        if ($token === '') {
            return ['status' => 503, 'body' => json_encode(['message' => 'Stock checks are not set up yet']), 'cached' => false];
        }

        try {
            $response = Http::withHeaders(['X-Auth-Token' => $token])
                ->acceptJson()
                ->timeout((int) config('quotes.stock_timeout'))
                ->connectTimeout(5)
                ->post(config('promodata.base_url')."/products/{$productId}/check-stock-levels");
        } catch (Throwable $e) {
            report($e);

            return ['status' => 502, 'body' => json_encode(['message' => 'Stock check is unavailable right now']), 'cached' => false];
        }

        $result = ['status' => $response->status(), 'body' => $response->body()];
        if ($response->successful() && is_array($response->json())) {
            Cache::put($key, $result, (int) config('quotes.stock_cache_seconds'));
            // Keep the supplier SKUs for the product page (docs/supplier-codes.md).
            try {
                $rows = $this->parse($response->json());
                if ($rows !== []) {
                    app(SupplierCodes::class)->record($productId, $rows);
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $result + ['cached' => false];
    }

    /**
     * Stock rows for the quote desk.
     *
     * @return array{ok: bool, message: string|null, rows: list<array{colour: string|null, size: string|null, qty: int|null, sku: string|null}>}
     */
    public function rows(int $productId): array
    {
        $raw = $this->raw($productId);
        $json = json_decode($raw['body'], true);

        if ($raw['status'] !== 200 || ! is_array($json)) {
            return ['ok' => false, 'message' => $json['message'] ?? 'Stock check is unavailable right now', 'rows' => []];
        }

        $rows = $this->parse($json);

        $message = $rows === [] ? ($json['message'] ?? $json['data']['message'] ?? 'The supplier returned no stock information') : null;

        return ['ok' => $rows !== [], 'message' => is_string($message) ? $message : null, 'rows' => $rows];
    }

    /** @return list<array{colour: ?string, size: ?string, qty: ?int, sku: ?string}> */
    private function parse(array $json): array
    {
        $rows = [];
        foreach ((array) ($json['data']['stock'] ?? []) as $row) {
            $row = array_values((array) $row);
            $rows[] = [
                'colour' => $this->text($row[0] ?? null),
                'size' => $this->text($row[1] ?? null),
                'qty' => is_numeric($row[2] ?? null) ? (int) $row[2] : null,
                'sku' => $this->text($row[3] ?? null),
            ];
        }

        return $rows;
    }

    private function text(mixed $v): ?string
    {
        if ($v === null || is_array($v)) {
            return null;
        }
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }
}
