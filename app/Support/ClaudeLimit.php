<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Claude's API refusing for a while (Scott's monthly spend limit, no credit, rate limits, overloaded): every
 * Claude job pauses for an hour and tries again, instead of marking its work failed (6 Nov 2026: the spend limit
 * turned 2,683 templates "failed" and 7,962 photos "could not load" overnight).
 */
class ClaudeLimit
{
    private const KEY = 'claude:paused_until';

    private const PATTERN = '/usage limit|credit balance|rate limit|overloaded|quota|billing|regain access/i';

    /** Is this error a limit (pause and retry), not a problem with the item itself? */
    public static function is(int $status, string $message): bool
    {
        return in_array($status, [429, 529, 503], true) || (in_array($status, [400, 402, 403], true) && preg_match(self::PATTERN, $message));
    }

    public static function hit(string $message, int $minutes = 60): void
    {
        Cache::put(self::KEY, ['until' => now()->addMinutes($minutes)->toIso8601String(), 'why' => mb_substr($message, 0, 200)], now()->addMinutes($minutes));
    }

    public static function paused(): bool
    {
        return Cache::has(self::KEY);
    }

    /** @return array{until: string, why: string}|null */
    public static function pause(): ?array
    {
        return Cache::get(self::KEY);
    }
}
