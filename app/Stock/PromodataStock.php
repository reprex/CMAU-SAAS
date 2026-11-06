<?php

namespace App\Stock;

use App\Catalogue\SupplierCodes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Live stock from Promodata: POST /products/{id}/check-stock-levels.
 * The reply is { data: { stock: [[colour, size, qty, supplierSKU], ...] } }
 * (some suppliers leave the size out: see row())
 * or a message such as "Queried the supplier but they returned no information".
 * Each product's reply is cached for a few minutes, for both the quote desk
 * and the public relay the CMAU product page uses.
 */
class PromodataStock
{
    /**
     * @param  bool  $fresh  ask Promodata even if a recent reply is cached (website checkout)
     * @return array{status: int, body: string} Promodata's reply, unchanged.
     */
    public function raw(int $productId, bool $fresh = false): array
    {
        $key = "stock:{$productId}";
        if (! $fresh && ($hit = Cache::get($key))) {
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
    public function rows(int $productId, bool $fresh = false): array
    {
        $raw = $this->raw($productId, $fresh);
        $json = json_decode($raw['body'], true);

        if ($raw['status'] !== 200 || ! is_array($json)) {
            return ['ok' => false, 'message' => $json['message'] ?? 'Stock check is unavailable right now', 'rows' => []];
        }

        $rows = $this->parse($json);

        $message = $rows === [] ? ($json['message'] ?? $json['data']['message'] ?? 'The supplier returned no stock information') : null;

        return ['ok' => $rows !== [], 'message' => is_string($message) ? $message : null, 'rows' => $rows];
    }

    /**
     * The relay body for the CMAU product page: Promodata's reply unchanged,
     * except rows that are not in the [colour, size, qty, sku] order the theme
     * reads (see row()) are rewritten into it, with a null size. The theme
     * shows a null size as "One size" and orders it as "One size".
     */
    public function relayBody(string $body): string
    {
        $json = json_decode($body, true);
        if (! is_array($json) || ! is_array($json['data']['stock'] ?? null)) {
            return $body;
        }
        $changed = false;
        foreach ($json['data']['stock'] as $i => $row) {
            if (is_array($row) && $this->needsReshape($row)) {
                $r = $this->row($row);
                $json['data']['stock'][$i] = [$r['colour'], $r['size'], $r['qty'], $r['sku']];
                $changed = true;
            }
        }

        return $changed ? (string) json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $body;
    }

    /** @return list<array{colour: ?string, size: ?string, qty: ?int, sku: ?string}> */
    private function parse(array $json): array
    {
        $rows = [];
        foreach ((array) ($json['data']['stock'] ?? []) as $row) {
            $rows[] = $this->row((array) $row);
        }

        return $rows;
    }

    /**
     * One stock row. Promodata's usual row is [colour, size, qty, sku]; a
     * product with no sizes has an empty or null size there. Some suppliers
     * leave the size slot out instead: [colour, qty, sku] or [colour, qty].
     * Read positionally, the quantity landed in the size column and the SKU
     * in the quantity ("Natural 850: ?"). Which layout a row has is decided
     * from its structure (see sizeSlotMissing), so numeric sizes such as
     * shoe "8" or kids "10" in a full row are kept. Rows keyed by name are
     * read by name. A row with no size gets size null, the no-size
     * convention (shown and ordered as "One size").
     *
     * @return array{colour: ?string, size: ?string, qty: ?int, sku: ?string}
     */
    private function row(array $row): array
    {
        if (($keyed = $this->keyed($row)) !== null) {
            return $keyed;
        }
        $v = array_values($row);
        if ($this->sizeSlotMissing($v)) {
            return ['colour' => $this->text($v[0] ?? null), 'size' => null, 'qty' => (int) $v[1], 'sku' => $this->text($v[2] ?? null)];
        }

        return [
            'colour' => $this->text($v[0] ?? null),
            'size' => $this->text($v[1] ?? null),
            'qty' => is_numeric($v[2] ?? null) ? (int) $v[2] : null,
            'sku' => $this->text($v[3] ?? null),
        ];
    }

    /**
     * A positional row with no size slot: the second value is a whole number
     * and there is no quantity after it, only a SKU (text) or nothing:
     * [colour, qty, sku] or [colour, qty]. A full row always has its quantity
     * (or an empty slot) third and the SKU fourth, so [colour, "8", 120, sku]
     * and [colour, "8", null, sku] keep "8" as the size.
     *
     * @param  list<mixed>  $v
     */
    private function sizeSlotMissing(array $v): bool
    {
        $second = $v[1] ?? null;
        $wholeNumber = is_int($second) || (is_string($second) && preg_match('/^\s*\d+\s*$/', $second) === 1);
        if (! $wholeNumber) {
            return false;
        }
        $third = $v[2] ?? null;
        $thirdIsSku = ! array_key_exists(2, $v) || (is_string($third) && trim($third) !== '' && ! is_numeric($third));
        $nothingAfter = $this->text($v[3] ?? null) === null && count($v) <= 4;

        return $thirdIsSku && $nothingAfter;
    }

    /** Whether the relay must rewrite this row for the theme's positional reading. */
    private function needsReshape(array $row): bool
    {
        return $this->keyed($row) !== null || $this->sizeSlotMissing(array_values($row));
    }

    /**
     * A row keyed by name ({"colour": ..., "quantity": ..., "sku": ...}), read
     * by name; a missing size key means no size. Null if the row isn't keyed
     * with names we know.
     *
     * @return array{colour: ?string, size: ?string, qty: ?int, sku: ?string}|null
     */
    private function keyed(array $row): ?array
    {
        if (array_is_list($row)) {
            return null;
        }
        $row = array_change_key_case($row, CASE_LOWER);
        $pick = function (array $names) use ($row) {
            foreach ($names as $n) {
                if (array_key_exists($n, $row)) {
                    return [true, $row[$n]];
                }
            }

            return [false, null];
        };
        [$hasQty, $qty] = $pick(['qty', 'quantity', 'stock', 'available', 'stock_level', 'level']);
        if (! $hasQty) {
            return null;
        }

        return [
            'colour' => $this->text($pick(['colour', 'color', 'colour_name', 'color_name'])[1]),
            'size' => $this->text($pick(['size', 'size_name'])[1]),
            'qty' => is_numeric($qty) ? (int) $qty : null,
            'sku' => $this->text($pick(['sku', 'supplier_sku', 'code'])[1]),
        ];
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
