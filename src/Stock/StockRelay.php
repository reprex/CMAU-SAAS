<?php
declare(strict_types=1);

namespace Cmau\Stock;

/**
 * Relays the PDP's "Check stock" request to Promodata so the Promodata token
 * stays on the server instead of in the theme's public JavaScript.
 *
 * - Accepts only a numeric product id (the Shopify handle / APPAID).
 * - Answers only the store origins listed in config (CORS).
 * - Caches each product's answer for a few minutes and limits each visitor
 *   to a number of uncached look-ups, so the relay cannot be used to hammer
 *   Promodata or the suppliers behind it.
 * - Returns Promodata's JSON unchanged, so the theme code reading
 *   response.data.stock keeps working.
 */
final class StockRelay
{
    /** @param array{token:string, base_url?:string, allowed_origins:list<string>, cache_dir:string, cache_seconds?:int, per_ip_limit?:int, per_ip_window?:int, timeout?:int} $cfg */
    public function __construct(private readonly array $cfg) {}

    /**
     * @param array<string,string> $query  $_GET
     * @param array<string,string> $server $_SERVER
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    public function handle(array $query, array $server, ?callable $fetch = null): array
    {
        $headers = ['Content-Type' => 'application/json; charset=utf-8', 'X-Content-Type-Options' => 'nosniff', 'Vary' => 'Origin'];

        $origin = $server['HTTP_ORIGIN'] ?? '';
        if ($origin !== '') {
            if (!in_array($origin, $this->cfg['allowed_origins'], true)) {
                return $this->error(403, 'Origin not allowed', $headers);
            }
            $headers['Access-Control-Allow-Origin'] = $origin;
            $headers['Access-Control-Allow-Methods'] = 'GET, POST, OPTIONS';
            $headers['Access-Control-Max-Age'] = '86400';
        }

        $method = strtoupper($server['REQUEST_METHOD'] ?? 'GET');
        if ($method === 'OPTIONS') {
            return ['status' => 204, 'headers' => $headers, 'body' => ''];
        }
        if ($method !== 'GET' && $method !== 'POST') {
            return $this->error(405, 'Method not allowed', $headers);
        }

        $id = (string) ($query['id'] ?? '');
        if (!preg_match('/^[1-9][0-9]{0,9}$/', $id)) {
            return $this->error(400, 'Invalid product id', $headers);
        }

        $ttl = (int) ($this->cfg['cache_seconds'] ?? 300);
        $cacheFile = $this->dir('products') . "/{$id}.json";
        if (is_file($cacheFile) && (time() - (int) filemtime($cacheFile)) < $ttl) {
            $headers['Cache-Control'] = 'public, max-age=60';
            $headers['X-Stock-Cache'] = 'hit';
            return ['status' => 200, 'headers' => $headers, 'body' => (string) file_get_contents($cacheFile)];
        }

        if (!$this->allowVisitor($server['REMOTE_ADDR'] ?? 'unknown')) {
            $headers['Retry-After'] = (string) ($this->cfg['per_ip_window'] ?? 300);
            return $this->error(429, 'Too many stock checks, please try again shortly', $headers);
        }

        $fetch ??= $this->fetchFromPromodata(...);
        [$status, $body] = $fetch($id);
        if ($status !== 200 || json_decode($body) === null) {
            return $this->error(502, 'Stock check is unavailable right now', $headers);
        }

        $tmp = $cacheFile . '.' . bin2hex(random_bytes(4));
        file_put_contents($tmp, $body);
        rename($tmp, $cacheFile);

        $headers['Cache-Control'] = 'public, max-age=60';
        $headers['X-Stock-Cache'] = 'miss';
        return ['status' => 200, 'headers' => $headers, 'body' => $body];
    }

    /** @return array{0:int, 1:string} */
    private function fetchFromPromodata(string $id): array
    {
        $base = rtrim($this->cfg['base_url'] ?? 'https://api.promodata.com.au', '/');
        $ch = curl_init("{$base}/products/{$id}/check-stock-levels");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '',
            CURLOPT_HTTPHEADER => ['X-Auth-Token: ' . $this->cfg['token'], 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) ($this->cfg['timeout'] ?? 20),
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$status, is_string($body) ? $body : ''];
    }

    /** Fixed-window count of uncached look-ups per visitor IP (hashed, never stored raw). */
    private function allowVisitor(string $ip): bool
    {
        $limit = (int) ($this->cfg['per_ip_limit'] ?? 30);
        $window = (int) ($this->cfg['per_ip_window'] ?? 300);
        $slot = intdiv(time(), $window);
        $file = $this->dir('visitors') . '/' . hash('sha256', $ip . '|' . $slot) . '.n';

        $fh = fopen($file, 'c+');
        if ($fh === false) {
            return true;
        }
        flock($fh, LOCK_EX);
        $count = (int) stream_get_contents($fh) + 1;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, (string) $count);
        flock($fh, LOCK_UN);
        fclose($fh);

        if (random_int(1, 50) === 1) {
            $this->pruneVisitors($window);
        }

        return $count <= $limit;
    }

    private function pruneVisitors(int $window): void
    {
        foreach (glob($this->dir('visitors') . '/*.n') ?: [] as $f) {
            if (time() - (int) filemtime($f) > 2 * $window) {
                @unlink($f);
            }
        }
    }

    private function dir(string $name): string
    {
        $dir = rtrim($this->cfg['cache_dir'], '/') . '/' . $name;
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        return $dir;
    }

    /** @param array<string,string> $headers @return array{status:int, headers:array<string,string>, body:string} */
    private function error(int $status, string $message, array $headers): array
    {
        return ['status' => $status, 'headers' => $headers, 'body' => (string) json_encode(['error' => $message])];
    }
}
