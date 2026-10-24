<?php
declare(strict_types=1);

namespace Cmau\Competitor;

/**
 * Reads CMAU's DecorationJSON metafield and prices a product at a given quantity.
 *
 * Shape (all values are strings):
 * {
 *   "BrandFlag": "...",
 *   "UndecoratedPriceSetup": "0", "UndecoratedPriceString": "25:6.10,50:5.80,...",
 *   "Decorations": [ {"DecID": "...", "DecName": "...", "CombinedSetup": "65", "CombinedPrcString": "50:7.20,100:6.90"} ]
 * }
 * Price strings are "qty:unit" breaks. The first decoration is the default.
 * Decoration prices are per-unit add-ons to the undecorated price; CombinedSetup is the one-off setup.
 */
final class DecorationJson
{
    /** @var array<string,mixed> */
    private array $data;

    public function __construct(string $json)
    {
        $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('DecorationJSON is not an object');
        }
        $this->data = $decoded;
    }

    /** @return list<array{id:string,name:string,setup:float,breaks:array<int,float>}> */
    public function decorations(): array
    {
        $out = [];
        foreach (($this->data['Decorations'] ?? []) as $d) {
            $out[] = [
                'id'     => (string)($d['DecID'] ?? ''),
                'name'   => (string)($d['DecName'] ?? ''),
                'setup'  => (float)($d['CombinedSetup'] ?? 0),
                'breaks' => self::parseBreaks((string)($d['CombinedPrcString'] ?? '')),
            ];
        }
        return $out;
    }

    /** @return array<int,float> qty => unit price, ascending by qty */
    public static function parseBreaks(string $priceString): array
    {
        $breaks = [];
        foreach (preg_split('/\s*,\s*/', trim($priceString), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $pair) {
            if (!preg_match('/^(\d+)\s*:\s*\$?([\d.]+)$/', $pair, $m)) {
                continue;
            }
            $breaks[(int)$m[1]] = (float)$m[2];
        }
        ksort($breaks);
        return $breaks;
    }

    /**
     * Unit price at a quantity: the highest break at or below qty.
     * Returns null when qty is below the first break (under minimum order).
     */
    public static function unitPriceAt(array $breaks, int $qty): ?float
    {
        $price = null;
        foreach ($breaks as $breakQty => $unit) {
            if ($qty >= $breakQty) {
                $price = $unit;
            }
        }
        return $price;
    }

    /**
     * Price the product at qty for a decoration (default: the first one; pass a name, or a
     * case-insensitive fragment such as "1 Col 1 Pos", to compare like for like).
     * @return array{decoration:string,qty:int,unit:float,setup:float,total:float}|null
     */
    public function quote(int $qty, ?string $decorationName = null): ?array
    {
        $decs = $this->decorations();
        if ($decs === []) {
            $breaks = self::parseBreaks((string)($this->data['UndecoratedPriceString'] ?? ''));
            $unit = self::unitPriceAt($breaks, $qty);
            $setup = (float)($this->data['UndecoratedPriceSetup'] ?? 0);
            return $unit === null ? null : [
                'decoration' => 'Undecorated', 'qty' => $qty, 'unit' => $unit,
                'setup' => $setup, 'total' => round($qty * $unit + $setup, 2),
            ];
        }
        $dec = $decs[0];
        if ($decorationName !== null) {
            foreach ($decs as $d) {
                if (strcasecmp($d['name'], $decorationName) === 0
                    || stripos($d['name'], $decorationName) !== false) {
                    $dec = $d;
                    break;
                }
            }
        }
        // Decorated unit = undecorated unit + decoration unit (matches the PDP:
        // "Cost per unit including branding" = (qty * (undec + dec) + setup) / qty).
        $undec = self::unitPriceAt(self::parseBreaks((string)($this->data['UndecoratedPriceString'] ?? '')), $qty) ?? 0.0;
        $decUnit = self::unitPriceAt($dec['breaks'], $qty);
        if ($decUnit === null) {
            return null;
        }
        $unit = round($undec + $decUnit, 4);
        $setup = $dec['setup'] + (float)($this->data['UndecoratedPriceSetup'] ?? 0);
        return [
            'decoration' => $dec['name'],
            'qty'        => $qty,
            'unit'       => $unit,
            'setup'      => $setup,
            'total'      => round($qty * $unit + $setup, 2),
        ];
    }
}
