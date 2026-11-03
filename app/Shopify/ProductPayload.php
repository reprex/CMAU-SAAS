<?php

namespace App\Shopify;

use App\Catalogue\BrandingTemplates;
use App\Catalogue\GarmentSizes;
use App\Catalogue\ImageTagger;
use App\Catalogue\OurCodes;
use App\Catalogue\PositionResolver;
use App\Catalogue\PrintAreas;
use App\Catalogue\SizeGuide;
use App\Catalogue\SupplierCodes;
use App\Models\Product;
use Illuminate\Support\Str;

/**
 * Builds the Shopify productSet input for one product, in the shape the CMAU
 * theme reads (docs/contracts/shopify-product.md) plus the new additive fields
 * for the new templates. Pure: no Shopify calls, so it can be previewed and tested.
 *
 * Not yet produced (TOD's tagging tables, "tagging and collections" module):
 * category codes, special and collection tags, essentials/urgent/ecorec
 * subcategories and the master sort order beyond its default. Those fields are
 * sent as "any," or their default so the theme's filters still work.
 */
class ProductPayload
{
    public const NO_STOCK_MESSAGE = 'This product is routinely kept in stock in large quantities in all colours. We will confirm again at the time of order placement.';

    /** Branding option tags (custom.tagbrandingoption) from decoration names. */
    private const BRANDING = [
        'embroider' => '/embroider/i',
        'engrave' => '/engrav|laser/i',
        'fullcolour' => '/full ?colou?r|digital|dtf|transfer|cmyk|uv print/i',
        'spotcolour' => '/screen|pad print|spot|[0-9] ?colou?r/i',
        'foil' => '/foil/i',
        'embossdeboss' => '/emboss|deboss/i',
        'sublimation' => '/sublimat/i',
    ];

    public function __construct(
        private readonly ImageTagger $images,
        private readonly SupplierCodes $codes,
        private readonly PrintAreas $areas,
        private readonly BrandingTemplates $templates,
        private readonly PositionResolver $positions,
    ) {}

