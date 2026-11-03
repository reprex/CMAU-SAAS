<?php

namespace App\Catalogue;

/**
 * Reads print areas out of supplier decoration text (Scott, 1 Nov 2026;
 * docs/logo-preview.md). Promodata keeps a product's decoration options as
 * price lines (description, promodata_decoration) and often a "Print Area" /
 * "Decoration Areas" detail; their wording carries the position, the method
 * and the maximum print size, for example:
 *
 *   "Full colour printing (sublimation print area: 209mm x 108mm)"
 *   "Wrap: 209 x 108 mm"          "Front 280 x 350mm, Back 280 x 350mm"
 *   "Pad print 1 colour (40mm (w) x 15mm (h))"   "Laser engraving 6cm x 3cm"
 *   "DTF print up to A3"          "Print area: 50mm diameter"
 *
 * parse() turns one text into areas: position (if stated), method (if stated,
 * else the hint), width and height in mm. It never guesses a size: text with
 * no readable size gives nothing, and three-number product sizes are ignored.
 * Pure: no database, so it is unit tested on its own.
 */
final class DecorationAreas
{
    /**
     * Methods, most specific first (the first that matches a piece of text
     * wins). The keys shared with config website.methods (dtf, dtf_oversize,
     * screen, pad, embroidery) mean the same thing there.
     */
    public const METHODS = [
        // Supplier transfer brands (Legend Life: Supacolour, Supaflex, Supaetch, Supasub; SupaDTF is DTF).
        'supacolour' => ['Full colour transfer', '/supacolou?r/i'],
        'supaflex' => ['Flex transfer', '/supaflex/i'],
        'supaetch' => ['Etched look transfer', '/supaetch/i'],
        'supasub' => ['Sublimated transfer', '/supasub/i'],
        'embroidery' => ['Embroidery', '/embroider|stitch(?:ed|ing)\b/i'],
        'transfer_screen' => ['Screen printed transfer', '/(screen|plastisol|pastisol).{0,25}transfer|transfer.{0,25}(screen|plastisol|pastisol)|plastisol|pastisol/i'],
        'dtf_oversize' => ['Oversize DTF', '/(over\s*-?\s*size|oversized|jumbo).{0,20}(dtf|transfer)|(dtf|transfer).{0,20}(over\s*-?\s*size|oversized|jumbo)/i'],
        'dtf' => ['DTF', '/\bdtf\b|supadtf|direct[\s-]to[\s-]film|digital transfer|full colou?r transfer/i'],
        'sublimation' => ['Sublimation', '/sublimat/i'],
        'screen' => ['Screen print', '/screen/i'],
        'pad' => ['Pad print', '/\bpad\b/i'],
        'laser' => ['Laser engraving', '/laser|engrav|etch|gloss\s*tone/i'],
        'enamel' => ['Enamel print', '/enamel/i'],
        'woven' => ['Woven / knitted', '/\bwoven\b|\bknit(ted)?\b/i'],
        'label' => ['Label / sticker', '/\blabel\b|sticker|decal/i'],
        'doming' => ['Resin dome', '/resin|doming|\bdome[ds]?\b/i'],
        'offset' => ['Offset print', '/\boffset\b|\blitho/i'],
        'deboss' => ['Deboss / emboss', '/deboss|emboss/i'],
        'foil' => ['Foil stamp', '/\bfoil/i'],
        'heat_transfer' => ['Heat transfer', '/heat[\s-]*(?:press|transfer)|\bvinyl\b|\bhtv\b/i'],
        'digital' => ['Full colour digital', '/full[\s-]*colou?r|\bdigital|\buv\b|\bcmyk\b|4\s*colou?r process|\b4cp\b|direct print|\bdtg\b/i'],
    ];

