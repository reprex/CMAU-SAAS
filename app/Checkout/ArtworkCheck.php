<?php

namespace App\Checkout;

use Illuminate\Http\UploadedFile;

/**
 * Website artwork check (Scott, 2 Nov 2026). Nothing is ever rejected: each logo is
 * flagged "ready" (OK for proofing) or "help" (our team will help make it print-ready).
 *
 *  - Solid colour methods (embroidery, screen, pad, laser, deboss, foil) want vector
 *    artwork: AI, EPS, SVG, or a PDF with no photos in it.
 *  - Full colour methods (DTF, sublimation, digital) want 300 dpi at the printed width,
 *    on a transparent base, ideally CMYK (PDF or JPG; PNG can only be RGB, which we convert).
 *
 * The shopper's browser checks the same things first and sends its notes; the server
 * repeats what it can and keeps the stricter answer.
 */
final class ArtworkCheck
{
    /** @return list<string> methods that want solid colour vector artwork (config website.artwork_solid_methods) */
    public static function solidMethods(): array
    {
        return (array) config('website.artwork_solid_methods', ['embroidery', 'screen', 'pad', 'laser', 'deboss', 'foil']);
    }

    public const MIN_DPI = 300;

    /**
     * @param  array<string, mixed>  $client  the position as sent (preflight, preflight_notes, width_mm)
     * @return array{0: string, 1: list<string>} [ready|help, notes]
     */
    public static function verdict(string $method, UploadedFile $file, array $client = []): array
    {
        $notes = [];
        $ext = mb_strtolower($file->getClientOriginalExtension() ?: (string) $file->guessExtension());
        $path = (string) $file->getRealPath();
        $head = $path !== '' ? (string) @file_get_contents($path, false, null, 0, 2_000_000) : '';
        $vector = in_array($ext, ['svg', 'ai', 'eps'], true) || ($ext === 'pdf' && ! str_contains($head, '/Subtype /Image') && ! str_contains($head, '/Subtype/Image'));

        if (in_array($method, self::solidMethods(), true)) {
            if (! $vector) {
                $notes[] = 'Needs vector artwork (AI, EPS, SVG or a vector PDF) in solid colours; this is '.strtoupper($ext ?: 'an image').'.';
            }
        } else {
            $width = (int) ($client['width_mm'] ?? 0);
            if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true) && $path !== '') {
                $info = @getimagesize($path);
                if ($info && $width > 0) {
                    $dpi = (int) floor($info[0] / ($width / 25.4));
                    if ($dpi < self::MIN_DPI) {
                        $notes[] = "{$dpi} dpi at {$width} mm wide; print needs ".self::MIN_DPI.' dpi.';
                    }
                }
                if (in_array($ext, ['jpg', 'jpeg'], true)) {
                    $notes[] = 'JPG has no transparent background.';
                } elseif ($ext === 'png' && ! self::pngHasAlpha($head)) {
                    $notes[] = 'No transparent background.';
                }
            }
        }

        // Keep the browser's findings too (it can see things the server can't, e.g. the size chosen).
        foreach ((array) ($client['preflight_notes'] ?? []) as $n) {
            $n = mb_substr(trim((string) $n), 0, 200);
            if ($n !== '' && ! in_array($n, $notes, true)) {
                $notes[] = $n;
            }
        }
        $help = $notes !== [] && (($client['preflight'] ?? null) === 'help' || self::blocking($notes));

        return [$help ? 'help' : 'ready', array_slice($notes, 0, 8)];
    }

    /** Notes that make artwork not print-ready (an RGB note alone does not: we convert). */
    private static function blocking(array $notes): bool
    {
        foreach ($notes as $n) {
            if (! preg_match('/^RGB\b|we convert/i', $n)) {
                return true;
            }
        }

        return false;
    }

    /** PNG colour type 4 or 6 (alpha channel), or a tRNS chunk. */
    private static function pngHasAlpha(string $bytes): bool
    {
        if (! str_starts_with($bytes, "\x89PNG")) {
            return true; // not readable as PNG: don't guess
        }
        $colourType = ord($bytes[25] ?? "\0");

        return in_array($colourType, [4, 6], true) || str_contains(substr($bytes, 0, 200000), 'tRNS');
    }
}
