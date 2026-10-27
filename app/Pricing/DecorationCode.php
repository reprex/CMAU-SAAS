<?php

namespace App\Pricing;

/**
 * TOD keys decorations by their description reduced to lower-case letters and
 * digits (LCase(AlphaNumericOnly(desc))): "Pad Print 1 Colour" -> "padprint1colour".
 * A product's option id is "APPAID-code", e.g. "2568-padprint1colour".
 */
final class DecorationCode
{
    public static function fromDescription(?string $description): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $description));
    }

    public static function optionId(int $productId, ?string $description): string
    {
        return $productId.'-'.self::fromDescription($description);
    }
}