    /**
     * Positions: pattern => [name, view]. Longest phrases first. "view" is the
     * picture the position is seen on (front, back, side, top).
     */
    public const POSITIONS = [
        '/wrap[\s-]*(?:a)?round|\bwrap\b|\b360\b|around the (?:barrel|body|bottle|mug)/i' => ['Wrap around', 'front'],
        '/left[\s-]*chest|\bl\/?h\s*chest|\blhc\b/i' => ['Left chest', 'front'],
        '/right[\s-]*chest|\br\/?h\s*chest|\brhc\b/i' => ['Right chest', 'front'],
        '/(?:front|centre|center)[\s-]*(?:front|centre|center)|full[\s-]*front/i' => ['Front', 'front'],
        '/back[\s-]*(?:of\s*)?neck|\bnape\b/i' => ['Back neck', 'back'],
        '/upper[\s-]*back|\byoke\b/i' => ['Upper back', 'back'],
        '/left[\s-]*sleeve/i' => ['Left sleeve', 'side'],
        '/right[\s-]*sleeve/i' => ['Right sleeve', 'side'],
        '/sleeves?\b/i' => ['Sleeve', 'side'],
        '/\bpocket\b/i' => ['Pocket', 'front'],
        '/\blid\b/i' => ['Lid', 'top'],
        '/\bpeak\b|\bbrim\b|\bvisor\b/i' => ['Peak', 'front'],
        '/\bbarrel\b/i' => ['Barrel', 'front'],
        '/\bclip\b/i' => ['Clip', 'front'],
        '/\bhandle\b/i' => ['Handle', 'side'],
        '/\bstrap\b/i' => ['Strap', 'front'],
        '/\bflap\b/i' => ['Flap', 'front'],
        '/\bcover\b/i' => ['Cover', 'front'],
        '/\bbase\b|\bbottom\b/i' => ['Base', 'top'],
        '/\btop\b/i' => ['Top', 'top'],
        '/\bback\b|\breverse\b|\brear\b/i' => ['Back', 'back'],
        '/\bfront\b|\bface\b/i' => ['Front', 'front'],
        '/\bsides?\b|\bpanel\b|\bgusset\b/i' => ['Side', 'side'],
    ];

    /** Portrait paper sizes, for "DTF up to A3". */
    public const PAPER = ['A2' => [420, 594], 'A3' => [297, 420], 'A4' => [210, 297], 'A5' => [148, 210], 'A6' => [105, 148]];

    private const NUM = '(\d+(?:[.,]\d+)?)';

    private const UNIT = '\s*(mm|cm|millimet(?:re|er)s?|centimet(?:re|er)s?|inch(?:es)?|in\b|"|″)?';

    private const MARK_AFTER = '(?:\s*\(?\s*(w|h|wide|high|width|height|long|length|l)\s*\)?(?![a-z]))?';

    private const MARK_BEFORE = '(?:(?<![a-z])(w|h|width|height)\s*[:=]?\s*)?';

    /** Words that make a number pair a print area even without a unit. */
    private const AREA_WORDS = '/print(?:ing)?\s*area|imprint|decoration\s*area|branding\s*area|logo\s*area|max(?:imum)?|print\s*size|area\b|up to|wrap|front|back|chest|sleeve/i';

    public static function method(string $text): ?string
    {
        foreach (self::METHODS as $key => [, $re]) {
            if (preg_match($re, $text)) {
                return $key;
            }
        }

        return null;
    }

    public static function methodName(?string $method): string
    {
        return $method === null ? 'Branding' : (self::METHODS[$method][0] ?? (string) config("website.methods.{$method}.name", $method));
    }

    /** @return array{0: string, 1: string}|null [name, view] */
    public static function position(string $text): ?array
    {
        foreach (self::POSITIONS as $re => $pos) {
            if (preg_match($re, $text)) {
                return $pos;
            }
        }

        return null;
    }

    /** View of a position name (e.g. a template's "Back" is seen on the back picture). */
    public static function viewOf(string $positionName): string
    {
        return self::position($positionName)[1] ?? 'front';
    }

