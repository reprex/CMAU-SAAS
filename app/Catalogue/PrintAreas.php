<?php

namespace App\Catalogue;

use App\Models\PrintArea;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Logo preview on the website (docs/logo-preview.md). Each product's hero image
 * has a branding area: left, top and width as a share of the picture. The site
 * shows the CrowdMerch logo there, and the shopper's own logo replaces it in the
 * same place. Areas start from a default for the product type or a Claude
 * suggestion, and staff vet each one (drag and resize the logo, then approve).
 */
class PrintAreas
{
    /** Default areas by product type keyword: [left, top, width] in %. First match wins. */
    public const DEFAULTS = [
        'cap|hat|beanie|visor' => [36, 34, 28],
        'shirt|tee|polo|hoodie|jumper|sweat|jacket|vest|singlet|top' => [37, 30, 26],
        'bag|tote|backpack|satchel|cooler' => [32, 44, 36],
        'bottle|mug|cup|tumbler|flask|glass' => [38, 42, 24],
        'pen|pencil|highlighter|stylus' => [30, 46, 40],
        'lanyard|strap' => [25, 45, 50],
        'notebook|journal|folder|compendium' => [33, 38, 34],
    ];

    public const FALLBACK = [33, 40, 34];

    public function heroOf(Product $product): ?string
    {
        return app(ImageTagger::class)->hero($product->loadMissing(['images', 'colours', 'override']));
    }

    /** The stored area for the product's hero image, or null. */
    public function find(Product $product): ?PrintArea
    {
        $hero = $this->heroOf($product);

        return $hero ? PrintArea::query()->find(sha1($hero)) : null;
    }

    /** @return array{0: float, 1: float, 2: float} */
    public function default(Product $product): array
    {
        $type = mb_strtolower($product->promodata_type_name.' '.$product->name);
        foreach (self::DEFAULTS as $words => $area) {
            if (preg_match('/\b('.$words.')s?\b/', $type)) {
                return $area;
            }
        }

        return self::FALLBACK;
    }

    /** The area to show: stored (vetted or suggested), else the type default. */
    public function areaFor(Product $product): array
    {
        $a = $this->find($product);

        return $a ? [$a->left, $a->top, $a->width] : $this->default($product);
    }

    public function approve(Product $product, float $left, float $top, float $width, int $userId): PrintArea
    {
        $hero = $this->heroOf($product);
        if (! $hero) {
            throw new RuntimeException('This product has no hero image');
        }
        $area = PrintArea::query()->updateOrCreate(['url_hash' => sha1($hero)], [
            'url' => $hero, 'product_id' => $product->id,
            'left' => round(max(0, min(95, $left)), 2), 'top' => round(max(0, min(95, $top)), 2),
            'width' => round(max(3, min(100, $width)), 2),
            'source' => 'staff', 'approved_by' => $userId, 'approved_at' => now(),
        ]);
        if ($product->tier === Product::TIER_LIVE) {
            $product->forceFill(['range_changed_at' => now()])->save();
        }

        return $area;
    }

    /**
     * Ask Claude where the branding goes on the hero image (one image, one
     * call). Stored as a suggestion for staff to vet; never overwrites a vetted area.
     */
    public function suggest(Product $product): ?PrintArea
    {
        $hero = $this->heroOf($product);
        if (! $hero || ! filled(config('search.anthropic.key'))) {
            return null;
        }
        $existing = PrintArea::query()->find(sha1($hero));
        if ($existing) {
            return $existing;
        }
        $dayKey = 'image-check:'.now()->format('Ymd'); // shares the image check's daily cap
        if ((int) Cache::get($dayKey, 0) >= (int) config('images.check.images_per_day')) {
            return null;
        }
        Cache::add($dayKey, 0, now()->endOfDay());
        Cache::increment($dayKey);

        $r = Http::withHeaders(['x-api-key' => config('search.anthropic.key'), 'anthropic-version' => '2023-06-01'])
            ->timeout((int) config('images.check.timeout'))
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => config('images.check.model'),
                'max_tokens' => 120,
                'system' => 'You place a company logo on product photos for a branded merchandise store. '
                    .'Give the usual branding area for this product (e.g. front centre of a tote, left chest of a polo, front panel of a cap) '
                    .'as a box in percent of the image: left and top of the box, and its width. Reply with JSON only: {"left":n,"top":n,"width":n}.',
                'messages' => [['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => "Product: {$product->name} ({$product->promodata_type_name})."],
                    ['type' => 'image', 'source' => ['type' => 'url', 'url' => $hero]],
                ]]],
            ]);
        if (! $r->successful()) {
            throw new RuntimeException('Claude print area failed: '.$r->status());
        }
        $json = json_decode(trim(preg_replace('/^```(?:json)?|```$/m', '', (string) collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode(''))), true);
        if (! is_array($json) || ! is_numeric($json['left'] ?? null) || ! is_numeric($json['top'] ?? null) || ! is_numeric($json['width'] ?? null)) {
            throw new RuntimeException('Claude print area reply was not usable');
        }

        return PrintArea::query()->create([
            'url_hash' => sha1($hero), 'url' => $hero, 'product_id' => $product->id,
            'left' => round(max(0, min(95, (float) $json['left'])), 2), 'top' => round(max(0, min(95, (float) $json['top'])), 2),
            'width' => round(max(3, min(100, (float) $json['width'])), 2), 'source' => 'ai',
        ]);
    }

    /**
     * What Shopify gets: product metafield `custom.printarea` (json), only once
     * vetted: {"image": url, "left": %, "top": %, "width": %}.
     *
     * @return array{image: string, left: float, top: float, width: float}|null
     */
    public function forShopify(Product $product): ?array
    {
        $a = $this->find($product);
        if (! $a || ! $a->approved_at) {
            return null;
        }

        return ['image' => $a->url, 'left' => $a->left, 'top' => $a->top, 'width' => $a->width];
    }
}
