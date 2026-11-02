<?php

namespace App\Catalogue;

use App\Models\DecTypeCode;
use App\Models\PositionRule;
use App\Models\PriceOption;
use App\Models\PrintArea;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Every product's branding positions and logo area, automatically (Scott,
 * 1 Nov 2026; docs/logo-preview.md). This replaces vetting ~100 logo areas by
 * hand. Priority:
 *
 *   1. a staff-approved logo area (Range › Logo areas, still available);
 *   2. the supplier's branding template, read by Claude (BrandingTemplates);
 *   3. the print areas in Promodata's own decoration data (DecorationAreas);
 *   4. the product type's default box (PrintAreas::DEFAULTS).
 *
 * Each position carries its allowed methods and, where the data gives one, the
 * maximum size for each method (e.g. DTF 297 × 420, Oversize DTF 380 × 480 on
 * the same front). Its box on our picture is the type default, scaled by the
 * print width against the product's size when Promodata gives the dimensions.
 */
class PositionResolver
{
    /** The product's largest dimension spans about this share of a square product photo. */
    public const PHOTO_FILL = 80.0;

    /** Default position name when the decoration text doesn't name one: type keyword => name. */
    public const DEFAULT_POSITION = [
        'stubby|stubbie|can cooler|can holder|koozie|bottle cooler' => 'Wrap around',
        'pen|pencil|highlighter|stylus' => 'Barrel',
    ];

    /** Box tweaks for named positions on the front picture: centre across (%), width cap (%). */
    private const FRONT_BOXES = [
        'Left chest' => [62, 14],
        'Right chest' => [38, 14],
        'Pocket' => [62, 14],
    ];

    public function __construct(private readonly PrintAreas $areas, private readonly BrandingTemplates $templates) {}