    /**
     * Every print area in one text.
     *
     * @return list<array{position: ?string, view: ?string, method: ?string, width_mm: int, height_mm: ?int, shape: string}>
     */
    public static function parse(?string $text, ?string $methodHint = null): array
    {
        $text = self::clean((string) $text);
        if ($text === '' || ! preg_match('/\d/', $text)) {
            return [];
        }
        $hint = $methodHint !== null && $methodHint !== '' ? self::method($methodHint) : null;
        $hits = self::sizes($text);
        if ($hits === []) {
            return [];
        }

        $out = [];
        $lastMethod = null;
        foreach ($hits as $i => $hit) {
            $before = mb_substr($text, $i === 0 ? 0 : $hits[$i - 1]['end'], max(0, $hit['start'] - ($i === 0 ? 0 : $hits[$i - 1]['end'])));
            $after = mb_substr($text, $hit['end'], (isset($hits[$i + 1]) ? $hits[$i + 1]['start'] : mb_strlen($text)) - $hit['end']);
            $method = self::method($before) ?? ($i === count($hits) - 1 || $lastMethod === null ? self::method(self::untilNextClause($after)) : null) ?? $lastMethod;
            $lastMethod = $method ?? $lastMethod;
            // "Front 280 x 350mm" (before), else "45mm dia on the lid" (straight after, same clause).
            $position = self::position(self::lastClause($before)) ?? self::position(self::untilNextClause($after));
            $out[] = [
                'position' => $position[0] ?? null,
                'view' => $position[1] ?? null,
                'method' => $method ?? self::method($text) ?? $hint,
                'width_mm' => $hit['w'],
                'height_mm' => $hit['h'],
                'shape' => $hit['shape'],
            ];
        }

        return $out;
    }

