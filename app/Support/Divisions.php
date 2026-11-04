<?php

namespace App\Support;

use Illuminate\Support\Str;

/** The three business divisions and the shared modules (config/divisions.php, docs/divisions.md). */
final class Divisions
{
    public const SHARED = 'shared';

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return config('divisions.list');
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function name(string $key): string
    {
        return $key === self::SHARED ? 'Shared' : (self::all()[$key]['name'] ?? $key);
    }

    /** The division (or "shared") a route belongs to. */
    public static function forRoute(?string $name): string
    {
        if ($name !== null) {
            if (Str::is(config('divisions.shared_routes'), $name)) {
                return self::SHARED;
            }
            foreach (self::all() as $key => $d) {
                if ($d['routes'] && Str::is($d['routes'], $name)) {
                    return $key;
                }
            }
        }

        return config('divisions.default');
    }

    /** The access the request is checked against: the route's, else the division being viewed. */
    public static function current(): string
    {
        return app()->bound('division.current') ? app('division.current') : self::viewing();
    }

    /** The division whose menu is showing (a shared screen keeps the one you came from). */
    public static function viewing(): string
    {
        $v = session('division');

        return in_array($v, self::keys(), true) ? $v : config('divisions.default');
    }
}
