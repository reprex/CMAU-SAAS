<?php

namespace App\Pricing;

/**
 * Money helpers that reproduce TOD's Access behaviour exactly.
 *
 * Access stores prices as Currency (4 decimal places). TOD rounds selling
 * prices UP to the cent with GlblRoundup(x, 2) = Int(-100 * x) / -100.
 * Amounts are handled in ten-thousandths (integers) to avoid float error.
 */
final class Money
{
    /** Convert to integer ten-thousandths, rounding like Access Currency (half to even). */
    public static function toUnits(float|int|string $amount): int
    {
        return (int) round((float) $amount * 10000, 0, PHP_ROUND_HALF_EVEN);
    }

    public static function fromUnits(int $units): float
    {
        return $units / 10000;
    }

    /** Access Currency value of $amount (4 decimal places). */
    public static function currency(float|int|string $amount): float
    {
        return self::fromUnits(self::toUnits($amount));
    }

    /** TOD GlblRoundup(x, 2): round UP to the next cent (for negatives, towards zero). */
    public static function roundUpToCent(float|int|string $amount): float
    {
        $units = self::toUnits($amount);

        return self::fromUnits((int) (ceil($units / 100) * 100));
    }

    /** Access Round(x, 2): to the nearest cent, ties to even. */
    public static function roundToCent(float|int|string $amount): float
    {
        $units = self::toUnits($amount);

        return self::fromUnits((int) (round($units / 100, 0, PHP_ROUND_HALF_EVEN) * 100));
    }

    /** Cost x markup, kept as an Access Currency value (4 dp). */
    public static function applyMarkup(float|int|string $cost, float|int|string $markup): float
    {
        return self::currency((float) $cost * (float) $markup);
    }

    /**
     * Format a number the way Access converts Currency/Double to text:
     * no trailing zeros, no thousands separator (60 -> "60", 0.45 -> "0.45").
     */
    public static function format(float|int|string $amount): string
    {
        $value = self::currency($amount);
        $text = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');

        return $text === '-0' ? '0' : $text;
    }
}
