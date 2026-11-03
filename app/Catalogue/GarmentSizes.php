<?php

namespace App\Catalogue;

use App\Models\Product;

/**
 * The garment's own chest width for the website mockup (Scott, 3 Nov 2026): the size L figure from the
 * supplier's size chart in the Promodata details or description (womens 14, kids 10), as the width laid
 * flat in cm. The theme draws logos to scale against it; without one it uses a typical width for the type.
 *
 * Reads charts written as "Chest (1/2): S 52, M 55, L 58", "Half chest: S-52cm M-55cm L-58cm",
 * "Body width (cm) 50 53 56" with the sizes in a line of their own, a "Size | S | M | L" table, inches,
 * and full chest (circumference) figures, which are halved.
 */
class GarmentSizes
{
    public const SIZE_ORDER = ['4XS', '3XS', '2XS', 'XXS', 'XS', 'S', 'M', 'L', 'XL', '2XL', 'XXL', '3XL', 'XXXL', '4XL', '5XL', '6XL', '7XL', '8XL'];

    private const CHEST = '/(?:1\s*\/\s*2|half|½)?\s*(?:chest|body\s*width|pit\s*to\s*pit|width\s*across|bust)\b|\bwidth\b/i';

    /** @return array{size: string, cm: float}|null */
    public static function forProduct(Product $product): ?array
    {
        $d = $product->detail;
        $texts = [];
        foreach ((array) ($d?->details ?? []) as $x) {
            if (is_array($x) && filled($x['detail'] ?? null)) {
                $texts[] = trim(($x['name'] ?? '').': '.$x['detail']);
            }
        }
        $texts[] = (string) $d?->description;

        $want = self::targetSize((string) ($product->overrideValue('title') ?: $product->name));
        foreach ($texts as $t) {
            $chart = self::chart($t);
            if ($chart && ($pick = self::pick($chart, $want))) {
                return $pick;
            }
        }

        return null;
    }

    /** "L:56" for the custom.mockupgarment metafield. */
    public static function metafield(Product $product): ?string
    {
        $g = self::forProduct($product);

        return $g ? $g['size'].':'.rtrim(rtrim(number_format($g['cm'], 1, '.', ''), '0'), '.') : null;
    }

    public static function targetSize(string $title): string
    {
        return preg_match('/women|ladies|\bwmns?\b/i', $title) ? '14' : (preg_match('/kid|youth|junior|child/i', $title) ? '10' : 'L');
    }

    /**
     * Size => chest width laid flat, in cm.
     *
     * @return array<string, float>|null
     */
    public static function chart(string $text): ?array
    {
        $text = html_entity_decode(strip_tags(str_ireplace(['<br>', '<br/>', '<br />', '</p>', '</li>', '</tr>'], "\n", $text)), ENT_QUOTES);
        $text = str_replace(['–', '—', '″', '”'], ['-', '-', '"', '"'], $text);
        $lines = preg_split('/\R+/', $text) ?: [];

        foreach ($lines as $i => $line) {
            if (! preg_match(self::CHEST, $line, $m, PREG_OFFSET_CAPTURE) || preg_match('/print|logo|decorat|embroider|imprint/i', $line)) {
                continue;
            }
            $rest = substr($line, $m[0][1] + strlen($m[0][0]));
            $full = preg_match('/\bfull\b|circumference|around/i', $line) && ! preg_match('/half|1\s*\/\s*2|½/i', $line);
            $inch = (bool) preg_match('/\binch|\bin\b|"/i', $line);

            // "S 52, M 55, L 58" / "S-52cm M-55cm" / "S: 52"
            $pairs = [];
            if (preg_match_all('/\b(\d{1,2}XL|X{1,3}[SL]|[SML]|\d{1,2})\s*[-:=]?\s*(\d{2,3}(?:\.\d)?)\s*(cm|mm|in|")?/i', $rest, $mm, PREG_SET_ORDER)) {
                foreach ($mm as $x) {
                    if (self::isSize($x[1]) && ! ctype_digit($x[1]) || (ctype_digit($x[1]) && (int) $x[1] <= 26 && (float) $x[2] >= 20)) {
                        $pairs[self::norm($x[1])] = self::cm((float) $x[2], $x[3] ?? '', $inch);
                    }
                }
            }
            if (count($pairs) >= 2) {
                return self::flat($pairs, $full);
            }

            // Numbers only, with the sizes on a nearby line (a table or "Sizes: S, M, L").
            preg_match_all('/(\d{2,3}(?:\.\d)?)/', $rest, $nums);
            $nums = $nums[1];
            if (count($nums) >= 2) {
                foreach ([$i - 1, $i - 2, $i + 1, $i - 3] as $j) {
                    $sizes = isset($lines[$j]) ? self::sizesIn($lines[$j]) : [];
                    if (count($sizes) === count($nums)) {
                        $unit = preg_match('/\bmm\b/i', $line) ? 'mm' : '';

                        return self::flat(array_combine($sizes, array_map(fn ($n) => self::cm((float) $n, $unit, $inch), $nums)), $full);
                    }
                }
                $sizes = self::sizesIn($text);
                if (count($sizes) === count($nums)) {
                    return self::flat(array_combine($sizes, array_map(fn ($n) => self::cm((float) $n, '', $inch), $nums)), $full);
                }
            }
        }

        return null;
    }

