<?php

namespace App\Tod;

use Generator;
use RuntimeException;

/**
 * Reads the tab-separated files written by TOD's ExportPricingTables VBA
 * (UTF-8, header row, tabs replaced, CR and LF escaped as \r and \n).
 */
final class TsvReader
{
    /** @return Generator<int, array<string, string>> */
    public static function rows(string $path): Generator
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open {$path}");
        }

        try {
            $header = null;
            while (($line = fgets($handle)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($header === null) {
                    $header = explode("\t", preg_replace('/^\xEF\xBB\xBF/', '', $line));

                    continue;
                }
                if ($line === '') {
                    continue;
                }
                $values = array_map(self::unescape(...), explode("\t", $line));
                $values = array_pad(array_slice($values, 0, count($header)), count($header), '');

                yield array_combine($header, $values);
            }
        } finally {
            fclose($handle);
        }
    }

    private static function unescape(string $value): string
    {
        return str_replace(['\r', '\n'], ["\r", "\n"], $value);
    }

    /** "1"/"0" (VBA export) or "True"/"False" to bool. */
    public static function bool(?string $value): bool
    {
        return in_array(strtolower((string) $value), ['1', '-1', 'true', 'yes'], true);
    }

    public static function intOrNull(?string $value): ?int
    {
        return ($value === null || trim($value) === '') ? null : (int) $value;
    }

    public static function floatOrNull(?string $value): ?float
    {
        return ($value === null || trim($value) === '') ? null : (float) $value;
    }
}
