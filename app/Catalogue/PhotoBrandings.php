<?php

namespace App\Catalogue;

use App\Models\PhotoBranding;
use App\Models\Product;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Example branding on the colour photos a mockup is drawn on (Scott, 5 Nov 2026; docs/branding-templates.md,
 * "Photos with example branding"). Suppliers often shoot a product with a sample logo or text on it; a client's
 * logo drawn over (or beside) that would mislead. Claude (Haiku) looks at each photo once:
 *
 *   - no example branding: the photo is used;
 *   - small branding on a plain surface, inside a print area: the product page paints that patch over with the
 *     surrounding colour first, then draws the client's logo;
 *   - anything else (large, textured, outside every print area): the photo isn't used for mockups, so the
 *     position gets the guide card or the no-image note.
 *
 * A photo not checked yet is never used for a mockup. A colour photo that is also the hero counts as a colour
 * photo once it is checked and clean or fixable.
 */
class PhotoBrandings
{
    public const NONE = 'none';

    public const SMALL = 'small';

    public const LARGE = 'large';

    /** How far (percent of the photo) branding may sit outside a print area and still be painted over. */
    private const MARGIN = 6.0;

    public function __construct(private readonly ImageTagger $images) {}

    public function enabled(): bool
    {
        return (bool) config('images.branding.enabled') && filled(config('search.anthropic.key'));
    }

    public function canCheck(): bool
    {
        return $this->enabled() && (int) Cache::get($this->dayKey(), 0) < (int) config('images.branding.per_day', 20000);
    }

    /**
     * The photos a mockup could be drawn on: each colour's own photo (front; a colour photo that is the hero
     * counts) and that colour's first other photo (back), as the product page picks them.
     *
     * @return list<array{url: string, colour: string, view: string}>
     */
    public function photos(Product $product): array
    {
        $product->loadMissing(['images', 'colours', 'override']);
        $tags = $this->images->tags($product)->keyBy('url');
        $out = [];
        foreach ($this->images->forShopify($product)['colours'] as $name => $c) {
            $front = $c['image'] ?? null;
            $t = $front ? ($tags[$front] ?? null) : null;
            if (! $t || ($t['colour'] ?? null) !== $name || ! in_array($t['role'], [ImageTagger::COLOUR, ImageTagger::HERO_STYLE], true)) {
                continue; // no picture of this colour of its own (it shows the hero or a style photo): never a mockup
            }
            $out[] = ['url' => $front, 'colour' => (string) $name, 'view' => 'front'];
            if ($back = $c['other'][0] ?? null) {
                $out[] = ['url' => $back, 'colour' => (string) $name, 'view' => 'back'];
            }
        }

        return $out;
    }

    /** @return list<string> photo URLs not checked yet */
    public function unchecked(Product $product): array
    {
        $urls = array_values(array_unique(array_column($this->photos($product), 'url')));
        if ($urls === []) {
            return [];
        }
        $done = PhotoBranding::query()->whereIn('url_hash', array_map(PhotoBranding::hashOf(...), $urls))->pluck('url')->all();

        return array_values(array_diff($urls, $done));
    }

    /** Check one product's photos now (before its template is read, so the reading uses a cleared photo). */
    public function ensureChecked(Product $product): int
    {
        return $this->enabled() && $this->unchecked($product) !== [] ? ($this->checkMany([$product])[$product->id] ?? 0) : 0;
    }

    /**
     * Check the unchecked photos of these products, $parallel requests at a time.
     *
     * @param  iterable<Product>  $products
     * @return array<int, int> product id => photos checked
     */
    public function checkMany(iterable $products, int $parallel = 1): array
    {
        $jobs = [];
        $out = [];
        foreach ($products as $product) {
            $out[$product->id] = 0;
            foreach (array_chunk($this->unchecked($product), (int) config('images.branding.images_per_request', 16)) as $chunk) {
                $jobs[] = ['product' => $product, 'urls' => $chunk];
            }
        }
        foreach (array_chunk($jobs, max(1, $parallel)) as $batch) {
            if (! $this->canCheck()) {
                break;
            }
            Cache::add($this->dayKey(), 0, now()->endOfDay());
            Cache::increment($this->dayKey(), array_sum(array_map(fn ($j) => count($j['urls']), $batch)));
            try {
                $responses = count($batch) === 1
                    ? [$this->request($batch[0]['urls'])]
                    : Http::pool(fn ($pool) => array_map(fn ($i) => $this->headers($pool->as((string) $i))
                        ->post('https://api.anthropic.com/v1/messages', $this->body($batch[$i]['urls'])), array_keys($batch)));
            } catch (Throwable) {
                continue; // network trouble: tried again next run
            }
            foreach (array_values($batch) as $i => $job) {
                $r = $responses[$i] ?? $responses[(string) $i] ?? null;
                $out[$job['product']->id] += $this->save($job['product'], $job['urls'], $r);
            }
        }

        return $out;
    }

