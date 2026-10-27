<?php

namespace App\Quotes;

/**
 * Normalises a Shopify draft order into one shape, whichever way it arrived:
 *  - Shopify REST / webhook JSON (snake_case: line_items, properties[{name, value}]);
 *  - the PascalCase export behind DraftOrdersLocal (LineItems, Properties[{Name, Value}]).
 *
 * Website lines carry the price split in line properties:
 *   decsetup, decunit, undecsetup, undecunit, decdesc, decID, decName
 * and Price = (decsetup + undecsetup) / qty + decunit + undecunit.
 */
class DraftOrderParser
{
    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function parse(array $raw): array
    {
        $r = $this->lowerKeys($raw);

        $customer = (array) ($r['customer'] ?? []);
        $shipping = (array) ($r['shipping_address'] ?? []);
        $billing = (array) ($r['billing_address'] ?? []);

        // The export flattens addresses (ShippingAddress_City...) and customer fields.
        foreach ($r as $key => $value) {
            if (str_starts_with($key, 'shipping_address_')) {
                $shipping[substr($key, 17)] = $value;
            } elseif (str_starts_with($key, 'billing_address_')) {
                $billing[substr($key, 16)] = $value;
            } elseif (str_starts_with($key, 'customer_')) {
                $customer[substr($key, 9)] = $value;
            }
        }

        $email = $this->clean($r['email'] ?? null) ?? $this->clean($customer['email'] ?? null);
        [$urgency, $notes] = $this->splitNote($this->clean($r['note'] ?? null));

        $address = $this->hasAddress($shipping) ? $shipping : $billing;
        $first = $this->clean($customer['first_name'] ?? null) ?? $this->clean($address['first_name'] ?? null);
        $last = $this->clean($customer['last_name'] ?? null) ?? $this->clean($address['last_name'] ?? null);

        $lineItems = $r['line_items'] ?? [];
        if (is_string($lineItems)) {
            $lineItems = json_decode($lineItems, true) ?: [];
        }

        return [
            'id' => (int) $r['id'],
            'name' => $this->clean($r['name'] ?? null),
            'status' => $this->clean($r['status'] ?? null),
            'email' => $email ? mb_strtolower($email) : null,
            'contact_name' => trim(($first ?? '').' '.($last ?? '')) ?: $this->clean($address['name'] ?? null),
            'company' => $this->clean($address['company'] ?? null),
            'phone' => $this->clean($customer['phone'] ?? null) ?? $this->clean($address['phone'] ?? null),
            'address' => [
                'name' => $this->clean($address['name'] ?? null),
                'address1' => $this->clean($address['address1'] ?? null),
                'address2' => $this->clean($address['address2'] ?? null),
                'city' => $this->clean($address['city'] ?? null),
                'state' => $this->clean($address['province_code'] ?? null) ?? $this->clean($address['province'] ?? null),
                'postcode' => $this->clean($address['zip'] ?? null),
                'country' => $this->clean($address['country_code'] ?? null) ?? 'AU',
            ],
            'urgency' => $urgency,
            'notes' => $notes,
            'invoice_url' => $this->clean($r['invoice_url'] ?? null),
            'created_at' => $this->clean($r['created_at'] ?? null),
            'updated_at' => $this->clean($r['updated_at'] ?? null),
            'lines' => array_values(array_map(fn ($l) => $this->parseLine((array) $l), (array) $lineItems)),
        ];
    }

    /** @return array<string, mixed> */
    public function parseLine(array $raw): array
    {
        $l = $this->lowerKeys($raw);

        $props = [];
        foreach ((array) ($l['properties'] ?? []) as $p) {
            $p = $this->lowerKeys((array) $p);
            if (isset($p['name'])) {
                $props[mb_strtolower((string) $p['name'])] = is_string($p['value'] ?? null) ? trim($p['value']) : ($p['value'] ?? null);
            }
        }

        $title = (string) ($l['title'] ?? $l['name'] ?? '');
        [$name, $supplierId, $code] = $this->splitTitle($title);
        $sku = $this->clean($l['sku'] ?? null);

        $decId = $this->clean($props['decid'] ?? null);
        $decProductId = null;
        if ($decId !== null && preg_match('/^(\d+)-/', $decId, $m)) {
            $decProductId = (int) $m[1];
        }

        return [
            'title' => $title,
            'name' => $name,
            'supplier_id' => $supplierId,
            'product_code' => $code,
            'colour' => $this->clean($l['variant_title'] ?? $l['product_variant_title'] ?? null),
            'sku' => $sku,
            'product_id' => $sku !== null && preg_match('/^(\d+)/', $sku, $m) ? (int) $m[1] : null,
            'shopify_variant_id' => isset($l['variant_id']) ? (int) $l['variant_id'] : (isset($l['product_variant_id']) ? (int) $l['product_variant_id'] : null),
            'quantity' => max(1, (int) ($l['quantity'] ?? 1)),
            'price' => (float) ($l['price'] ?? 0),
            'custom' => (bool) ($l['custom'] ?? false),
            // Older drafts carry the properties with every value 0; their line price is then the whole story.
            'has_split' => ((float) ($props['undecunit'] ?? 0) + (float) ($props['decunit'] ?? 0) + (float) ($props['decsetup'] ?? 0) + (float) ($props['undecsetup'] ?? 0)) > 0,
            'undec_unit' => (float) ($props['undecunit'] ?? 0),
            'undec_setup' => (float) ($props['undecsetup'] ?? 0),
            'dec_unit' => (float) ($props['decunit'] ?? 0),
            'dec_setup' => (float) ($props['decsetup'] ?? 0),
            'dec_desc' => $this->clean($props['decdesc'] ?? null) ?? $this->clean($props['decname'] ?? null),
            'dec_option_id' => $decId,
            'dec_product_id' => $decProductId,
        ];
    }

    /**
     * "Heavy brushed cotton cap buckle on back [53-CH35]" -> [name, 53, "CH35"].
     *
     * @return array{0: string, 1: int|null, 2: string|null}
     */
    public function splitTitle(string $title): array
    {
        if (preg_match('/^(.*?)\s*\[(\d+)-([^\]]+)\]\s*(?:-.*)?$/u', $title, $m)) {
            return [trim($m[1]), (int) $m[2], trim($m[3])];
        }

        return [trim($title), null, null];
    }

    /**
     * The website note reads "Urgency: <choice> Notes: <free text>".
     *
     * @return array{0: string|null, 1: string|null}
     */
    public function splitNote(?string $note): array
    {
        if ($note === null) {
            return [null, null];
        }
        if (preg_match('/^\s*Urgency:\s*(.*?)\s*Notes:\s*(.*)$/isu', $note, $m)) {
            return [$this->clean($m[1]), $this->clean($m[2])];
        }

        return [null, $note];
    }

    private function hasAddress(array $a): bool
    {
        return $this->clean($a['address1'] ?? null) !== null || $this->clean($a['city'] ?? null) !== null;
    }

    private function clean(mixed $v): ?string
    {
        if ($v === null || is_array($v)) {
            return null;
        }
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }

    /** PascalCase / camelCase keys to snake_case, one level deep. */
    private function lowerKeys(array $a): array
    {
        $out = [];
        foreach ($a as $k => $v) {
            $key = is_string($k) ? mb_strtolower(preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', $k)) : $k;
            $out[$key] = $v;
        }

        return $out;
    }
}
