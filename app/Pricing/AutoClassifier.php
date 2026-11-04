<?php

namespace App\Pricing;

use App\Catalogue\DecorationAreas;
use App\Catalogue\ProductOptions;
use App\Models\DecTypeCode;
use App\Models\PriceOption;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Decoration types (10 decoration, 11 option, 12 undecorated, 23 hidden) worked out from what we
 * know about each supplier, so staff don't assign them by hand (Scott, 5 Nov 2026;
 * docs/decoration-types.md). For each description a supplier's products use, in order:
 *
 *   1. the supplier's pricing rule (config pricing.supplier_rules, e.g. Logo Line's TOD rule), which
 *      already decides the type when the product is priced;
 *   2. the supplier's option template, read from its own product pages
 *      (database/data/option-templates): an option is 11, a branding line it takes over stays 10;
 *   3. a production time (Dex): a listed time's line is 10, any other time's (28 days, offshore) is 23;
 *   4. a method the supplier lists as offered (Legend Life) is 10;
 *   5. an undecorated line (Promodata's blank price) is 12;
 *   6. a description that names a branding method, or a transfer or print under a supplier's own name
 *      (Colourflex, DigiFlex), and isn't a charge on top (setup, sample, surcharge, additional…) is 10.
 *
 * Only descriptions with no type are filled in. A type staff already set that disagrees is listed,
 * never changed, and a description two suppliers would type differently is left for staff.
 */
class AutoClassifier
{
    /** A method word in these is a charge on top of the branding, not the branding itself. */
    private const NOT_A_METHOD = '/set\s*-?\s*up|repeat|sample|surcharge|additional|extra|per\s+additional|change\s*-?\s*over|changeout|\bfee\b|freight|delivery|proof|artwork|colour\s*match|pms\s*match|per\s+line|per\s+name/i';

    public function __construct(private readonly ProductOptions $options) {}

    /** Suppliers we have read the pages of (an option template). @return list<int> */
    public function suppliers(): array
    {
        $ids = [];
        foreach (glob(database_path(ProductOptions::DIR.'/*.json')) ?: [] as $path) {
            $data = json_decode((string) file_get_contents($path), true);
            if (isset($data['supplier_id'])) {
                $ids[] = (int) $data['supplier_id'];
            }
        }
        sort($ids);

        return $ids;
    }

    /**
     * Every description these suppliers' products use, with its type now and the type worked out.
     *
     * @param  list<int>  $supplierIds
     * @return Collection<int, array{code: string, supplier_id: int, description: string, current: ?int, derived: ?int, why: ?string, products: int, on_range: int}>
     */
    public function plan(array $supplierIds): Collection
    {
        $rows = PriceOption::query()->join('products as p', 'p.id', '=', 'price_options.product_id')
            ->whereIn('p.supplier_id', $supplierIds)->whereIn('p.tier', [Product::TIER_ACTIVE, Product::TIER_LIVE])
            ->where('price_options.kind', '!=', PriceOption::KIND_EXTERNAL)->where('price_options.placeholder', false)
            ->groupBy('price_options.code', 'p.supplier_id')
            ->selectRaw('price_options.code, p.supplier_id, min(price_options.description) as description, min(price_options.kind) as kind,
                max(price_options.cost_string) as cost_string, count(distinct price_options.product_id) as products,
                count(distinct case when p.tier = ? and p.discontinued = 0 then p.id end) as on_range', [Product::TIER_LIVE])
            ->get();
        $current = DecTypeCode::query()->whereIn('code', $rows->pluck('code')->unique())->pluck('type_code', 'code');

        return $rows->map(function ($r) use ($current) {
            [$type, $why] = $this->derive((int) $r->supplier_id, (string) $r->code, (string) $r->description, (string) $r->kind, (string) $r->cost_string);

            return [
                'code' => (string) $r->code, 'supplier_id' => (int) $r->supplier_id, 'description' => (string) $r->description,
                'current' => isset($current[$r->code]) ? (int) $current[$r->code] : null, 'derived' => $type, 'why' => $why,
                'products' => (int) $r->products, 'on_range' => (int) $r->on_range,
            ];
        })->values();
    }

    /** @return array{0: ?int, 1: ?string} the type and where it came from */
    public function derive(int $supplierId, string $code, string $description, string $kind, string $costString = ''): array
    {
        $rule = SupplierRules::for($supplierId)->typeFor($description, $costString !== '' ? $costString : '1:0');
        if ($rule !== null) {
            return [$rule, 'supplier pricing rule'];
        }
        $file = $this->options->templateFor($supplierId);
        if ($file) {
            foreach ($file['lines'] ?? [] as $line) {
                if (preg_match('/'.$line['match'].'/', $code)) {
                    return ($line['branding_line'] ?? false)
                        ? [DecTypeCode::DECORATION, 'supplier pages: '.$line['group'].' option taken over from branding']
                        : [DecTypeCode::ACCESSORY, 'supplier pages: '.$line['group'].' option'];
                }
            }
            if (isset($file['speed'])) {
                if ($this->options->speedKey($file, $description) !== null) {
                    return [$kind === PriceOption::KIND_UNDECORATED ? DecTypeCode::UNDECORATED : DecTypeCode::DECORATION, 'supplier pages: production time'];
                }
                if (preg_match('/^\s*\d+\s*(?:days?|wks?|weeks?)\b|offshore|indent/i', $description)) {
                    return [DecTypeCode::HIDDEN, 'supplier pages: production time not offered'];
                }
            }
            $desc = mb_strtolower($description);
            foreach ($file['offered']['methods'] ?? [] as $m) {
                if (str_starts_with($desc, mb_strtolower($m))) {
                    return [DecTypeCode::DECORATION, 'supplier pages: branding method'];
                }
            }
        }
        if ($kind === PriceOption::KIND_UNDECORATED) {
            return [DecTypeCode::UNDECORATED, 'blank price'];
        }
        if (DecorationAreas::method($description) !== null && ! preg_match(self::NOT_A_METHOD, $description)) {
            return [DecTypeCode::DECORATION, 'names a branding method'];
        }
        // Supplier brand names for transfers and prints (Colourflex, DigiFlex, …): branding all the same.
        if (preg_match('/\b(transfer|print(?:ed|ing)?|imprint|branding)\b/i', $description) && ! preg_match(self::NOT_A_METHOD, $description)) {
            return [DecTypeCode::DECORATION, 'a transfer or print'];
        }

        return [null, null];
    }

    /**
     * Group the plan by description: what to fill in, what disagrees with staff, what is left.
     *
     * @param  Collection<int, array<string, mixed>>  $plan
     * @return array{fill: array<string, array{type: int, why: string, description: string}>, disagree: list<array<string, mixed>>, mixed: list<string>, left: list<array<string, mixed>>}
     */
    public function sort(Collection $plan): array
    {
        $out = ['fill' => [], 'disagree' => [], 'mixed' => [], 'left' => []];
        foreach ($plan->groupBy('code') as $code => $rows) {
            $types = $rows->pluck('derived')->filter(fn ($t) => $t !== null)->unique()->values();
            $current = $rows->first()['current'];
            if ($types->count() > 1) {
                $out['mixed'][] = (string) $code;

                continue;
            }
            $type = $types->first();
            if ($type === null) {
                if ($current === null) {
                    array_push($out['left'], ...$rows->all());
                }

                continue;
            }
            $row = $rows->firstWhere('derived', $type);
            if ($current === null) {
                $out['fill'][(string) $code] = ['type' => (int) $type, 'why' => (string) $row['why'], 'description' => (string) $row['description']];
            } elseif ($current !== $type) {
                $out['disagree'][] = $row;
            }
        }

        return $out;
    }
}