    /**
     * What the product page gets (custom.mockupphotos): per colour, per side, whether its photo can carry a mockup.
     * "ok", "skip", or [left, top, width, height] of example branding to paint over first. A photo not checked yet
     * is left out, and the page draws no mockup on it.
     *
     * @param  list<array<string, mixed>>  $positions  custom.brandingpositions
     * @return array<string, array<string, string|list<float>>>
     */
    public function forShopify(Product $product, array $positions): array
    {
        $photos = $this->photos($product);
        if ($photos === []) {
            return [];
        }
        $rows = PhotoBranding::query()->whereIn('url_hash', array_map(fn ($p) => PhotoBranding::hashOf($p['url']), $photos))->get()->keyBy('url');
        $out = [];
        foreach ($photos as $p) {
            if ($row = $rows[$p['url']] ?? null) {
                $out[$p['colour']][$p['view']] = $this->verdict($row, self::areas($positions, $p['view']));
            }
        }

        return $out;
    }

    /** A photo the template reader may place print areas on: checked, and clean or fixable. */
    public function usable(string $url): bool
    {
        if (! $this->enabled()) {
            return true; // the check is off: as before
        }
        $row = PhotoBranding::query()->find(PhotoBranding::hashOf($url));

        return $row && ($row->branding === self::NONE || ($row->branding === self::SMALL && $row->plain && $row->box));
    }

    /** @param  list<list<float>>  $areas  print areas on this side, [left, top, width, height] */
    public function verdict(PhotoBranding $row, array $areas): string|array
    {
        if ($row->branding === self::NONE) {
            return 'ok';
        }
        $box = $row->box;
        if ($row->branding !== self::SMALL || ! $row->plain || ! is_array($box) || count($box) !== 4) {
            return 'skip';
        }
        [$l, $t, $w, $h] = array_map('floatval', $box);
        foreach ($areas as [$al, $at, $aw, $ah]) {
            $m = self::MARGIN;
            if ($l >= $al - $m && $t >= $at - $m && $l + $w <= $al + $aw + $m && $t + $h <= $at + $ah + $m) {
                // Inside where the client's logo goes: paint it over, a little wider than Claude's box.
                $pad = 1.5;

                return [round(max(0, $l - $pad), 1), round(max(0, $t - $pad), 1), round(min(100 - max(0, $l - $pad), $w + 2 * $pad), 1), round(min(100 - max(0, $t - $pad), $h + 2 * $pad), 1)];
            }
        }

        return 'skip'; // example branding where the client's logo won't cover it
    }

    /**
     * Print areas on one side, as boxes in percent of the photo.
     *
     * @param  list<array<string, mixed>>  $positions
     * @return list<list<float>>
     */
    public static function areas(array $positions, string $view): array
    {
        $out = [];
        foreach ($positions as $p) {
            if (($p['view'] ?? 'front') !== $view) {
                continue;
            }
            if (is_array($p['quad'] ?? null) && count($p['quad']) === 4) {
                $xs = array_column($p['quad'], 0);
                $ys = array_column($p['quad'], 1);
                $out[] = [(float) min($xs), (float) min($ys), (float) (max($xs) - min($xs)), (float) (max($ys) - min($ys))];
            } elseif (isset($p['left'], $p['top'], $p['width'])) {
                $mm = $p['max_mm'] ?? null;
                $ratio = is_array($mm) && (float) ($mm[0] ?? 0) > 0 && (float) ($mm[1] ?? 0) > 0 ? (float) $mm[1] / (float) $mm[0] : 0.6;
                $out[] = [(float) $p['left'], (float) $p['top'], (float) $p['width'], (float) $p['width'] * $ratio];
            }
        }

        return $out;
    }

    /** @param list<string> $urls */
    private function request(array $urls): Response
    {
        return $this->headers(Http::getFacadeRoot())->post('https://api.anthropic.com/v1/messages', $this->body($urls));
    }

    private function headers(mixed $request): mixed
    {
        return $request->withHeaders(['x-api-key' => config('search.anthropic.key'), 'anthropic-version' => '2023-06-01'])
            ->timeout((int) config('images.branding.timeout', 60));
    }

