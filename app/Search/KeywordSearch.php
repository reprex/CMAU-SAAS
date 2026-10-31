<?php

namespace App\Search;

use App\Models\Product;
use App\Quotes\WebsiteLinePricer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * "Smart keyword" search over the live range: no AI, no cost. Matches product
 * types (with synonyms), names and materials; applies quantity (minimum order),
 * budget and colour; prices each result at the visitor's quantity with its
 * default branding, exactly as the product page would.
 */
class KeywordSearch
{
    public function __construct(private readonly WebsiteLinePricer $pricer) {}

    /** @param array<string, mixed> $brief from BriefParser */
    public function search(array $brief, int $limit = 12): Collection
    {
        $words = array_merge($brief['types'], $brief['terms']);
        if ($words === []) {
            return collect();
        }

        $rows = $this->live()
            ->where(function (Builder $q) use ($words) {
                foreach ($words as $w) {
                    $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $w).'%';
                    $q->orWhere('promodata_type_name', 'like', $like)->orWhere('name', 'like', $like)->orWhere('material', 'like', $like);
                }
            })
            ->limit(300)->get();

        return $this->rank($rows, $brief, $words)->take($limit)->values();
    }

    /**
     * A shortlist for Claude to choose from: keyword hits, then the best-priced
     * products of each product type mentioned, then (for vague briefs) a spread
     * across the most-stocked product types. Never more than $max.
     */
    public function candidates(array $brief, int $max = 40): Collection
    {
        $hits = $this->search($brief, $max);
        if ($hits->count() >= $max) {
            return $hits;
        }
        $types = $this->live()->whereNotNull('promodata_type_name')
            ->selectRaw('promodata_type_name, count(*) as n')->groupBy('promodata_type_name')->orderByDesc('n')->limit(24)->pluck('promodata_type_name');
        $extra = collect();
        foreach ($types as $type) {
            $extra = $extra->merge($this->live()->where('promodata_type_name', $type)->orderBy('undecorated_cost')->limit(3)->get());
        }

        return $hits->merge($this->rank($extra, $brief, [])->reject(fn ($r) => $hits->contains('id', $r['id'])))->take($max)->values();
    }

    private function live(): Builder
    {
        return Product::query()->where('tier', Product::TIER_LIVE)->where('discontinued', false)
            ->with('webPrice:product_id,default_decoration_option_id,actual_moq,collection_moq,collection_variant_price');
    }

    /** @return Collection<int, array<string, mixed>> */
    private function rank(Collection $rows, array $brief, array $words): Collection
    {
        $qty = $brief['qty'];

        return $rows->map(function (Product $p) use ($brief, $words, $qty) {
            $moq = (int) ($p->webPrice?->actual_moq ?: $p->undecorated_moq ?: 1);
            if ($qty !== null && $qty < $moq) {
                return null;
            }
            $hay = mb_strtolower($p->name.' '.$p->promodata_type_name.' '.$p->material);
            foreach ($brief['negatives'] as $neg) {
                if ($neg !== '' && str_contains($hay, rtrim($neg, 's'))) {
                    return null;
                }
            }
            $at = $qty ?? (int) ($p->webPrice?->collection_moq ?: $moq);
            $priced = $this->pricer->price($p->id, max($at, $moq), $p->webPrice?->default_decoration_option_id);
            $price = $priced['price'] ?? ($p->webPrice?->collection_variant_price !== null ? (float) $p->webPrice->collection_variant_price : null);
            if ($brief['budget'] && $price !== null && $price > $brief['budget'] * ($brief['under'] ? 1.0 : 1.25)) {
                return null;
            }
            $score = 0;
            foreach ($words as $w) {
                $score += str_contains(mb_strtolower((string) $p->promodata_type_name), $w) ? 3 : (str_contains($hay, $w) ? 1 : 0);
            }
            foreach ($brief['colours'] as $c) {
                $score += str_contains($hay, $c) ? 1 : 0;
            }
            if ($brief['eco'] && preg_match('/recycl|organic|bamboo|cotton|jute|eco/', $hay)) {
                $score += 2;
            }

            return [
                'id' => $p->id, 'name' => $p->name, 'type' => $p->promodata_type_name, 'material' => $p->material,
                'image' => $p->hero_image_url, 'qty' => max($at, $moq), 'price' => $price !== null ? round($price, 2) : null,
                'moq' => $moq, 'lead_days' => $p->lead_time_days, 'score' => $score,
            ];
        })->filter()->sortBy([['score', 'desc'], ['price', 'asc']])->values();
    }
}