    /** @param array<string, float> $chart */
    public static function pick(array $chart, string $want): ?array
    {
        if (isset($chart[$want])) {
            return ['size' => $want, 'cm' => $chart[$want]];
        }
        // Womens and kids charts in letters use L too; else the nearest size above, then below.
        if ($want !== 'L' && isset($chart['L'])) {
            return ['size' => 'L', 'cm' => $chart['L']];
        }
        $keys = array_keys($chart);
        $rank = fn ($s) => ctype_digit($s) ? (int) $s : (array_search($s, self::SIZE_ORDER, true) ?: 0);
        $target = ctype_digit($want) ? (int) $want : $rank('L');
        $numeric = ctype_digit($want);
        $same = array_values(array_filter($keys, fn ($k) => ctype_digit($k) === $numeric));
        if (! $same) {
            return null;
        }
        usort($same, fn ($a, $b) => abs($rank($a) - $target) <=> abs($rank($b) - $target) ?: $rank($b) <=> $rank($a));

        return ['size' => $same[0], 'cm' => $chart[$same[0]]];
    }

    public static function isSize(string $s): bool
    {
        return in_array(self::norm($s), self::SIZE_ORDER, true) || ctype_digit($s);
    }

    public static function norm(string $s): string
    {
        $s = strtoupper(trim($s));

        return ['XXL' => '2XL', 'XXXL' => '3XL', 'XXS' => '2XS'][$s] ?? $s;
    }

    /** @return list<string> */
    public static function sizesIn(string $line): array
    {
        if (! preg_match_all('/\b(\d{1,2}XL|X{1,3}[SL]|XS|[SML]|\d{1,2})\b/i', $line, $m)) {
            return [];
        }
        $sizes = array_map([self::class, 'norm'], $m[1]);
        // Letter sizes only, unless the line has no letters (kids/womens numbers).
        $letters = array_values(array_filter($sizes, fn ($s) => ! ctype_digit($s)));

        return count($letters) >= 2 ? $letters : (count($letters) ? [] : array_values(array_filter($sizes, fn ($s) => (int) $s <= 26)));
    }

    private static function cm(float $v, string $unit, bool $inch): float
    {
        $u = strtolower($unit);
        if ($u === 'mm' || $v > 300) {
            return $v / 10;
        }
        if ($u === 'in' || $u === '"' || ($inch && $v < 70)) {
            return round($v * 2.54, 1);
        }

        return $v;
    }

    /** Full chest (around the body) is halved; anything still out of range for a garment is dropped. */
    private static function flat(array $chart, bool $full): ?array
    {
        $max = max($chart);
        if ($full || $max > 95) {
            $chart = array_map(fn ($v) => round($v / 2, 1), $chart);
        }
        $chart = array_filter($chart, fn ($v) => $v >= 25 && $v <= 95);

        return count($chart) >= 2 ? $chart : null;
    }
}
