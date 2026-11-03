<?php

namespace App\Catalogue;

use App\Models\Product;

/**
 * The product page "Size guide" (Scott, 3 Nov 2026): the fit, a measurements table and the supplier's
 * size chart picture or document, from the Promodata details, attributes, description and images.
 * Published as custom.sizeguide (json); the theme shows it above Product specifications, and the
 * specification lines it came from are left out of that list.
 *
 * {"fit":"Regular fit","sizes":["S","M","L"],"rows":[{"label":"Chest (½)","unit":"cm","values":[52,55,58]}],
 *  "image":"https://…/size-chart.jpg","link":"https://…/size-guide.pdf","notes":["Measured laid flat."]}
 */
class SizeGuide
{
    private const MEASURES = [
        'Chest (½)' => '/(?:1\s*\/\s*2|half|½)\s*chest|chest\s*\(?\s*(?:1\s*\/\s*2|half|½)|pit\s*to\s*pit|body\s*width|chest\s*width|width\s*across/i',
        'Chest' => '/\bchest\b/i',
        'Bust' => '/\bbust\b/i',
        'Sleeve' => '/sleeve/i',
        'Shoulder' => '/shoulder/i',
        'Waist' => '/\bwaist\b/i',
        'Hip' => '/\bhips?\b/i',
        'Inseam' => '/inseam|inside\s*leg/i',
        'Neck' => '/\bneck\b|collar/i',
        'Length' => '/\blength\b/i',
    ];

    private const FITS = '/\b(regular|classic|slim|relaxed|oversized?|boxy|tapered|athletic|modern|standard|loose|tailored|fitted|longline|cropped|unisex|straight)\s+(?:fit|cut)\b/i';

    /** @return array<string, mixed>|null */
    public static function forProduct(Product $product): ?array
    {
        $product->loadMissing(['detail', 'images']);
        $d = $product->detail;
        $guide = ['fit' => null, 'sizes' => [], 'rows' => [], 'highlight' => null, 'image' => null, 'link' => null, 'notes' => []];

        $lines = [];
        foreach ((array) ($d?->details ?? []) as $x) {
            if (is_array($x) && filled($x['detail'] ?? null)) {
                $lines[] = [(string) ($x['name'] ?? ''), (string) $x['detail']];
            }
        }

        // Fit: a "Fit" detail, a "Fit: …" attribute, else "regular fit" in the description.
        foreach ($lines as [$name, $detail]) {
            if (preg_match('/^\s*(fit|fit\s*type|cut|style\s*fit)\s*$/i', $name)) {
                $guide['fit'] = self::fitWords($detail);
                break;
            }
        }
        foreach ((array) ($d?->promodata_attributes ?? []) as $a) {
            if (! $guide['fit'] && preg_match('/^\s*fit\s*:\s*(.+)$/i', (string) $a, $m)) {
                $guide['fit'] = self::fitWords($m[1]);
            }
        }
        if (! $guide['fit'] && preg_match(self::FITS, strip_tags((string) $d?->description), $m)) {
            $guide['fit'] = ucfirst(strtolower($m[1])).' fit';
        }

        // Measurements, from the details first, then the description.
        $texts = [implode("\n", array_map(fn ($l) => trim($l[0].': '.$l[1]), $lines)), (string) $d?->description];
        foreach ($texts as $t) {
            $table = self::table($t);
            if ($table) {
                $guide['sizes'] = $table['sizes'];
                $guide['rows'] = $table['rows'];
                break;
            }
        }
        // The size the website mockup is drawn at, highlighted in the table.
        if ($guide['rows'] && ($pick = GarmentSizes::pick(array_fill_keys($guide['sizes'], 1.0), GarmentSizes::targetSize((string) ($product->overrideValue('title') ?: $product->name))))) {
            $guide['highlight'] = $pick['size'];
        }
        if ($guide['rows'] && preg_match('/laid\s*flat|flat\s*measure/i', implode(' ', $texts))) {
            $guide['notes'][] = 'Measured laid flat.';
        }

        // The supplier's size chart: a picture among the product images, or a link in the details.
        $img = $product->images->first(fn ($i) => preg_match('/size[\s_-]*(chart|guide|spec)|sizing|measure/i', urldecode((string) $i->url)));
        $guide['image'] = $img?->url;
        foreach ($lines as [$name, $detail]) {
            if (preg_match('/size|measure|fit/i', $name.' '.$detail) && preg_match('~https?://[^\s"<>]+~i', $detail, $m)) {
                if (preg_match('/\.(jpe?g|png|webp|gif)(\?|$)/i', $m[0]) && ! $guide['image']) {
                    $guide['image'] = $m[0];
                } else {
                    $guide['link'] ??= $m[0];
                }
            }
        }

        // Only with real measurements (Scott): a table from the data, or the supplier's size chart picture. A fit or a link alone isn't enough.
        return ($guide['rows'] || $guide['image']) ? array_filter($guide, fn ($v) => $v !== null && $v !== []) : null;
    }

    /** Which details the guide shows, so Product specifications can leave them out. */
    public static function usesDetail(string $name, string $detail): bool
    {
        $text = $name.': '.$detail;
        if (preg_match('/^\s*(fit|fit\s*type|cut|style\s*fit)\s*$/i', $name)) {
            return true;
        }
        if (preg_match('/size\s*(chart|guide)|measurement/i', $name)) {
            return true;
        }

        return self::table($text) !== null;
    }

