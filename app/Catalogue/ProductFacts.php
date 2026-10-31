<?php

namespace App\Catalogue;

use App\Sources\SourceProduct;

/**
 * Pulls compact comparison facts out of a supplier product: material,
 * dimensions, fastest lead time and the unbranded minimum order and cost.
 * Supplier text is not uniform, so each parser is forgiving and returns null
 * when it cannot read a value rather than guessing.
 */
final class ProductFacts
{
    /** @return array{material: ?string, dim_1: ?float, dim_2: ?float, dim_3: ?float, lead_time_days: ?int, undecorated_moq: ?int, undecorated_cost: ?float} */
    public static function from(SourceProduct $p): array
    {
        $dims = self::dimensions($p->details);
        [$moq, $cost] = self::undecorated($p->priceGroups);

        return [
            'material' => self::material($p->promodataAttributes, $p->details),
            'dim_1' => $dims[0] ?? null,
            'dim_2' => $dims[1] ?? null,
            'dim_3' => $dims[2] ?? null,
            'lead_time_days' => self::leadTimeDays($p->priceGroups),
            'undecorated_moq' => $moq,
            'undecorated_cost' => $cost,
        ];
    }

    /**
     * Lower-case material words, comma separated, e.g. "cotton, polyester".
     *
     * @param  list<array{name: string, value: ?string}>  $attributes
     * @param  list<array{name: string, detail: ?string}>  $details
     */
    public static function material(array $attributes, array $details): ?string
    {
        $texts = [];
        foreach ($attributes as $a) {
            if (preg_match('/^materials?$/i', trim((string) $a['name']))) {
                $texts[] = (string) $a['value'];
            }
        }
        if ($texts === []) {
            foreach ($details as $d) {
                if (preg_match('/^(materials?|fabric|composition)\b/i', trim((string) $d['name']))) {
                    $texts[] = (string) $d['detail'];
                }
            }
        }

        $words = [];
        foreach ($texts as $text) {
            $text = mb_strtolower(preg_replace('/\([^)]*\)/', ' ', $text));          // "(non-specific)"
            $text = preg_replace('/\d+(\.\d+)?\s*(%|gsm|g\/m2|oz|mm|cm|ml|l)\b/', ' ', $text); // "65%", "180gsm"
            foreach (preg_split('/[,\/;&+|]|\band\b|\bwith\b/', $text) as $part) {
                $part = trim(preg_replace('/[^a-z\s-]/', ' ', $part));
                $part = trim(preg_replace('/\s+/', ' ', $part));
                if ($part !== '' && mb_strlen($part) > 2 && ! in_array($part, ['non', 'specific', 'other', 'mixed', 'various'], true)) {
                    $words[$part] = true;
                }
            }
        }

        return $words === [] ? null : mb_substr(implode(', ', array_keys($words)), 0, 255);
    }

    /**
     * Up to three dimensions in millimetres, largest first, read from a
     * "Dimensions" / "Size" detail, e.g. "140mm x 10mm", "14 x 7.5 x 2cm",
     * "Ø75mm x H100mm", "W 30cm x H 40cm". Apparel sizes (S–5XL) give nothing.
     *
     * @param  list<array{name: string, detail: ?string}>  $details
     * @return list<float>
     */
    public static function dimensions(array $details): array
    {
        foreach ($details as $d) {
            if (! preg_match('/\b(dimensions?|size|measurements?|product size)\b/i', (string) $d['name'])) {
                continue;
            }
            $text = mb_strtolower((string) $d['detail']);
            if (! preg_match_all('/(\d+(?:[.,]\d+)?)\s*(mm|cm|m)?\b/', $text, $m, PREG_SET_ORDER)) {
                continue;
            }

            // A unit written once at the end ("14 x 7.5 x 2cm") applies to every number.
            $lastUnit = null;
            foreach (array_reverse($m) as $hit) {
                if (($hit[2] ?? '') !== '') {
                    $lastUnit = $hit[2];
                    break;
                }
            }
            if ($lastUnit === null) {
                continue; // no unit at all: too ambiguous
            }

            $values = [];
            foreach ($m as $hit) {
                $n = (float) str_replace(',', '.', $hit[1]);
                $unit = ($hit[2] ?? '') ?: $lastUnit;
                $mm = match ($unit) {
                    'cm' => $n * 10,
                    'm' => $n * 1000,
                    default => $n,
                };
                if ($mm > 0 && $mm < 100000) {
                    $values[] = round($mm, 1);
                }
            }
            if ($values !== []) {
                rsort($values);

                return array_slice($values, 0, 3);
            }
        }

        return [];
    }

    /**
     * Fastest stated lead time across the base price lines, in working days.
     * "10 working days" → 10, "5-7 days" → 7, "2 weeks" → 10, "48 hours" → 2.
     *
     * @param  list<array{base: array<string, mixed>, additions: list<array<string, mixed>>}>  $groups
     */
    public static function leadTimeDays(array $groups): ?int
    {
        $best = null;
        foreach ($groups as $g) {
            $days = self::parseLeadTime((string) ($g['base']['lead_time'] ?? ''));
            if ($days !== null && ($best === null || $days < $best)) {
                $best = $days;
            }
        }

        return $best;
    }

    public static function parseLeadTime(string $text): ?int
    {
        $text = mb_strtolower($text);
        if (! preg_match_all('/\d+(?:\.\d+)?/', $text, $m)) {
            return null;
        }
        $n = max(array_map('floatval', $m[0])); // upper end of a range
        $days = match (true) {
            str_contains($text, 'week') => $n * 5,
            str_contains($text, 'hour') => $n / 24,
            str_contains($text, 'month') => $n * 21,
            default => $n,
        };
        $days = (int) ceil($days);

        return $days > 0 && $days < 1000 ? $days : null;
    }

    /**
     * Unbranded minimum order and the supplier's cost at it: the lowest-quantity
     * break across base lines marked undecorated (cheapest if several start there).
     *
     * @param  list<array{base: array<string, mixed>, additions: list<array<string, mixed>>}>  $groups
     * @return array{0: ?int, 1: ?float}
     */
    public static function undecorated(array $groups): array
    {
        $moq = null;
        $cost = null;
        foreach ($groups as $g) {
            if (! ($g['base']['undecorated'] ?? false)) {
                continue;
            }
            foreach ($g['base']['breaks'] ?? [] as $b) {
                $qty = (int) $b['qty'];
                $price = (float) $b['price'];
                if ($qty <= 0 || $price <= 0) {
                    continue;
                }
                if ($moq === null || $qty < $moq || ($qty === $moq && $price < $cost)) {
                    $moq = $qty;
                    $cost = $price;
                }
            }
        }

        return [$moq, $cost];
    }
}
