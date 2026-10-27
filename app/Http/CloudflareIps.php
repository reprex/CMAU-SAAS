<?php

namespace App\Http;

/**
 * saas.replicat.com.au is proxied by Cloudflare. Only Cloudflare's edge may set
 * X-Forwarded-For; trusting every proxy ('*') would let anyone who reaches the
 * origin server directly forge their IP and get past ADMIN_ALLOWED_IPS and the
 * per-IP rate limits.
 *
 * Published list: https://www.cloudflare.com/ips/ (checked 27 Oct 2026; it rarely
 * changes). Middleware is set up before .env and config load, so the list lives
 * in code: update it here and release if Cloudflare publishes new ranges.
 */
final class CloudflareIps
{
    public const RANGES = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /** @return list<string> */
    public static function trusted(): array
    {
        return self::RANGES;
    }
}
