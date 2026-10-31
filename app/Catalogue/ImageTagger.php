<?php

namespace App\Catalogue;

use App\Models\ImageCheck;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Tags every product image as hero style, other style, colour or other colour
 * (docs/images.md), the way TOD's PDS_LocalImages flags did.
 *
 * Automatic rules, in order:
 *  1. A colour's own Promodata image is that colour's "colour" image.
 *  2. The hero (staff choice, else Promodata's hero) is "hero style".
 *  3. Any other image whose file name contains a colour's name (for example
 *     4001_maple_tee_pistacio_back) is an "other colour" image of that colour.
 *  4. Everything else is "other style".
 * Then Claude's look at the image (ImageVision, stored in image_checks)
 * settles the images the file name says nothing about, and flags for review
 * any image where it and the file name or Promodata disagree.
 * Staff tags on the product screen (override image_tags) always win, and
 * survive every Promodata refresh because they are keyed by image URL.
 */
class ImageTagger
{
    public const HERO_STYLE = 'hero_style';

    public const OTHER_STYLE = 'other_style';

    public const COLOUR = 'colour';

    public const OTHER_COLOUR = 'other_colour';

    /** TOD's single variant for products without colours: SKU APPAID-customprint, colour family cmyk. */
    public const CUSTOM_PRINT = 'Custom Print';

    public const ROLES = [
        self::HERO_STYLE => 'Hero style',
        self::OTHER_STYLE => 'Other style',
        self::COLOUR => 'Colour',
        self::OTHER_COLOUR => 'Other colour',
    ];

    /**
     * Work out the tags and store them on the product's image rows (adding a
     * row for any colour image that has none).
     */
    public function apply(Product $product): void
    {
        $product->load(['images', 'colours', 'override']);
        $have = $product->images->pluck('url')->all();
        $position = (int) $product->images->max('position') + 1;
        foreach ($product->colours as $colour) {
            if ($colour->image_url && ! in_array($colour->image_url, $have, true)) {
                $product->images()->create(['url' => $colour->image_url, 'role' => self::COLOUR, 'position' => $position++]);
                $have[] = $colour->image_url;
            }
        }
        $product->load('images');

        foreach ($this->tags($product) as $tag) {
            $image = $product->images->firstWhere('url', $tag['url']);
            if ($image && ($image->role !== $tag['role'] || $image->colour_name !== $tag['colour'] || $image->tagged_by !== $tag['by'] || $image->review !== $tag['review'])) {
                $image->forceFill(['role' => $tag['role'], 'colour_name' => $tag['colour'], 'tagged_by' => $tag['by'], 'review' => $tag['review']])->save();
            }
        }
    }