    /** One line of text, with the different "x" and diameter signs made plain. */
    private static function clean(string $text): string
    {
        $text = strip_tags(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = str_replace(['×', '✕', '╳', 'Ⅹ', '⌀', 'Ø', 'ø', '’’', '”', '“', "''"], [' x ', ' x ', ' x ', ' x ', ' dia ', ' dia ', ' dia ', '"', '"', '"', '"'], $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Sizes in the text, in order: "W x H" pairs, diameters, then paper sizes
     * when nothing else was found.
     *
     * @return list<array{start: int, end: int, w: int, h: ?int, shape: string}>
     */
    private static function sizes(string $text): array
    {
        $n = self::NUM;
        $u = self::UNIT;
        $pair = '/'.self::MARK_BEFORE.$n.$u.self::MARK_AFTER.'\s*(?:x|\*|by)\s*'.self::MARK_BEFORE.$n.$u.self::MARK_AFTER.'/iu';
        $hits = [];
        if (preg_match_all($pair, $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($m as $hit) {
                $start = mb_strlen(substr($text, 0, $hit[0][1]));
                $end = $start + mb_strlen($hit[0][0]);
                // A third number ("40 x 30 x 20 cm") is a product or carton size, not a print area.
                if (preg_match('/^\s*(?:mm|cm)?\s*(?:x|\*)\s*\d/i', mb_substr($text, $end, 12))) {
                    continue;
                }
                $unitA = self::unit($hit[3][0] ?? '');
                $unitB = self::unit($hit[7][0] ?? '');
                $unit = $unitB ?? $unitA ?? self::unit(self::trailingUnit(mb_substr($text, $end, 8)));
                if ($unit === null && ! preg_match(self::AREA_WORDS, mb_substr($text, max(0, $start - 40), 40 + ($end - $start)))) {
                    continue; // no unit and nothing saying it's an area: too ambiguous
                }
                $a = self::mm((float) str_replace(',', '.', $hit[2][0]), $unitA ?? $unit ?? 'mm');
                $b = self::mm((float) str_replace(',', '.', $hit[6][0]), $unitB ?? $unit ?? 'mm');
                $firstIsHeight = in_array(mb_strtolower(($hit[1][0] ?? '') ?: ($hit[4][0] ?? '')), ['h', 'high', 'height'], true)
                    || in_array(mb_strtolower(($hit[5][0] ?? '') ?: ($hit[8][0] ?? '')), ['w', 'wide', 'width'], true);
                [$w, $h] = $firstIsHeight ? [$b, $a] : [$a, $b];
                if (self::plausible($w) && self::plausible($h)) {
                    $hits[] = ['start' => $start, 'end' => $end, 'w' => $w, 'h' => $h, 'shape' => 'rect'];
                }
            }
        }

        $dia = '/(?:\b(?:dia(?:meter)?|round|circle)\.?\s*(?:of\s*)?[:=]?\s*(?:up to\s*)?'.$n.$u.')|(?:'.$n.$u.'\s*(?:dia(?:meter)?|round|circle)\b)/iu';
        if (preg_match_all($dia, $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($m as $hit) {
                $start = mb_strlen(substr($text, 0, $hit[0][1]));
                $end = $start + mb_strlen($hit[0][0]);
                if (self::overlaps($hits, $start, $end)) {
                    continue;
                }
                [$num, $unit] = ($hit[1][0] ?? '') !== '' ? [$hit[1][0], $hit[2][0] ?? ''] : [$hit[3][0], $hit[4][0] ?? ''];
                $d = self::mm((float) str_replace(',', '.', $num), self::unit($unit) ?? 'mm');
                if (self::plausible($d)) {
                    $hits[] = ['start' => $start, 'end' => $end, 'w' => $d, 'h' => $d, 'shape' => 'circle'];
                }
            }
        }

        if ($hits === [] && preg_match_all('/\b(A[2-6])\b/', $text, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $hit) {
                $start = mb_strlen(substr($text, 0, $hit[1]));
                [$w, $h] = self::PAPER[$hit[0]];
                $hits[] = ['start' => $start, 'end' => $start + 2, 'w' => $w, 'h' => $h, 'shape' => 'rect'];
            }
        }

        usort($hits, fn ($a, $b) => $a['start'] <=> $b['start']);

        return array_values($hits);
    }

    private static function unit(?string $raw): ?string
    {
        $raw = mb_strtolower(trim((string) $raw));

        return match (true) {
            $raw === '' => null,
            str_starts_with($raw, 'mm'), str_starts_with($raw, 'millimet') => 'mm',
            str_starts_with($raw, 'cm'), str_starts_with($raw, 'centimet') => 'cm',
            str_starts_with($raw, 'in'), $raw === '"', $raw === '″' => 'in',
            default => null,
        };
    }

    private static function trailingUnit(string $after): string
    {
        return preg_match('/^\s*(mm|cm)\b/i', $after, $m) ? $m[1] : '';
    }

    private static function mm(float $value, string $unit): int
    {
        return (int) round(match ($unit) {
            'cm' => $value * 10,
            'in' => $value * 25.4,
            default => $value,
        });
    }

    private static function plausible(?int $mm): bool
    {
        return $mm !== null && $mm >= 3 && $mm <= 2000;
    }

    private static function overlaps(array $hits, int $start, int $end): bool
    {
        foreach ($hits as $h) {
            if ($start < $h['end'] && $end > $h['start']) {
                return true;
            }
        }

        return false;
    }

    /** The text after the last clause break: "Front 1 colour; Back " => "Back ". */
    private static function lastClause(string $before): string
    {
        $parts = preg_split('/[;,|\/\n]|\band\b|\s-\s/i', $before) ?: [$before];
        for ($i = count($parts) - 1; $i >= 0; $i--) {
            if (preg_match('/[a-z]/i', $parts[$i])) {
                return $parts[$i];
            }
        }

        return $before;
    }

    private static function untilNextClause(string $after): string
    {
        return (preg_split('/[;,|\n]/', $after) ?: [$after])[0];
    }
}
