<?php

namespace App\Catalogue;

use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Is a product photo on a white background? (Scott, 2 Nov 2026: a white-background
 * style photo is the hero everywhere, docs/images.md.) Looks at a band round the
 * edge of the picture: white or transparent there means a white background.
 * Needs PHP's GD; without it nothing is marked and the heroes stay as they are.
 */
class Backdrop
{
    /** Share of the edge band that must be white (or see-through). */
    public const MIN_WHITE = 0.92;

    /** Each of red, green and blue at least this to count as white (AS Colour's studio grey is about 225). */
    public const WHITE_LEVEL = 240;

    public function enabled(): bool
    {
        return function_exists('imagecreatefromstring');
    }

    /** Look at the product's style photos not yet looked at; returns how many were marked. */
    public function mark(Product $product, int $max = 12): int
    {
        if (! $this->enabled()) {
            return 0;
        }
        $product->loadMissing('images');
        $colourUrls = $product->colours()->pluck('image_url')->filter()->all();
        $n = 0;
        foreach ($product->images as $image) {
            if ($n >= $max) {
                break;
            }
            if ($image->white_bg !== null || $image->role === 'line_art' || in_array($image->url, $colourUrls, true)) {
                continue;
            }
            $white = $this->isWhite($image->url);
            if ($white === null) {
                continue; // couldn't fetch it: try again another run
            }
            $image->forceFill(['white_bg' => $white])->save();
            $n++;
        }

        return $n;
    }

    public function isWhite(string $url): ?bool
    {
        try {
            $r = Http::timeout(15)->get($url);
            if (! $r->successful() || strlen($r->body()) > 25_000_000) {
                return null;
            }
            $img = @imagecreatefromstring($r->body());
        } catch (Throwable) {
            return null;
        }
        if (! $img) {
            return null;
        }

        return $this->whiteEdge($img);
    }

    /** @param  \GdImage  $img */
    public function whiteEdge($img): bool
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $sw = 80;
        $sh = max(1, (int) round($h * $sw / max(1, $w)));
        $small = imagecreatetruecolor($sw, $sh);
        imagealphablending($small, false);
        imagesavealpha($small, true);
        imagecopyresampled($small, $img, 0, 0, 0, 0, $sw, $sh, $w, $h);
        $band = max(1, (int) round(min($sw, $sh) * 0.04));
        $white = $total = 0;
        for ($y = 0; $y < $sh; $y++) {
            for ($x = 0; $x < $sw; $x++) {
                if ($x >= $band && $x < $sw - $band && $y >= $band && $y < $sh - $band) {
                    continue;
                }
                $c = imagecolorat($small, $x, $y);
                $a = ($c >> 24) & 0x7F;
                $total++;
                if ($a > 100 || min(($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF) >= self::WHITE_LEVEL) {
                    $white++;
                }
            }
        }

        return $total > 0 && $white / $total >= self::MIN_WHITE;
    }
}