    /**
     * @return Collection<int, array{url:string, role:string, colour:?string, by:string, auto_role:string, auto_colour:?string, review:?string, seen:?array}>
     */
    public function tags(Product $product): Collection
    {
        $colours = $product->colours->unique('name')->values();
        $manual = (array) $product->overrideValue('image_tags', []);
        $heroOverride = $product->overrideValue('hero_image_url');
        $hero = $heroOverride ?: $product->hero_image_url;
        $colourImage = [];
        foreach ($product->colours as $c) {
            if ($c->image_url && ! isset($colourImage[$c->image_url])) {
                $colourImage[$c->image_url] = $c->name;
            }
        }
        $nameTokens = $this->tokens($product->name.' '.$product->code);
        $matchers = $colours->map(fn ($c) => ['name' => $c->name, 'tokens' => array_values(array_diff($this->tokens($c->name), ['and', 'with']))])
            ->filter(fn ($m) => $m['tokens'] !== [])
            ->sortByDesc(fn ($m) => count($m['tokens']) * 100 + mb_strlen(implode('', $m['tokens'])))->values();

        $urls = $product->images->pluck('url')->push($hero)->filter()->unique()->values();
        $checks = ImageCheck::query()->whereIn('url_hash', $urls->map(fn ($u) => ImageCheck::hashOf($u)))->get()->keyBy('url');
        $sure = (float) config('images.check.min_confidence', 0.7);
        $tags = $urls->map(function (string $url) use ($hero, $colourImage, $matchers, $nameTokens, $checks, $sure) {
            [$role, $colour] = match (true) {
                isset($colourImage[$url]) && $url !== $hero => [self::COLOUR, $colourImage[$url]],
                $url === $hero => [self::HERO_STYLE, $colourImage[$url] ?? null],
                default => ($c = $this->colourInFileName($url, $matchers, $nameTokens)) ? [self::OTHER_COLOUR, $c] : [self::OTHER_STYLE, null],
            };
            $by = 'auto';
            $review = null;
            $check = $checks[$url] ?? null;
            $seen = $check ? ['tag' => $check->tag, 'colour' => $check->colour_name, 'shows' => $check->shows, 'confidence' => $check->confidence, 'note' => $check->note] : null;
            if ($check && $check->tag === null) {
                $review = $check->note ?: 'Claude could not check this image';
            } elseif ($check && ($check->confidence ?? 0) < $sure) {
                $review = 'Claude is unsure'.($check->note ? ": {$check->note}" : '');
            } elseif ($check) {
                $saw = $check->tag === self::OTHER_COLOUR ? $check->colour_name : null;
                [$role, $colour, $by, $review] = match ($role) {
                    // Promodata's own hero and colour images stay; Claude only flags doubts.
                    self::HERO_STYLE => [$role, $colour, 'auto', $check->shows && ! in_array($check->shows, ['whole_product', 'angle'], true)
                        ? 'Claude: hero shows '.str_replace('_', ' ', $check->shows).($check->note ? ". {$check->note}" : '') : null],
                    self::COLOUR => [$role, $colour, 'auto', $saw && $saw !== $colour ? "Claude sees {$saw}, not {$colour}" : null],
                    // The file name named a colour: keep it when Claude agrees, flag it when not.
                    self::OTHER_COLOUR => $saw === $colour ? [$role, $colour, 'ai', null]
                        : [$role, $colour, 'auto', $saw ? "Claude sees {$saw}, the file name says {$colour}" : "Claude sees the hero's colour; the file name says {$colour}"],
                    // Nothing in the file name: Claude decides.
                    default => $saw ? [self::OTHER_COLOUR, $saw, 'ai', null] : [self::OTHER_STYLE, null, 'ai', null],
                };
            }

            return ['url' => $url, 'role' => $role, 'colour' => $colour, 'by' => $by, 'auto_role' => $role, 'auto_colour' => $colour, 'review' => $review, 'seen' => $seen];
        });

        // Staff tags win. A staff "hero style" replaces the automatic hero.
        $colourNames = $colours->pluck('name')->all();
        $tags = $tags->map(function (array $t) use ($manual, $colourNames) {
            $m = $manual[$t['url']] ?? null;
            if (is_array($m) && isset(self::ROLES[$m['role'] ?? ''])) {
                $needsColour = in_array($m['role'], [self::COLOUR, self::OTHER_COLOUR], true);
                $colour = in_array($m['colour'] ?? null, $colourNames, true) ? $m['colour'] : null;
                $t = ['role' => $m['role'], 'colour' => $needsColour || $m['role'] === self::HERO_STYLE ? $colour : null, 'by' => 'staff', 'review' => null] + $t;
                if ($needsColour && $t['colour'] === null) {
                    $t['role'] = $t['auto_role'] === self::HERO_STYLE ? self::HERO_STYLE : self::OTHER_STYLE;
                    $t['colour'] = $t['auto_colour'];
                }
            }

            return $t;
        });
        $staffHero = $tags->first(fn ($t) => $t['by'] === 'staff' && $t['role'] === self::HERO_STYLE);
        if ($staffHero) {
            $tags = $tags->map(fn ($t) => $t['role'] === self::HERO_STYLE && $t['url'] !== $staffHero['url']
                ? ['role' => $t['colour'] && isset($colourImage[$t['url']]) ? self::COLOUR : self::OTHER_STYLE] + $t : $t);
        }

        // Always exactly one hero: the first style image, else the first colour image.
        if (! $tags->contains('role', self::HERO_STYLE) && $tags->isNotEmpty()) {
            $first = $tags->search(fn ($t) => $t['role'] === self::OTHER_STYLE);
            $first = $first === false ? 0 : $first;
            $tags[$first] = ['role' => self::HERO_STYLE] + $tags[$first];
        }

        return $tags->values();
    }

