<?php

namespace App\Catalogue;

use App\Models\PriceOption;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;

/**
 * Product options that aren't branding (Scott, 3 Nov 2026; docs/product-options.md): construction
 * choices such as a lanyard's clips, parts included as standard, add-ons, packaging, embroidery
 * extras, personalisation and per-order services such as a pre-production sample.
 *
 * The prices are the product's Promodata lines, priced by the SaaS like every other line (sell price
 * breaks and sell setup). The logic (what is included, what replaces what, what can be combined, what
 * goes with which branding method) comes from the supplier's own product pages and is kept per supplier
 * in database/data/option-templates/<supplier>.json: groups, and rules that put each line into a group
 * by its code (the description in lower-case letters and digits, without the APPAID prefix).
 *
 * The result goes to Shopify as custom.options; the product page shows it as an option builder.
 */
class ProductOptions
{
    public const DIR = 'data/option-templates';

    /** @return array<string, mixed>|null the custom.options value, or null when nothing applies */
    public function forProduct(Product $product): ?array
    {
        $file = $this->templateFor((int) $product->supplier_id);
        if (! $file) {
            return null;
        }
        $groups = [];
        $codes = array_keys($this->lines($product));
        foreach ($this->lines($product) as $code => $line) {
            $rule = $this->ruleFor($file, $code);
            if (! $rule || in_array((int) $line->type_code, [10, 23], true)) {
                continue; // no rule, branding (DecorationJSON has it) or hidden
            }
            if (($rule['requires'] ?? []) && ! $this->hasAll($codes, $rule['requires'])) {
                continue;
            }
            $g = $rule['group'];
            $groups[$g][] = array_filter([
                'id' => $line->option_id,
                'name' => $rule['name'] ?? self::label($line),
                'included' => ($rule['included'] ?? false) ?: null,
                'fixed' => ($rule['fixed'] ?? false) ?: null,
                'breaks' => $line->price_string ?: null,                // sell per unit by quantity
                'setup' => (float) $line->setup_price > 0 ? round((float) $line->setup_price, 2) : null, // sell, once
                'per' => $rule['per'] ?? null,                           // "order" | "artwork"; null = per unit
                'count' => $rule['count'] ?? null,                       // {max, label}: a quantity per item / order
                'per_stitches' => $rule['per_stitches'] ?? null,
                'excludes' => isset($rule['excludes']) ? $this->idsFor($product, $rule['excludes']) : null,
                'note' => $this->note($line),
            ], fn ($v) => $v !== null && $v !== []);
        }
        if ($groups === []) {
            return null;
        }
        $defs = $file['groups'];
        uksort($groups, fn ($a, $b) => ($defs[$a]['order'] ?? 99) <=> ($defs[$b]['order'] ?? 99));
        $out = [];
        foreach ($groups as $key => $options) {
            $def = $defs[$key] ?? ['label' => ucfirst($key)];
            // A "one" group needs a default: the included option, else none (optional) or the first.
            $pick = $def['pick'] ?? 'many';
            if ($pick === 'one' && ! collect($options)->contains('included', true) && ! ($def['optional'] ?? false)) {
                $options[0]['included'] = true;
            }
            $out[] = array_filter([
                'key' => $key,
                'label' => $def['label'],
                'pick' => $pick,                                   // one (radio) | many (toggles) | count (stepper)
                'optional' => ($def['optional'] ?? false) ?: null,
                'max' => $def['max'] ?? null,
                'unit_label' => $def['unit_label'] ?? null,
                'base_stitches' => $def['base_stitches'] ?? null,
                'with_method' => $def['with_method'] ?? null,      // only shown when a position uses one of these methods
                'note' => $def['note'] ?? null,
                'options' => $options,
            ], fn ($v) => $v !== null);
        }
        $notes = [];
        if (isset($groups['clip']) && ($n = $file['notes']['lanyard'] ?? null)) {
            $notes[] = $n;
        }

        return array_filter(['v' => 1, 'source' => $file['key'], 'groups' => $out, 'notes' => $notes ?: null], fn ($v) => $v !== null);
    }

    /** Lines of a product no rule covers and that aren't branding: for the review report. @return list<string> */
    public function unmatched(Product $product): array
    {
        $file = $this->templateFor((int) $product->supplier_id);
        $out = [];
        foreach ($this->lines($product) as $code => $line) {
            if (in_array((int) $line->type_code, [10, 12, 23], true) || ($file && $this->ruleFor($file, $code))) {
                continue;
            }
            $out[] = (string) $line->description;
        }

        return $out;
    }

    /** @return array<string, mixed>|null the supplier's template file */
    public function templateFor(int $supplierId): ?array
    {
        return Cache::remember("option-templates:{$supplierId}:v2", 600, function () use ($supplierId) {
            foreach (glob(database_path(self::DIR.'/*.json')) ?: [] as $path) {
                $data = json_decode((string) file_get_contents($path), true);
                if (is_array($data) && (int) ($data['supplier_id'] ?? 0) === $supplierId) {
                    return $data;
                }
            }

            return null;
        });
    }

    private function ruleFor(array $file, string $code): ?array
    {
        foreach ($file['lines'] as $rule) {
            if (preg_match('/'.$rule['match'].'/', $code)) {
                return $rule;
            }
        }

        return null;
    }

    /** @return array<string, PriceOption> code without the APPAID prefix => line */
    private function lines(Product $product): array
    {
        $out = [];
        foreach ($product->priceOptions as $o) {
            $out[preg_replace('/^\d+-/', '', (string) $o->option_id)] = $o;
        }

        return $out;
    }

    private function hasAll(array $codes, array $prefixes): bool
    {
        foreach ($prefixes as $p) {
            if (! collect($codes)->contains(fn ($c) => str_starts_with($c, $p))) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> option ids of the product's lines starting with these codes */
    private function idsFor(Product $product, array $prefixes): array
    {
        return collect($this->lines($product))->filter(fn ($l, $c) => collect($prefixes)->contains(fn ($p) => str_starts_with($c, $p)))
            ->map(fn ($l) => $l->option_id)->values()->all();
    }

    /** The bracketed part of a Promodata description, e.g. "(per line)" → "per line". */
    private function note(PriceOption $line): ?string
    {
        return preg_match('/\(([^)]{3,80})\)/', (string) $line->description, $m) ? trim($m[1]) : null;
    }

    private static function label(PriceOption $line): string
    {
        $name = trim(preg_replace('/\s*\(.*$/', '', (string) ($line->display_description ?: $line->description)));

        return mb_strtoupper(mb_substr($name, 0, 1)).mb_strtolower(mb_substr($name, 1));
    }
}
