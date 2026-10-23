<?php

namespace App\Sources\Promodata;

use App\Sources\SourceProduct;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

/**
 * Converts one Promodata product payload into a SourceProduct.
 *
 * Shape (as consumed by TOD's modApi.bas):
 *   meta:     id, country, data_source, discontinued, can_check_stock, discontinued_at,
 *             first_listed_at, last_changed_at, prices_changed_at, discontinued_reason
 *   supplier: supplier, supplier_id, supplier_country, supplier_appa_member_number
 *   overview: hero_image
 *   product:  name, code, description, supplier_brand, supplier_label, supplier_catalogue,
 *             supplier_website_page, details[{name, detail}], images[url],
 *             categorisation{supplier_category, supplier_subcategory, appa_product_type,
 *                            promodata_attributes["Name: value"], promodata_product_type{type_id, type_name, ...}},
 *             colours{supplier_text{n:{name, detail}}, list{n:{for, name, image, swatch[], colours{}, appa_colours{}}}},
 *             prices{price_groups[{base_price, additions[]}], price_tags{name: [tags]}}
 *
 * Collections sometimes arrive as JSON objects keyed "0", "1"... and sometimes as
 * arrays; both are accepted.
 */
class PromodataNormaliser
{
    /** @param  array<string, mixed>  $data */
    public function normalise(array $data): SourceProduct
    {
        $meta = (array) ($data['meta'] ?? []);
        $supplier = (array) ($data['supplier'] ?? []);
        $overview = (array) ($data['overview'] ?? []);
        $product = (array) ($data['product'] ?? []);
        $cat = (array) ($product['categorisation'] ?? []);
        $colours = (array) ($product['colours'] ?? []);
        $prices = (array) ($product['prices'] ?? []);
        $type = is_array($cat['promodata_product_type'] ?? null) ? $cat['promodata_product_type'] : [];

        return new SourceProduct(
            source: 'promodata',
            id: (int) ($meta['id'] ?? 0),
            supplierId: (int) ($supplier['supplier_id'] ?? 0),
            supplierName: (string) ($supplier['supplier'] ?? ''),
            supplierCountry: $this->str($supplier['supplier_country'] ?? null),
            supplierAppaMemberNumber: $this->str($supplier['supplier_appa_member_number'] ?? null),
            name: trim((string) ($product['name'] ?? '')),
            code: $this->str($product['code'] ?? null),
            country: $this->str($meta['country'] ?? null),
            discontinued: (bool) ($meta['discontinued'] ?? false),
            canCheckStock: (bool) ($meta['can_check_stock'] ?? false),
            firstListedAt: $this->date($meta['first_listed_at'] ?? null),
            lastChangedAt: $this->date($meta['last_changed_at'] ?? null),
            pricesChangedAt: $this->date($meta['prices_changed_at'] ?? null),
            discontinuedAt: $this->date($meta['discontinued_at'] ?? null),
            supplierCategory: $this->str($cat['supplier_category'] ?? null),
            supplierSubcategory: $this->str($cat['supplier_subcategory'] ?? null),
            promodataTypeId: $this->str($type['type_id'] ?? null),
            promodataTypeName: $this->str($type['type_name_text'] ?? $type['type_name'] ?? null),
            heroImageUrl: $this->str($overview['hero_image'] ?? null),
            imageUrls: array_values(array_filter(array_map(
                fn ($url) => $this->str($url),
                $this->list($product['images'] ?? []),
            ))),
            description: $this->str($product['description'] ?? null),
            supplierBrand: $this->str($product['supplier_brand'] ?? null),
            supplierLabel: $this->str($product['supplier_label'] ?? null),
            supplierCatalogue: $this->str($product['supplier_catalogue'] ?? null),
            supplierWebsitePage: $this->str($product['supplier_website_page'] ?? null),
            details: array_map(fn ($d) => [
                'name' => (string) ($d['name'] ?? ''),
                'detail' => $this->str($d['detail'] ?? null),
            ], $this->list($product['details'] ?? [])),
            promodataAttributes: $this->attributes($cat['promodata_attributes'] ?? []),
            appaProductTypes: is_array($cat['appa_product_type'] ?? null) ? $cat['appa_product_type'] : [],
            colourNotes: array_map(fn ($n) => [
                'name' => (string) ($n['name'] ?? ''),
                'detail' => $this->str($n['detail'] ?? null),
            ], $this->list($colours['supplier_text'] ?? [])),
            colours: array_map(fn ($c) => [
                'applies_to' => $this->str($c['for'] ?? null),
                'name' => trim((string) ($c['name'] ?? '')),
                'image_url' => $this->str($c['image'] ?? null),
                'swatch_urls' => $this->strings($c['swatch'] ?? []),
                'supplier_colours' => $this->strings($c['colours'] ?? []),
                'appa_colours' => $this->strings($c['appa_colours'] ?? []),
            ], $this->list($colours['list'] ?? [])),
            priceGroups: array_map(fn ($g) => [
                'base' => $this->priceLine((array) ($g['base_price'] ?? [])),
                'additions' => array_map(fn ($a) => $this->priceLine((array) $a), $this->list($g['additions'] ?? [])),
            ], $this->list($prices['price_groups'] ?? [])),
            priceTags: is_array($prices['price_tags'] ?? null) ? $prices['price_tags'] : [],
            contentHash: sha1((string) json_encode($data)),
        );
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function priceLine(array $line): array
    {
        $breaks = array_map(fn ($b) => [
            'qty' => (int) ($b['qty'] ?? 1),
            'price' => (float) ($b['price'] ?? 0),
        ], $this->list($line['price_breaks'] ?? []));

        usort($breaks, fn ($a, $b) => $a['qty'] <=> $b['qty']);

        return [
            'key' => $this->str($line['key'] ?? null),
            'type' => $this->str($line['type'] ?? null),
            'description' => $this->str($line['description'] ?? null),
            'setup' => (float) ($line['setup'] ?? 0),
            'currency' => $this->str($line['currency'] ?? null) ?? 'AUD',
            'lead_time' => $this->str($line['lead_time'] ?? null),
            'undecorated' => (bool) ($line['undecorated'] ?? false),
            'indent' => (bool) ($line['indent'] ?? false),
            'promodata_decoration' => $this->str($line['promodata_decoration'] ?? null),
            'tags' => $this->strings($line['tags'] ?? []),
            'breaks' => $breaks,
        ];
    }

    /**
     * "Material: Cotton" strings become {name, value} pairs.
     *
     * @return list<array{name: string, value: ?string}>
     */
    private function attributes(mixed $raw): array
    {
        $out = [];
        foreach ($this->list($raw) as $attr) {
            if (! is_string($attr)) {
                continue;
            }
            [$name, $value] = array_pad(explode(':', $attr, 2), 2, null);
            $out[] = ['name' => trim($name), 'value' => $value === null ? null : trim($value)];
        }

        return $out;
    }

    /** @return list<mixed> */
    private function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        return array_values(array_filter(array_map(fn ($v) => $this->str($v), $this->list($value))));
    }

    private function str(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = implode(', ', array_filter($value, 'is_scalar'));
        }
        if ($value === null || ! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function date(mixed $value): ?DateTimeInterface
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->setTimezone(config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }
}