    private static function fitWords(string $s): string
    {
        $s = trim(strip_tags($s));
        if (preg_match(self::FITS, $s, $m)) {
            return ucfirst(strtolower($m[1])).' fit';
        }
        if (preg_match('/^(regular|classic|slim|relaxed|oversized?|boxy|tapered|athletic|modern|standard|loose|tailored|fitted|longline|cropped|unisex|straight)$/i', $s, $m)) {
            return ucfirst(strtolower($m[1])).' fit';
        }

        return ucfirst(mb_strimwidth($s, 0, 40, '…'));
    }

    /**
     * Every measurement line in a text, on one shared set of sizes.
     *
     * @return array{sizes: list<string>, rows: list<array{label: string, unit: string, values: list<float|null>}>}|null
     */
    public static function table(string $text): ?array
    {
        $text = html_entity_decode(strip_tags(str_ireplace(['<br>', '<br/>', '<br />', '</p>', '</li>', '</tr>'], "\n", $text)), ENT_QUOTES);
        $text = str_replace(['–', '—', '″', '”'], ['-', '-', '"', '"'], $text);
        // One-line details like "Chest (1/2): S 52 M 55 | Length: S 70 M 72" become separate lines.
        $text = preg_replace('/\s*[|;]\s*(?=[A-Za-z][A-Za-z ½\/()]{2,30}:)/', "\n", $text);
        $lines = preg_split('/\R+/', $text) ?: [];
        $sizesLine = null;
        foreach ($lines as $l) {
            if (preg_match('/^\s*(size|sizes)\b/i', $l) && count(GarmentSizes::sizesIn($l)) >= 2) {
                $sizesLine = GarmentSizes::sizesIn($l);
            }
        }

        $rows = [];
        foreach ($lines as $line) {
            if (preg_match('/print|logo|decorat|embroider|imprint|carton|packag|box|label/i', $line)) {
                continue;
            }
            $label = null;
            foreach (self::MEASURES as $name => $re) {
                if (preg_match($re, $line, $m, PREG_OFFSET_CAPTURE)) {
                    $label = $name;
                    $rest = substr($line, $m[0][1] + strlen($m[0][0]));
                    break;
                }
            }
            if (! $label || isset($rows[$label])) {
                continue;
            }
            $inch = (bool) preg_match('/\binch|\bin\b|"/i', $line);
            $mmUnit = (bool) preg_match('/\(mm\)|\bmm\b/i', $line);
            $values = [];
            if (preg_match_all('/\b(\d{1,2}XL|X{1,3}[SL]|XS|[SML]|\d{1,2})\s*[-:=]?\s*(\d{2,3}(?:\.\d)?)\s*(cm|mm|in|")?/i', $rest, $mm, PREG_SET_ORDER)) {
                foreach ($mm as $x) {
                    $letter = ! ctype_digit($x[1]) && GarmentSizes::isSize($x[1]);
                    if ($letter || ((int) $x[1] <= 26 && (float) $x[2] >= 20)) {
                        $values[GarmentSizes::norm($x[1])] = self::cm((float) $x[2], ($x[3] ?? '') ?: ($mmUnit ? 'mm' : ''), $inch);
                    }
                }
            }
            if (count($values) < 2 && $sizesLine) {
                preg_match_all('/(\d{2,4}(?:\.\d)?)/', $rest, $nums);
                if (count($nums[1]) === count($sizesLine)) {
                    $values = array_combine($sizesLine, array_map(fn ($n) => self::cm((float) $n, $mmUnit ? 'mm' : '', $inch), $nums[1]));
                }
            }
            if (count($values) >= 2 && max($values) <= 200 && min($values) >= 5) {
                $rows[$label] = $values;
            }
        }
        if (! $rows) {
            return null;
        }
        if (isset($rows['Chest (½)'], $rows['Chest'])) {
            unset($rows['Chest']);
        }

        $sizes = [];
        foreach ($rows as $vals) {
            $sizes = array_merge($sizes, array_keys($vals));
        }
        $sizes = array_values(array_unique($sizes));
        $rank = fn ($s) => ctype_digit($s) ? 100 + (int) $s : (int) array_search($s, GarmentSizes::SIZE_ORDER, true);
        usort($sizes, fn ($a, $b) => $rank($a) <=> $rank($b));

        $out = [];
        foreach ($rows as $label => $vals) {
            $out[] = ['label' => $label, 'unit' => 'cm', 'values' => array_map(fn ($s) => isset($vals[$s]) ? (fmod(round($vals[$s], 1), 1.0) == 0.0 ? (int) $vals[$s] : round($vals[$s], 1)) : null, $sizes)];
        }

        return ['sizes' => $sizes, 'rows' => $out];
    }

    private static function cm(float $v, string $unit, bool $inch): float
    {
        $u = strtolower($unit);
        if ($u === 'mm' || $v > 250) {
            return $v / 10;
        }
        if ($u === 'in' || $u === '"' || $inch) {
            return round($v * 2.54, 1);
        }

        return $v;
    }
}
