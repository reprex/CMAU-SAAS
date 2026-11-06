<?php

namespace App\Freight;

/**
 * What goes on the truck: cartons, dead weight and volume, built from each
 * product's pack data and how many of it are ordered. Each product's cartons
 * are also kept as items (count, size, weight each) for the carriers' APIs.
 * Estimated when any product's weight isn't from Promodata (Scott, 7 Nov 2026).
 */
final class Consignment
{
    /** @param  list<array{quantity: int, length: float, width: float, height: float, weight: float}>  $items */
    private function __construct(
        public readonly int $cartons,
        public readonly float $kg,
        public readonly float $m3,
        public readonly float $cm3,
        public readonly array $items = [],
        public readonly bool $estimated = false,
        public readonly float $value = 0.0,
    ) {}

    /** The goods' value ex GST in AUD (FedEx needs a customs value). */
    public function withValue(float $value): self
    {
        return new self($this->cartons, $this->kg, $this->m3, $this->cm3, $this->items, $this->estimated, $value);
    }

    /** @param  list<array{pack: array<string, mixed>, units: int}>  $parts */
    public static function from(array $parts): self
    {
        $cartons = 0;
        $kg = 0.0;
        $cm3 = 0.0;
        $items = [];
        $estimated = false;
        foreach ($parts as $part) {
            $units = (int) $part['units'];
            if ($units <= 0) {
                continue;
            }
            $pack = $part['pack'];
            $per = max(1, (int) $pack['units_per_carton']);
            $n = (int) ceil($units / $per);
            [$l, $w, $h] = $pack['carton_cm'];
            $cartons += $n;
            $weight = $units * (float) $pack['unit_kg'] + $n * (float) $pack['tare_kg'];
            $kg += $weight;
            $cm3 += $n * $l * $w * $h;
            $estimated = $estimated || ! empty($pack['estimated']);
            // Full cartons, then the part carton (lighter, same size).
            $full = intdiv($units, $per);
            $rest = $units - $full * $per;
            $each = fn (int $u) => round($u * (float) $pack['unit_kg'] + (float) $pack['tare_kg'], 2);
            if ($full > 0) {
                $items[] = ['quantity' => $full, 'length' => (float) $l, 'width' => (float) $w, 'height' => (float) $h, 'weight' => $each($per)];
            }
            if ($rest > 0) {
                $items[] = ['quantity' => 1, 'length' => (float) $l, 'width' => (float) $w, 'height' => (float) $h, 'weight' => $each($rest)];
            }
        }

        return new self($cartons, $kg, $cm3 / 1_000_000, $cm3, $items, $estimated);
    }
}
