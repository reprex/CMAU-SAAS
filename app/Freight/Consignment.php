<?php

namespace App\Freight;

/**
 * What goes on the truck: cartons, dead weight and volume, built from each
 * product's pack data and how many of it are ordered.
 */
final class Consignment
{
    private function __construct(
        public readonly int $cartons,
        public readonly float $kg,
        public readonly float $m3,
        public readonly float $cm3,
    ) {}

    /** @param  list<array{pack: array<string, mixed>, units: int}>  $parts */
    public static function from(array $parts): self
    {
        $cartons = 0;
        $kg = 0.0;
        $cm3 = 0.0;
        foreach ($parts as $part) {
            $units = (int) $part['units'];
            if ($units <= 0) {
                continue;
            }
            $pack = $part['pack'];
            $n = (int) ceil($units / max(1, (int) $pack['units_per_carton']));
            [$l, $w, $h] = $pack['carton_cm'];
            $cartons += $n;
            $kg += $units * (float) $pack['unit_kg'] + $n * (float) $pack['tare_kg'];
            $cm3 += $n * $l * $w * $h;
        }

        return new self($cartons, $kg, $cm3 / 1_000_000, $cm3);
    }
}