    private function dayKey(): string
    {
        return 'photo-branding:'.now()->format('Ymd');
    }

    /** @param list<string> $urls */
    private function body(array $urls): array
    {
        $content = [];
        foreach ($urls as $n => $url) {
            $content[] = ['type' => 'text', 'text' => 'IMAGE '.($n + 1).':'];
            $content[] = ['type' => 'image', 'source' => ['type' => 'url', 'url' => $url]];
        }

        return [
            'model' => config('images.branding.model'),
            'max_tokens' => 100 + 60 * count($urls),
            'system' => 'You check product photos for a branded merchandise store before a customer\'s logo is drawn on them. '
                .'For each image, is there EXAMPLE BRANDING printed or engraved on the product: a sample logo, sample text or a sample print '
                .'that a customer would not get (not the product\'s own small maker\'s mark moulded into it, not a label or swing tag, '
                .'not stitching or a texture pattern)? '
                .'"branding": "none" if there is none; "small" if it is one compact mark that could be painted over; "large" if it covers much of the product, '
                .'is spread over several places, or is a full-colour or all-over print. '
                .'"box": where it is, [left, top, width, height] in percent of the whole image (0-100), covering all of it; null for none. '
                .'"plain": true if the surface around the branding is a smooth single colour (gentle shading is fine), false if it is textured, '
                .'patterned, ribbed, knitted, mesh, transparent or crosses an edge or seam. '
                .'Reply with JSON only: {"images":[{"n":number,"branding":"none"|"small"|"large","box":[number,number,number,number]|null,"plain":boolean,"note":string}]}. '
                .'Notes under 10 words; empty when there is no branding.',
            'messages' => [['role' => 'user', 'content' => $content]],
        ];
    }

    /** @param list<string> $urls */
    private function save(Product $product, array $urls, mixed $r): int
    {
        if (! $r instanceof Response) {
            return 0; // timed out: tried again next run
        }
        if (! $r->successful()) {
            if ($r->status() === 400 && count($urls) === 1) {
                // A photo Claude can't load (gone, too big): recorded, and never used for mockups.
                $this->store($product, $urls[0], ['branding' => null, 'note' => 'Could not load: '.mb_substr((string) $r->json('error.message'), 0, 80)]);

                return 1;
            }
            $n = 0;
            if ($r->status() === 400) {
                foreach ($urls as $url) { // one bad photo spoils the request: try each on its own
                    $n += rescue(fn () => $this->save($product, [$url], $this->request([$url])), 0, false);
                }
            }

            return $n;
        }
        $text = (string) collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode('');
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        $json = $start !== false && $end !== false ? json_decode(substr($text, $start, $end - $start + 1), true) : null;
        if (! is_array($json['images'] ?? null)) {
            return 0;
        }
        $n = 0;
        foreach ($json['images'] as $row) {
            $url = $urls[((int) ($row['n'] ?? 0)) - 1] ?? null;
            if ($url === null) {
                continue;
            }
            $branding = in_array($row['branding'] ?? null, [self::NONE, self::SMALL, self::LARGE], true) ? $row['branding'] : null;
            $box = self::box($row['box'] ?? null);
            if ($branding === self::SMALL && $box === null) {
                $branding = self::LARGE; // branding with no place given can't be painted over
            }
            $this->store($product, $url, [
                'branding' => $branding,
                'box' => $branding === self::NONE ? null : $box,
                'plain' => isset($row['plain']) ? (bool) $row['plain'] : null,
                'note' => mb_substr(strip_tags((string) ($row['note'] ?? '')), 0, 200) ?: null,
            ]);
            $n++;
        }

        return $n;
    }

    private function store(Product $product, string $url, array $fields): void
    {
        PhotoBranding::query()->updateOrCreate(['url_hash' => PhotoBranding::hashOf($url)], $fields + [
            'url' => $url, 'product_id' => $product->id, 'model' => config('images.branding.model'), 'checked_at' => now(),
        ]);
    }

    /** @return list<float>|null */
    public static function box(mixed $b): ?array
    {
        if (! is_array($b) || count($b) !== 4 || array_filter($b, fn ($v) => ! is_numeric($v)) !== []) {
            return null;
        }
        [$l, $t, $w, $h] = array_map(fn ($v) => max(0.0, min(100.0, (float) $v)), array_values($b));
        if ($w <= 0 || $h <= 0) {
            return null;
        }

        return [round($l, 1), round($t, 1), round(min($w, 100 - $l), 1), round(min($h, 100 - $t), 1)];
    }
}