    /**
     * Positions from Promodata's decoration data (price lines, decoration
     * options and print-area details), with their methods and boxes.
     *
     * @return list<array<string, mixed>>
     */
    public function fromDecorations(Product $product): array
    {
        $product->loadMissing(['detail', 'webPrice']);
        $texts = []; // [text, method hint]
        foreach ($product->priceLines()->where('undecorated', false)->orderBy('position')->get(['description', 'promodata_decoration']) as $l) {
            $texts[] = [(string) $l->description, $l->promodata_decoration];
        }
        $options = $this->decorationOptions($product);
        foreach ($options as $o) {
            $texts[] = [(string) ($o->display_description ?: $o->description), $o->promodata_decoration];
        }
        foreach ((array) ($product->detail?->details ?? []) as $d) {
            if (is_array($d) && preg_match('/print|imprint|branding|decorat|logo|engrav|embroider/i', (string) ($d['name'] ?? '')) && ! preg_match('/dimension|carton|packag/i', (string) ($d['name'] ?? ''))) {
                $texts[] = [(string) ($d['detail'] ?? ''), (string) ($d['name'] ?? '')];
            }
        }

        $areas = [];
        $methodsOnly = [];
        $seen = [];
        foreach ($texts as [$text, $hint]) {
            $key = mb_strtolower(trim($text)).'|'.$hint;
            if (trim($text) === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $found = DecorationAreas::parse($text, $hint);
            if ($found === []) {
                $m = DecorationAreas::method($text) ?? ($hint ? DecorationAreas::method($hint) : null);
                if ($m !== null && ! preg_match('/front\s*(?:and|&|\+)\s*back|two positions|2 positions/i', $text)) {
                    $methodsOnly[$m] = true; // a method with no size, e.g. "Embroidery up to 8000 stitches"
                }
            }
            array_push($areas, ...$found);
        }
        if ($areas === []) {
            return [];
        }

        // A size with no method (a "Print Area" detail) applies to the methods the product offers.
        $known = array_keys($methodsOnly + array_flip(array_filter(array_column($areas, 'method'))));
        $expanded = [];
        foreach ($areas as $a) {
            if ($a['method'] === null && $known !== []) {
                foreach ($known as $m) {
                    $expanded[] = ['method' => $m] + $a;
                }
            } else {
                $expanded[] = $a;
            }
        }

        $default = $this->defaultPositionName($product);
        $positions = [];
        foreach ($expanded as $a) {
            $name = $a['position'] ?? $default;
            $k = mb_strtolower($name);
            $positions[$k] ??= ['name' => $name, 'view' => $a['view'] ?? DecorationAreas::viewOf($name), 'shape' => $a['shape'], 'methods' => []];
            $mk = $a['method'] ?? '';
            $was = $positions[$k]['methods'][$mk]['max_mm'] ?? null;
            if ($was === null || $a['width_mm'] * ($a['height_mm'] ?? $a['width_mm']) > $was[0] * ($was[1] ?? $was[0])) {
                $positions[$k]['methods'][$mk] = ['method' => $a['method'], 'name' => DecorationAreas::methodName($a['method']), 'max_mm' => [$a['width_mm'], $a['height_mm']]];
            }
        }
        // Methods with no size of their own are still offered, on the default position (else the first).
        $target = isset($positions[mb_strtolower($default)]) ? mb_strtolower($default) : array_key_first($positions);
        foreach (array_keys($methodsOnly) as $m) {
            $positions[$target]['methods'][$m] ??= ['method' => $m, 'name' => DecorationAreas::methodName($m), 'max_mm' => null];
        }

        $out = [];
        foreach (array_values($positions) as $p) {
            $methods = array_values($p['methods']);
            foreach ($methods as $j => $m) {
                $methods[$j]['decIDs'] = $m['method'] === null ? [] : $options
                    ->filter(fn (PriceOption $o) => DecorationAreas::method((string) ($o->display_description ?: $o->description)) === $m['method'])
                    ->pluck('option_id')->values()->all();
            }
            $sized = array_filter(array_column($methods, 'max_mm'));
            usort($sized, fn ($a, $b) => $b[0] * ($b[1] ?? $b[0]) <=> $a[0] * ($a[1] ?? $a[0]));
            $max = $sized[0] ?? null;
            $out[] = [
                'name' => $p['name'],
                'view' => in_array($p['view'], BrandingTemplates::VIEWS, true) ? $p['view'] : 'front',
                'max_mm' => $max,
                'methods' => array_values(array_unique(array_map(fn ($m) => $m['name'], array_filter($methods, fn ($m) => $m['method'] !== null)))),
                'method_areas' => $methods,
                'source' => 'decoration',
            ] + $this->box($product, $p['name'], $p['view'], $max);
        }

        return $out;
    }

    /**
     * What Shopify gets as custom.brandingpositions: the template's positions
     * if one was read, else the decoration data's, else the type default.
     *
     * @return list<array<string, mixed>>
     */
    public function forShopify(Product $product): array
    {
        $template = $this->templates->forShopify($product);
        $rule = PositionRule::for($product);
        if ($template !== []) {
            $positions = array_map(fn ($p) => $p + [
                'method_areas' => array_map(fn ($name) => ['method' => DecorationAreas::method($name), 'name' => $name, 'max_mm' => $p['max_mm'] ?? null], $p['methods'] ?? []),
                'source' => 'template',
            ], $template);

            return $rule ? $this->fromRule($product, $rule, $positions) : $positions; // staff positions beat the template
        }
        $positions = $this->withGarmentPositions($product, $this->fromDecorations($product) ?: $this->typeDefault($product));
        if ($positions !== [] && $rule) {
            $positions = $this->fromRule($product, $rule, $positions);
        }
        $staff = $this->staffArea($product);
        foreach ($positions as $i => $p) {
            if ($staff && $p['view'] === 'front') { // staff placed the logo by hand: that wins on the first front position
                $positions[$i] = array_merge($p, ['image' => $staff->url, 'left' => $staff->left, 'top' => $staff->top, 'width' => $staff->width]);
                break;
            }
        }

        return $positions;
    }

    /**
     * The logo area from the decoration data: the first front position's box
     * on the hero image, or null.
     *
     * @return array{image: string, left: float, top: float, width: float}|null
     */
    public function decorationLogoArea(Product $product): ?array
    {
        $hero = $this->areas->heroOf($product);
        foreach ($this->fromDecorations($product) as $p) {
            if ($hero && ($p['image'] ?? null) === $hero) {
                return ['image' => $hero, 'left' => $p['left'], 'top' => $p['top'], 'width' => $p['width']];
            }
        }

        return null;
    }

    /** The product has decoration options at all (a product nobody can brand gets no logo preview). */
    public function isBrandable(Product $product): bool
    {
        return $product->priceLines()->where('undecorated', false)->exists() || $this->decorationOptions($product)->isNotEmpty();
    }

    /**
     * The last resort: one position named for the product type, at the type
     * default box, with the methods the product's decoration options name.
     *
     * @return list<array<string, mixed>>
     */
    public function typeDefault(Product $product): array
    {
        if (! $this->isBrandable($product)) {
            return [];
        }
        $options = $this->decorationOptions($product);
        $methods = $options->map(fn (PriceOption $o) => DecorationAreas::method((string) ($o->display_description ?: $o->description)))->filter()->unique()->values();
        if ($methods->isEmpty()) {
            $methods = $product->priceLines()->where('undecorated', false)->get(['description', 'promodata_decoration'])
                ->map(fn ($l) => DecorationAreas::method((string) $l->description) ?? DecorationAreas::method((string) $l->promodata_decoration))->filter()->unique()->values();
        }
        $name = $this->defaultPositionName($product);

        return [[
            'name' => $name,
            'view' => 'front',
            'max_mm' => null,
            'methods' => $methods->map(fn ($m) => DecorationAreas::methodName($m))->all(),
            'method_areas' => $methods->map(fn ($m) => [
                'method' => $m, 'name' => DecorationAreas::methodName($m), 'max_mm' => null,
                'decIDs' => $options->filter(fn (PriceOption $o) => DecorationAreas::method((string) ($o->display_description ?: $o->description)) === $m)->pluck('option_id')->values()->all(),
            ])->all(),
            'source' => 'default',
        ] + $this->box($product, $name, 'front', null)];
    }

    public function defaultPositionName(Product $product): string
    {
        $type = mb_strtolower($product->promodata_type_name.' '.$product->name);
        foreach (self::DEFAULT_POSITION as $words => $name) {
            if (preg_match('/\b('.$words.')(s|es)?\b/', $type)) {
                return $name;
            }
        }

        return 'Front';
    }

    /**
     * Where a position sits on our picture: the type default box, its width
     * scaled by the print width against the product's largest dimension (when
     * Promodata gives dimensions), kept on the default's centre. A wrap is seen
     * across the front only, about its width ÷ π. No picture for the view: no box.
     *
     * @param  array{0: int, 1: ?int}|null  $maxMm
     * @return array{image?: string, left?: float, top?: float, width?: float}
     */
    public function box(Product $product, string $name, string $view, ?array $maxMm): array
    {
        $image = $view === 'front' ? $this->areas->heroOf($product) : ($view === 'back' ? $this->templates->pictureFor($product, 'back') : null);
        if (! $image) {
            return [];
        }
        [$left, $top, $width] = $this->areas->default($product);
        $centre = $left + $width / 2;
        if ($view === 'front' && isset(self::FRONT_BOXES[$name])) {
            [$centre, $cap] = self::FRONT_BOXES[$name];
            $width = min($width, $cap);
        }
        $largest = (float) ($product->dim_1 ?? 0);
        if ($maxMm !== null && $largest > 0) {
            $printW = (float) $maxMm[0] / ($name === 'Wrap around' ? M_PI : 1);
            $width = max(5.0, min(80.0, $printW / $largest * self::PHOTO_FILL));
        }
        $left = max(0.0, min(100.0 - $width, $centre - $width / 2));

        return ['image' => $image, 'left' => round($left, 2), 'top' => round((float) $top, 2), 'width' => round((float) $width, 2)];
    }

    /**
     * Staff positions (Range › Positions) replace the automatic ones. Each keeps the
     * decoration methods the product offers, capped at the size staff set, and the box
     * of the automatic position of the same name (else the type default box).
     *
     * @param  list<array<string, mixed>>  $auto
     * @return list<array<string, mixed>>
     */
    public function fromRule(Product $product, PositionRule $rule, array $auto): array
    {
        $byName = collect($auto)->keyBy(fn ($p) => mb_strtolower((string) $p['name']));
        $base = collect($auto)->first(fn ($p) => ($p['view'] ?? 'front') === 'front') ?? $auto[0];
        $out = [];
        foreach ((array) $rule->positions as $r) {
            $name = trim((string) ($r['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $view = in_array($r['view'] ?? null, PositionRule::VIEWS, true) ? $r['view'] : 'front';
            $max = ! empty($r['width_mm']) ? [(int) $r['width_mm'], ! empty($r['height_mm']) ? (int) $r['height_mm'] : null] : null;
            $same = $byName->get(mb_strtolower($name));
            $from = $same ?? $base;
            $areas = array_map(fn ($a) => $max ? array_merge($a, ['max_mm' => $max]) : $a, $from['method_areas'] ?? []);
            $box = $same && isset($same['width']) && ($same['view'] ?? 'front') === $view
                ? array_intersect_key($same, array_flip(['image', 'left', 'top', 'width']))
                : $this->box($product, $name, $view, $max);
            $out[] = [
                'name' => $name, 'view' => $view, 'max_mm' => $max ?? ($from['max_mm'] ?? null),
                'methods' => $from['methods'] ?? [], 'method_areas' => $areas, 'source' => 'staff',
            ] + $box;
        }

        return $out ?: $auto;
    }

    /** Garments whose decoration data names only the front still print on the back, sleeves and neck. */
    public const GARMENT = '/\\b(t-?shirts?|tees?|shirts?|polos?|hood(ie|y|ies)s?|jumpers?|sweat\\w*|crews?|singlets?|tanks?|jackets?|vests?|long ?sleeves?)\\b/i';

    /**
     * Back, sleeves and back neck for garments (Scott, 2 Nov 2026: tees must offer
     * printing on the back). Each copies the front's methods; the back keeps the
     * front's size, the small positions are capped at 100 × 100 mm (sleeves) and
     * 80 × 40 mm (back neck). Positions the data already names are left alone.
     *
     * @param  list<array<string, mixed>>  $positions
     * @return list<array<string, mixed>>
     */
    public function withGarmentPositions(Product $product, array $positions): array
    {
        if ($positions === [] || ! preg_match(self::GARMENT, $product->promodata_type_name.' '.$product->name)) {
            return $positions;
        }
        $front = collect($positions)->first(fn ($p) => ($p['view'] ?? 'front') === 'front');
        if ($front === null) {
            return $positions;
        }
        $have = collect($positions)->map(fn ($p) => mb_strtolower((string) $p['name']))->all();
        $cap = fn (array $areas, ?array $max) => array_map(fn ($a) => array_merge($a, ['max_mm' => $max === null ? ($a['max_mm'] ?? null) : $max]), $areas);
        $extra = [
            // name, view, max size (null = the front's), box on the picture (left, top, width %)
            ['Back', 'back', null, [$front['left'] ?? 32, $front['top'] ?? 26, $front['width'] ?? 36]],
            ['Left sleeve', 'front', [100, 100], [78, 24, 9]],
            ['Right sleeve', 'front', [100, 100], [13, 24, 9]],
            ['Back neck', 'back', [80, 40], [44, 14, 12]],
        ];
        foreach ($extra as [$name, $view, $max, [$l, $t, $w]]) {
            if (in_array(mb_strtolower($name), $have, true)) {
                continue;
            }
            $positions[] = [
                'name' => $name,
                'view' => $view,
                'max_mm' => $max ?? ($front['max_mm'] ?? null),
                'methods' => $front['methods'] ?? [],
                'method_areas' => $cap($front['method_areas'] ?? [], $max),
                'source' => 'garment',
                'image' => $view === 'front' ? ($front['image'] ?? null) : $this->templates->pictureFor($product, 'back'),
                'left' => $l, 'top' => $t, 'width' => $w,
            ];
        }

        return $positions;
    }

    /** @return Collection<int, PriceOption> */
    private function decorationOptions(Product $product)
    {
        $id = (int) ($product->webPrice?->decoration_product_id ?: $product->id);

        return PriceOption::query()->where('product_id', $id)->where('type_code', DecTypeCode::DECORATION)->orderBy('position')->get();
    }

    private function staffArea(Product $product): ?PrintArea
    {
        $a = $this->areas->find($product);

        return $a && $a->approved_at ? $a : null;
    }
}
