<?php

namespace App\Support;

use RuntimeException;

/**
 * Refuses to run the app in production on a PHP version older than the
 * configured minimum. PHP 8.1 and earlier never received fixes for the
 * October 2026 critical RCE flaws (CVE-2026-109618 / CVE-2026-109619).
 */
class PhpVersionGuard
{
    public static function check(string $minimum, string $current = PHP_VERSION): void
    {
        if (! self::isSupported($minimum, $current)) {
            throw new RuntimeException(sprintf(
                'CMAU SaaS requires PHP %s or newer; this server is running PHP %s. '
                .'Set the domain to PHP 8.4 (ea-php84) in cPanel > MultiPHP Manager.',
                $minimum,
                $current,
            ));
        }
    }

    public static function isSupported(string $minimum, string $current = PHP_VERSION): bool
    {
        return version_compare($current, $minimum, '>=');
    }
}