    /**
     * @param  array<string, string>  $mediaIds  image url => existing Shopify media gid (kept, not re-uploaded)
     * @return array{input: array<string, mixed>, files: list<string>}
     */
    public function build(Product $product, array $mediaIds = []): array
    {
        $product->loadMissing(['supplier', 'detail', 'webPrice', 'override', 'images', 'colours']);
        $wp = $product->webPrice;
        $archived = $product->discontinued || $product->tier !== Product::TIER_LIVE;
        $img = $this->images->forShopify($product);
        $hidden = $product->images->where('hide_web', true)->pluck('url')->all();
        $files = array_values(array_diff($img['media'], $hidden));
        // Keep anything already on the product (merge, never replace): the theme
        // contract says images are merged; media we no longer want are left for staff.
        $files = array_values(array_unique(array_merge($files, array_keys($mediaIds))));
        $fileInput = fn (string $url) => isset($mediaIds[$url]) ? ['id' => $mediaIds[$url]] : ['originalSource' => $url, 'contentType' => 'IMAGE', 'alt' => $product->name];

        $price = $wp?->collection_variant_price !== null ? number_format((float) $wp->collection_variant_price, 2, '.', '') : null;
        $moq = (int) ($wp?->collection_moq ?: $wp?->actual_moq ?: 0);
        $variants = [];
        $pos = 0;
        foreach ($img['colours'] as $name => $c) {
            $label = trim(preg_replace('/\.png$/i', '', $name));
            $v = [
                'optionValues' => [['optionName' => 'Color', 'name' => $label]],
                'inventoryItem' => ['sku' => $product->id.'-'.Str::of($label)->lower()->replaceMatches('/[^a-z0-9]+/', '')->toString()],
                'position' => ++$pos,
                'metafields' => array_values(array_filter([
                    $moq ? $this->mf('custom', 'productmoq', 'number_integer', (string) $moq) : null,
                    $c['other'] ? $this->mf('custom', 'altimagepublicpath', 'url', $c['other'][0]) : null,
                    $c['other'] ? $this->mf('custom', 'altimages', 'list.url', json_encode(array_values($c['other']))) : null,
                    ($c['families'] ?? []) ? $this->mf('custom', 'colourfamily', 'list.single_line_text_field', json_encode(self::families($c['families']))) : null,
                ])),
            ];
            if ($price !== null) {
                $v['price'] = $price;
            }
            if ($c['image'] && ! in_array($c['image'], $hidden, true)) {
                $v['file'] = $fileInput($c['image']);
            }
            $variants[] = $v;
        }

        $title = $this->title($product);
        $codes = $this->codes->forShopify($product);
        $area = $this->areas->forShopify($product);
        $families = collect($img['colours'])->pluck('families')->flatten()->filter()->map(fn ($f) => Str::slug($f, ''))->unique()->values()->all();
        $decNames = collect(json_decode((string) $wp?->decoration_json, true)['Decorations'] ?? [])->pluck('DecName')->filter()->implode(' ');
        $branding = collect(self::BRANDING)->filter(fn ($re) => preg_match($re, $decNames))->keys()->all();
        if (array_intersect($branding, ['spotcolour', 'fullcolour'])) {
            $branding[] = 'anyprint';
        }
        $type = (string) $product->promodata_type_name;

        $metafields = array_values(array_filter([
            $wp?->decoration_json ? $this->mf('custom', 'decorationjson', 'multi_line_text_field', $wp->decoration_json) : null,
            $moq ? $this->mf('custom', 'collectiondisplayqty', 'number_integer', (string) $moq) : null,
            $price !== null ? $this->mf('custom', 'collectiondisplayprice', 'number_decimal', $price) : null,
            $this->mf('custom', 'mastersortorder', 'number_integer', (string) ((int) $product->overrideValue('master_sort_order') ?: 99999)),
            $this->mf('custom', 'tagcategory', 'single_line_text_field', 'any,'),
            $this->mf('custom', 'tagsubcategory', 'single_line_text_field', 'any,'.(app(RangeCollections::class)->tagFor($type) ?? '')),
            $this->mf('custom', 'tagcolourgroup', 'single_line_text_field', 'any,'.implode(',', $families)),
            $this->mf('custom', 'tagbrandingoption', 'single_line_text_field', 'any,'.implode(',', array_unique($branding))),
            $this->mf('custom', 'tagspecial', 'single_line_text_field', 'any,'),
            $this->mf('custom', 'tagcollection', 'single_line_text_field', 'any,'),
            $type !== '' ? $this->mf('custom', 'essentialssubcat', 'single_line_text_field', $type) : null,
            $type !== '' ? $this->mf('custom', 'urgentsubcat', 'single_line_text_field', $type) : null,
            $type !== '' ? $this->mf('custom', 'ecorecsubcat', 'single_line_text_field', $type) : null,
            $product->supplier?->stock_columns ? $this->mf('custom', 'stockcolumns', 'single_line_text_field', $product->supplier->stock_columns) : null,
            $this->mf('custom', 'nostockmessage', 'single_line_text_field', self::NO_STOCK_MESSAGE),
            $type !== '' ? $this->mf('my_fields', 'pdpcollectionname', 'single_line_text_field', $type) : null,
            // New, additive (the live theme ignores them)
            $codes['supplier_code'] !== '' ? $this->mf('custom', 'suppliercode', 'single_line_text_field', $codes['supplier_code']) : null,
            $codes['skus'] ? $this->mf('custom', 'supplierskus', 'json', json_encode($codes['skus'])) : null,
            // Shown to clients (docs/supplier-codes.md): our code under the title; the supplier's code alone as the title chip.
            ($our = OurCodes::code($product)) ? $this->mf('custom', 'ourcode', 'single_line_text_field', $our) : null,
            $product->lead_time_days ? $this->mf('custom', 'leadtimedays', 'number_integer', (string) (int) ceil((float) $product->lead_time_days)) : null,
            filled($product->code) ? $this->mf('custom', 'supplierproductcode', 'single_line_text_field', (string) $product->code) : null,
            $area ? $this->mf('custom', 'printarea', 'json', json_encode($area)) : null,
            // Template, else Promodata's decoration print areas, else the type default (docs/logo-preview.md).
            ($positions = $this->positions->forShopify($product)) ? $this->mf('custom', 'brandingpositions', 'json', json_encode($positions)) : null,
            ($tpl = $this->templates->templateUrl($product)) ? $this->mf('custom', 'brandingtemplate', 'url', $tpl) : null,
            // The size chart's chest width for the mockup, e.g. "L:56" (docs/logo-preview.md); none means the theme uses a typical width.
            ($garment = $this->sizing(fn () => GarmentSizes::metafield($product))) ? $this->mf('custom', 'mockupgarment', 'single_line_text_field', $garment) : null,
            // Fit, measurements and the supplier's size chart for the product page's Size guide.
            ($guide = $this->sizing(fn () => SizeGuide::forProduct($product))) ? $this->mf('custom', 'sizeguide', 'json', json_encode($guide, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) : null,
        ]));

        $input = [
            'handle' => (string) $product->id,
            'title' => $title,
            'descriptionHtml' => $this->body($product),
            'productType' => 'Product',
            'tags' => $this->tags($product),
            'status' => $archived ? 'ARCHIVED' : 'ACTIVE',
            'templateSuffix' => (string) config('shopify.product_template', 'colour_as_variant_product'),
            'seo' => ['title' => $this->titleTag($product, $decNames)],
            'productOptions' => [['name' => 'Color', 'values' => array_map(fn ($v) => ['name' => $v['optionValues'][0]['name']], $variants)]],
            'variants' => $variants,
            'files' => array_map($fileInput, $files),
            'metafields' => $metafields,
        ];

        return ['input' => $input, 'files' => $files];
    }

    /** TOD: AlphaNumericSU(web title, else name) + " [SupplierID-Code]". */
    public function title(Product $product): string
    {
        $name = (string) ($product->overrideValue('title') ?: $product->name);
        $name = trim(preg_replace('/\s+/', ' ', preg_replace("/[^\\p{L}\\p{N} &'()\\-.\\/+]/u", ' ', $name)));

        return $name.' ['.$product->supplier_id.'-'.$product->code.']';
    }

    private function titleTag(Product $product, string $decNames): string
    {
        $name = (string) ($product->overrideValue('title') ?: $product->name);
        $verb = match (true) {
            (bool) preg_match('/embroider/i', $decNames) => 'Embroidered',
            (bool) preg_match('/engrav|laser/i', $decNames) => 'Engraved',
            (bool) preg_match('/print|dtf|transfer|sublimat/i', $decNames) => 'Printed',
            default => 'Branded',
        };
        $long = "Custom {$verb} {$name} [{$product->code}]";

        return mb_strlen($long) <= 70 ? $long : "{$name} [{$product->code}]";
    }

    /** @return list<string> */
    private function tags(Product $product): array
    {
        $d = $product->detail;
        $tags = ['style'];
        // The subcategory tag its range collection is built on (RangeCollections).
        if ($sub = app(RangeCollections::class)->tagFor($product->promodata_type_name)) {
            $tags[] = $sub;
        }
        foreach ([$d?->supplier_brand, $d?->supplier_label, $product->promodata_type_name] as $t) {
            if (filled($t)) {
                $tags[] = trim($t);
            }
        }
        if ($product->id > 10000000) {
            $tags[] = 'external';
        }
        foreach ((array) ($d?->promodata_attributes ?? []) as $a) {
            if (is_array($a) && filled($a['value'] ?? null)) {
                $tags[] = trim((string) $a['value']);
            }
        }
        foreach (array_filter(array_map('trim', explode(',', (string) $product->overrideValue('extra_tags', '')))) as $t) {
            $tags[] = $t;
        }

        return array_values(array_unique(array_map(fn ($t) => mb_substr($t, 0, 255), $tags)));
    }

    /** Long description, then sizes, dimensions, accessory options and specifications, as TOD. */
    private function body(Product $product): string
    {
        $d = $product->detail;
        $html = (string) ($product->overrideValue('description') ?: $d?->description);
        $plain = strip_tags(str_ireplace(['<p>', '</p>', '<br>', '<br/>', '<br />'], ["\n", '', "\n", "\n", "\n"], $html));
        // A size table in the description moves to the Size guide (custom.sizeguide).
        $lines = preg_split('/\R/', $plain) ?: [];
        $keep = [];
        $header = null;
        $dropped = false;
        foreach ($lines as $i => $line) {
            $sizeHeader = preg_match('/^\s*sizes?\s*[|:]/i', $line) && SizeGuide::table($line."\n".($lines[$i + 1] ?? ''));
            // A measurement line reads on its own ("Chest: S 52, M 55") or under the size header above it.
            $measurement = trim($line) !== '' && (SizeGuide::table($line) || (preg_match('/^\s*sizes?\s*[|:]/i', $lines[$i - 1] ?? '') || ! empty($dropped)) && SizeGuide::table(($header ?? '')."\n".$line));
            if ($sizeHeader) {
                $header = $line;
                $dropped = true;
            } elseif ($measurement) {
                $dropped = true;
            } else {
                $keep[] = $line;
                $dropped = false;
            }
        }
        $html = nl2br(e(trim(implode("\n", $keep))), false);
        $details = collect((array) ($d?->details ?? []))->filter(fn ($x) => is_array($x) && filled($x['detail'] ?? null));
        $find = fn (string $re) => $details->first(fn ($x) => preg_match($re, (string) ($x['name'] ?? '')));
        if ($s = $find('/size/i')) {
            $html .= '<br><br>Available sizes: '.e($s['detail']);
        }
        if ($s = $find('/dimension/i')) {
            $html .= '<br>Dimensions: '.e($s['detail']);
        }
        if (filled($product->webPrice?->accessory_list)) {
            $html .= '<details><summary>Additional options available for custom quote</summary>'.nl2br(e($product->webPrice->accessory_list), false).'</details>';
        }
        // Fit and measurements show in the Size guide instead (custom.sizeguide).
        $hasGuide = $this->sizing(fn () => SizeGuide::forProduct($product)) !== null;
        $specs = $hasGuide ? $details->reject(fn ($x) => SizeGuide::usesDetail((string) ($x['name'] ?? ''), (string) $x['detail'])) : $details;
        if ($specs->isNotEmpty()) {
            $html .= '<details><summary>Product Specifications</summary><ul>'
                .$specs->map(fn ($x) => '<li><b>'.e($x['name'] ?? '').':</b> '.e($x['detail']).'</li>')->implode('').'</ul></details>';
        }

        return $html;
    }

    /** Sizing is a nice-to-have: a product whose size data can't be read still publishes (logged, without it). */
    private function sizing(callable $read): mixed
    {
        try {
            return $read();
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /** @return array{namespace: string, key: string, type: string, value: string} */
    private function mf(string $ns, string $key, string $type, string $value): array
    {
        return ['namespace' => $ns, 'key' => $key, 'type' => $type, 'value' => $value];
    }

    /**
     * Colour families without light/dark shades, and navy as blue (Scott, 2 Nov 2026):
     * "Dark Red" is Red, "Navy" is Blue, so each family holds every shade.
     *
     * @param  list<string>  $families
     * @return list<string>
     */
    public static function families(array $families): array
    {
        $out = [];
        foreach ($families as $f) {
            $f = trim((string) preg_replace('/\\s+/', ' ', (string) preg_replace('/\\b(light|dark|pale|deep|bright|neon|dusty|faded|soft)\\b/i', '', (string) $f)));
            $f = strcasecmp($f, 'navy') === 0 ? 'Blue' : ucwords(strtolower($f));
            if ($f !== '' && ! in_array($f, $out, true)) {
                $out[] = $f;
            }
        }

        return $out;
    }
}