    /** The hero style URL: what the collection page shows when no colour is picked. */
    public function hero(Product $product): ?string
    {
        return $this->tags($product)->firstWhere('role', self::HERO_STYLE)['url'] ?? null;
    }

    /**
     * What Shopify gets (docs/images.md): product media in order (hero style,
     * other style, then each colour image), and per colour its variant image and
     * other-colour images. Hidden colours are left out.
     *
     * @return array{media: list<string>, style: list<string>, colours: array<string, array{families: list<string>, image: ?string, other: list<string>}>}
     */
    public function forShopify(Product $product): array
    {
        $tags = $this->tags($product);
        $hidden = (array) $product->overrideValue('hidden_colours', []);
        $style = $tags->where('role', self::HERO_STYLE)->merge($tags->where('role', self::OTHER_STYLE))->pluck('url')->values()->all();
        $colours = [];
        foreach ($product->colours->unique('name') as $c) {
            if (in_array($c->name, $hidden, true)) {
                continue;
            }
            $image = $tags->first(fn ($t) => $t['role'] === self::COLOUR && $t['colour'] === $c->name)['url']
                ?? $tags->first(fn ($t) => $t['role'] === self::HERO_STYLE && $t['colour'] === $c->name)['url']
                ?? $c->image_url;
            $colours[$c->name] = [
                'families' => array_values(array_filter((array) $c->appa_colours)),
                'image' => $image,
                'other' => $tags->filter(fn ($t) => $t['role'] === self::OTHER_COLOUR && $t['colour'] === $c->name)->pluck('url')->values()->all(),
            ];
        }
        $hero = $style[0] ?? null;
        foreach ($colours as $name => $c) {
            $colours[$name]['image'] ??= $hero; // a colour without its own picture shows the hero, rather than being dropped as TOD did
        }
        if ($colours === []) {
            // No colours (or all hidden): one "Custom Print" variant showing the hero, as TOD does.
            $colours[self::CUSTOM_PRINT] = ['families' => [], 'image' => $hero, 'other' => []];
        }
        $media = array_values(array_unique(array_merge($style, array_filter(array_column($colours, 'image')))));

        return ['media' => $media, 'style' => $style, 'colours' => $colours];
    }

    /** @param Collection<int, array{name:string, tokens:list<string>}> $matchers */
    private function colourInFileName(string $url, Collection $matchers, array $nameTokens): ?string
    {
        $file = $this->tokens(preg_replace('/\.[a-z0-9]{3,4}$/i', '', basename(parse_url($url, PHP_URL_PATH) ?: $url)));
        $file = array_values(array_filter($file, fn ($t) => ! ctype_digit($t)));
        foreach ($matchers as $m) {
            // A colour word that is also in the product's name (e.g. "Natural Jute Bag") proves nothing.
            if (count($m['tokens']) === 1 && in_array($m['tokens'][0], $nameTokens, true)) {
                continue;
            }
            if ($this->containsRun($file, $m['tokens'])) {
                return $m['name'];
            }
        }

        return null;
    }

    /** True when $needle appears as consecutive tokens in $hay; long words may be off by one letter (pistacio). */
    private function containsRun(array $hay, array $needle): bool
    {
        $n = count($needle);
        for ($i = 0; $i + $n <= count($hay); $i++) {
            $ok = true;
            for ($j = 0; $j < $n; $j++) {
                $a = $hay[$i + $j];
                $b = $needle[$j];
                if ($a !== $b && ! (mb_strlen($b) >= 6 && levenshtein($a, $b) <= 1)) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function tokens(?string $text): array
    {
        $t = mb_strtolower((string) $text);
        $t = str_replace(['grey'], ['gray'], $t);

        return array_values(array_filter(preg_split('/[^a-z0-9]+/', $t) ?: [], fn ($w) => $w !== ''));
    }
}
