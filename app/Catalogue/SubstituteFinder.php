<?php

namespace App\Catalogue;

use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Finds products similar to a reference product, e.g. when it is out of
 * stock, discontinued or too slow. Candidates must share the Promodata
 * product type (or, without one, the supplier subcategory). They are ranked on:
 *
 *   material 25 · dimensions 25 · unbranded cost 20 · MOQ 15 · lead time 15
 *
 * A fact missing on either side scores half, so thin data neither wins nor
 * loses. Live stock is checked separately for the shown results (one supplier
 * call each), because it cannot be compared across the whole catalogue.
 */
class SubstituteFinder
{
    public const WEIGHTS = ['material' => 25, 'size' => 25, 'cost' => 20, 'moq' => 15, 'lead' => 15];

    /**
     * @param  array{scope?: string, max_cost?: ?float, max_moq?: ?int, max_lead?: ?int, other_suppliers?: bool, limit?: int}  $opts
     * @return Collection<int, array{product: Product, score: int, parts: array<string, ?float>}>
     */
    public function find(Product $ref, array $opts = []): Collection
    {
        $scope = $opts['scope'] ?? 'type';
        $limit = (int) ($opts['limit'] ?? 30);

        $query = Product::query()
            ->with('supplier:id,name')
            ->whereKeyNot($ref->id)
            ->where('discontinued', false);

        if ($scope === 'type' && $ref->promodata_type_id) {
            $query->where('promodata_type_id', $ref->promodata_type_id);
        } elseif ($scope !== 'any' && $ref->supplier_subcategory) {
            $query->where('supplier_subcategory', $ref->supplier_subcategory);
        } elseif ($ref->promodata_type_id) {
            $query->where('promodata_type_id', $ref->promodata_type_id);
        } else {
            return collect();
        }

        if (! empty($opts['other_suppliers'])) {
            $query->where('supplier_id', '!=', $ref->supplier_id);
        }
        if (! empty($opts['max_cost'])) {
            $query->where(fn ($w) => $w->where('undecorated_cost', '<=', $opts['max_cost'])
                ->orWhere(fn ($w) => $w->whereNull('undecorated_cost')->where('min_unit_cost', '<=', $opts['max_cost'])));
        }
        if (! empty($opts['max_moq'])) {
            $query->whereRaw('coalesce(undecorated_moq, min_qty, 0) <= ?', [(int) $opts['max_moq']]);
        }
        if (! empty($opts['max_lead'])) {
            $query->where(fn ($w) => $w->whereNull('lead_time_days')->orWhere('lead_time_days', '<=', (int) $opts['max_lead']));
        }

        // Pre-select the nearest on cost so the scoring stays quick on large types (principle 1).
        $refCost = $this->cost($ref);
        if ($refCost !== null) {
            $query->orderByRaw('abs(coalesce(undecorated_cost, min_unit_cost, 0) - ?)', [$refCost]);
        }
        $candidates = $query->limit(400)->get();

        return $candidates
            ->map(fn (Product $p) => $this->score($ref, $p))
            ->sortByDesc('score')
            ->take($limit)
            ->values();
    }

    /** @return array{product: Product, score: int, parts: array<string, ?float>} */
    public function score(Product $ref, Product $p): array
    {
        $parts = [
            'material' => $this->materialSimilarity($ref->material, $p->material),
            'size' => $this->sizeSimilarity($this->dims($ref), $this->dims($p)),
            'cost' => $this->closeness($this->cost($ref), $this->cost($p)),
            'moq' => $this->notWorse($this->moq($ref), $this->moq($p)),
            'lead' => $this->notWorse($ref->lead_time_days, $p->lead_time_days),
        ];

        $total = 0.0;
        foreach (self::WEIGHTS as $key => $weight) {
            $total += $weight * ($parts[$key] ?? 0.5);
        }

        return ['product' => $p, 'score' => (int) round($total), 'parts' => $parts];
    }

    public function cost(Product $p): ?float
    {
        $v = $p->undecorated_cost ?? $p->min_unit_cost;

        return $v === null ? null : (float) $v;
    }

    public function moq(Product $p): ?int
    {
        return $p->undecorated_moq ?? $p->min_qty;
    }

    /** @return list<float> */
    public function dims(Product $p): array
    {
        return array_values(array_filter([(float) $p->dim_1, (float) $p->dim_2, (float) $p->dim_3]));
    }

    /** Share of material words in common (Jaccard), or null if either is unknown. */
    public function materialSimilarity(?string $a, ?string $b): ?float
    {
        if (! $a || ! $b) {
            return null;
        }
        $words = fn (string $s) => array_unique(array_filter(preg_split('/[\s,]+/', $s), fn ($w) => mb_strlen($w) > 2));
        $wa = $words($a);
        $wb = $words($b);
        $union = count(array_unique([...$wa, ...$wb]));

        return $union === 0 ? null : count(array_intersect($wa, $wb)) / $union;
    }

    /**
     * Compares sizes dimension by dimension (both sorted largest first).
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public function sizeSimilarity(array $a, array $b): ?float
    {
        $n = min(count($a), count($b));
        if ($n === 0) {
            return null;
        }
        $sum = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sum += min($a[$i], $b[$i]) / max($a[$i], $b[$i]);
        }

        return $sum / $n;
    }

    /** 1 when equal, falling linearly to 0 at double (or half) the reference. */
    public function closeness(?float $ref, ?float $other): ?float
    {
        if ($ref === null || $other === null || $ref <= 0) {
            return null;
        }

        return max(0.0, 1 - abs($other - $ref) / $ref);
    }

    /** 1 when the same or better (lower), otherwise reference ÷ other. */
    public function notWorse(int|float|null $ref, int|float|null $other): ?float
    {
        if ($ref === null || $other === null || $other <= 0) {
            return null;
        }

        return $other <= $ref ? 1.0 : $ref / $other;
    }
}
