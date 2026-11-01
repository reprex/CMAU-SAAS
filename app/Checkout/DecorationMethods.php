<?php

namespace App\Checkout;

/**
 * Which decoration method a decoration option is (config website.methods), and
 * how many colours its name says, e.g. "Screen print 2 colours" => screen, 2.
 */
final class DecorationMethods
{
    public static function classify(string $name): ?string
    {
        foreach (config('website.methods') as $key => $method) {
            if (preg_match($method['match'], $name)) {
                return $key;
            }
        }

        return null;
    }

    public static function needsReview(?string $method): bool
    {
        return $method === null || (bool) config("website.methods.{$method}.review", true);
    }

    public static function pricing(?string $method): string
    {
        return (string) config("website.methods.{$method}.pricing", 'flat');
    }

    public static function name(?string $method): string
    {
        return (string) config("website.methods.{$method}.name", 'Branding');
    }

    /** Colour count named in a decoration name ("1 colour", "two colours", "4 col"), or null. */
    public static function colourCount(string $name): ?int
    {
        $words = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'single' => 1];
        if (preg_match('/(\d+)\s*(?:-\s*)?(?:colou?rs?|cols?)\b/i', $name, $m)) {
            return max(1, (int) $m[1]);
        }
        if (preg_match('/\b('.implode('|', array_keys($words)).')\s*(?:-\s*)?(?:colou?rs?|cols?)\b/i', $name, $m)) {
            return $words[mb_strtolower($m[1])];
        }

        return null;
    }
}
