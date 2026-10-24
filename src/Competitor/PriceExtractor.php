<?php
declare(strict_types=1);

namespace Cmau\Competitor;

/**
 * Pulls quantity-break prices out of a competitor product page or Shopify product JSON.
 * Every method returns a list of breaks: ['qty' => ?int, 'unit' => float, 'source' => string].
 * qty is null when the site only shows a single "from" price.
 */
final class PriceExtractor
{
    /**
     * Shopify /products/{handle}.json: variant prices (usually a single "from" price per colour).
     * If the store encodes quantity discounts in a tag such as "bulk_250:10-500:15-1000:20"
     * (qty:percent off, as Promo Punks does), the breaks are expanded from the base price.
     */
    public static function fromShopifyJson(string $json): array
    {
        $data = json_decode($json, true);
        $product = $data['product'] ?? [];
        $prices = [];
        foreach ($product['variants'] ?? [] as $v) {
            if (isset($v['price']) && is_numeric($v['price']) && (float)$v['price'] > 0) {
                $prices[] = (float)$v['price'];
            }
        }
        if ($prices === []) {
            return [];
        }
        $base = min($prices);
        $tags = $product['tags'] ?? '';
        $tags = is_array($tags) ? $tags : array_map('trim', explode(',', (string)$tags));
        foreach ($tags as $tag) {
            if (preg_match('/^bulk_((?:\d+:\d+(?:\.\d+)?-?)+)$/', $tag, $m)) {
                $breaks = [['qty' => 1, 'unit' => $base, 'source' => 'shopify_json']];
                foreach (explode('-', $m[1]) as $pair) {
                    [$qty, $pct] = explode(':', $pair);
                    $breaks[] = ['qty' => (int)$qty, 'unit' => round($base * (1 - (float)$pct / 100), 4), 'source' => 'shopify_json'];
                }
                return $breaks;
            }
        }
        return [['qty' => null, 'unit' => $base, 'source' => 'shopify_json']];
    }

    /**
     * schema.org JSON-LD. Reads Offer/AggregateOffer prices and, where present,
     * UnitPriceSpecification entries with eligibleQuantity (true quantity breaks).
     */
    public static function fromJsonLd(string $html): array
    {
        $breaks = [];
        if (!preg_match_all('#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#si', $html, $m)) {
            return [];
        }
        foreach ($m[1] as $block) {
            $data = json_decode(html_entity_decode(trim($block)), true);
            if (!is_array($data)) {
                continue;
            }
            foreach (self::walkOffers($data) as $offer) {
                foreach ((array)($offer['priceSpecification'] ?? []) as $spec) {
                    if (!is_array($spec) || !isset($spec['price'])) {
                        continue;
                    }
                    $min = $spec['eligibleQuantity']['minValue'] ?? null;
                    $breaks[] = [
                        'qty' => is_numeric($min) ? (int)$min : null,
                        'unit' => (float)$spec['price'],
                        'source' => 'page_jsonld',
                    ];
                }
                foreach (['lowPrice', 'price'] as $key) {
                    if (isset($offer[$key]) && is_numeric($offer[$key]) && (float)$offer[$key] > 0) {
                        $breaks[] = ['qty' => null, 'unit' => (float)$offer[$key], 'source' => 'page_jsonld'];
                        break;
                    }
                }
            }
        }
        return self::dedupe($breaks);
    }

    /**
     * Site-specific regex with named groups "qty" and "price", applied to the page's visible text.
     * Example for a table rendered as "100 $4.50": /(?<qty>\d{2,5})\s+\$(?<price>\d+\.\d{2})/
     */
    public static function fromRegex(string $html, string $pattern): array
    {
        $noScripts = preg_replace('#<(script|style)\b.*?</\1>#si', ' ', $html) ?? '';
        // Put a space where each tag was, so table cells don't run together ("250$1.57").
        $text = html_entity_decode(strip_tags(preg_replace('#<[^>]+>#', ' $0', $noScripts) ?? ''));
        $text = preg_replace('/\s+/', ' ', $text) ?? '';
        if (@preg_match_all($pattern, $text, $m, PREG_SET_ORDER) === false) {
            throw new \InvalidArgumentException('Invalid price_regex for competitor');
        }
        $breaks = [];
        foreach ($m as $row) {
            if (isset($row['qty'], $row['price'])) {
                $breaks[] = [
                    'qty' => (int)str_replace(',', '', $row['qty']),
                    'unit' => (float)str_replace(',', '', $row['price']),
                    'source' => 'page_regex',
                ];
            }
        }
        return self::dedupe($breaks);
    }

    /** @return iterable<array<string,mixed>> */
    private static function walkOffers(array $node): iterable
    {
        $type = $node['@type'] ?? null;
        $types = is_array($type) ? $type : [$type];
        if (array_intersect($types, ['Offer', 'AggregateOffer'])) {
            yield $node;
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                yield from self::walkOffers($value);
            }
        }
    }

    private static function dedupe(array $breaks): array
    {
        $seen = [];
        $out = [];
        foreach ($breaks as $b) {
            $key = ($b['qty'] ?? 'from') . '|' . $b['unit'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $b;
            }
        }
        usort($out, fn($a, $b) => ($a['qty'] ?? 0) <=> ($b['qty'] ?? 0));
        return $out;
    }

    /** Unit price at qty from extracted breaks (highest break at or below qty). */
    public static function unitAt(array $breaks, int $qty): ?float
    {
        $byQty = [];
        foreach ($breaks as $b) {
            if ($b['qty'] !== null) {
                $byQty[$b['qty']] = $b['unit'];
            }
        }
        ksort($byQty);
        return DecorationJson::unitPriceAt($byQty, $qty);
    }
}
