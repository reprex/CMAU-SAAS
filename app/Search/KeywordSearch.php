<?php

namespace App\Search;

use App\Checkout\LeadTimes;
use App\Models\Product;
use App\Quotes\WebsiteLinePricer;
use App\Shopify\RangeCollections;
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

    /**
     * The visitor's results (Scott, 3 Nov 2026): subcategories named through CrowdMerch's synonym list first
     * ("water bottles", "keep cups", "pens"); else an occasion's spread of subcategories ("gifts under $30",
     * "merch stand essentials"), a few of the best from each; else words in product names and types.
     *
     * @param  array<string, mixed>  $brief  from BriefParser
     */
    public function search(array $brief, int $limit = 24): Collection
    {
        if ($types = Synonyms::typesFor($brief['tags'] ?? [])) {
            return $this->spread($types, $brief, $limit, count($types) === 1 ? $limit : max(4, intdiv($limit, count($types))));
        }
        if (! empty($brief['occasions'])) {
            $types = Synonyms::typesFor(Occasions::tags($brief['occasions']));
            if ($types) {
                return $this->spread(array_slice($types, 0, 12), $brief, $limit, 3);
            }
        }

        return $this->words($brief, $limit);
    }

    /**
     * A few of the best products from each product type, taken in turn so every type gets a showing.
     *
     * @param  list<string>  $types  best first
     */
    /**
     * The top-ranked products of these subcategories (tags), a few of each in turn.
     *
     * @param  list<string>  $tags  best first
     */
    public function forTags(array $tags, array $brief, int $limit = 24, int $perType = 3): Collection
    {
        return $this->spread(Synonyms::typesFor($tags), $brief, $limit, $perType);
    }

    private function spread(array $types, array $brief, int $limit, int $perType): Collection
    {
        $words = array_values(array_unique(array_merge($brief['rest'] ?? [], $brief['colours'] ?? [])));
        $lists = [];
        foreach ($types as $type) {
            $q = $this->live()->with('override')->withCount('colours')->where('promodata_type_name', $type);
            if (! empty($brief['budget'])) {
                // Roughly within budget at the minimum order; the exact price at their quantity is checked below.
                $q->whereHas('webPrice', fn ($w) => $w->whereNull('collection_variant_price')->orWhere('collection_variant_price', '<=', $brief['budget'] * 1.6));
            }
            // The collection page's order (staff sort, then price, then products with colour photos), visitor's words first.
            $rows = $q->limit(60)->get()->sortBy(fn (Product $p) => [-$this->wordScore($p, $words), (int) ($p->overrideValue('master_sort_order') ?: 99999),
                (float) ($p->webPrice?->collection_variant_price ?? 9999), $p->colours_count > 0 ? 0 : 1])->values();
            $lists[] = $this->rank($rows->take($perType * 3), $brief, $words, false)->take($perType)->values()->all();
        }

        $out = [];
        for ($i = 0; count($out) < $limit && $i < $perType; $i++) {
            foreach ($lists as $list) {
                if (isset($list[$i]) && count($out) < $limit) {
                    $out[] = $list[$i];
                }
            }
        }

        return collect($out);
    }

    private function wordScore(Product $p, array $words): int
    {
        $hay = mb_strtolower($p->name.' '.$p->material);
        $score = 0;
        foreach ($words as $w) {
            $score += str_contains($hay, Synonyms::singular($w)) ? 2 : 0;
        }

        return $score;
    }

    /** Words in product names, types and materials (the old keyword search), for anything the lists don't name. */
    private function words(array $brief, int $limit): Collection
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
     * A shortlist for Claude to choose from: the same spread as the visitor's results, wider (more types and
     * more of each), then the best-priced products of the most-stocked types for vague briefs. Never more than $max.
     */
    public function candidates(array $brief, int $max = 48): Collection
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

    /**
     * The subcategories in these results, in order, for the website's "pick one or more" buttons: tag, name,
     * the reason it suits them, how many products it has, a photo and the lowest price among the results.
     *
     * @return list<array{tag: string, name: string, why: ?string, count: int, image: ?string, from: ?float}>
     */
    public function groups(Collection|array $results): array
    {
        $out = [];
        foreach ($results as $r) {
            $tag = $r['tag'] ?? null;
            if (! $tag) {
                continue;
            }
            if (! isset($out[$tag])) {
                $types = Synonyms::typesFor([$tag]);
                $out[$tag] = ['tag' => $tag, 'name' => (string) ($r['group'] ?? $tag), 'why' => $r['reason'] ?? null,
                    'count' => $types ? $this->live()->whereIn('promodata_type_name', $types)->count() : 0, 'image' => $r['image'] ?? null, 'from' => $r['price'] ?? null];
            } elseif (($r['price'] ?? null) !== null && ($out[$tag]['from'] === null || $r['price'] < $out[$tag]['from'])) {
                $out[$tag]['from'] = $r['price'];
            }
        }

        return array_values($out);
    }

    private function live(): Builder
    {
        return Product::query()->where('tier', Product::TIER_LIVE)->where('discontinued', false)
            ->with('webPrice:product_id,default_decoration_option_id,actual_moq,collection_moq,collection_variant_price');
    }

    /** @return Collection<int, array<string, mixed>> */
    private function rank(Collection $rows, array $brief, array $words, bool $sort = true): Collection
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
            if ($brief['budget'] && $price !== null && $price > $brief['budget'] * ($brief['under'] ? 1.0 : 1.15)) {
                return null;
            }
            if (! empty($brief['budget_min']) && $price !== null && $price < $brief['budget_min'] * 0.85) {
                return null;
            }
            // Search by date (docs/lead-times.md): leave out what can't arrive in time.
            $earliest = ! empty($brief['need_by']) ? app(LeadTimes::class)->earliest($p) : null;
            if ($earliest !== null && $earliest > $brief['need_by']) {
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
                'id' => $p->id, 'name' => $p->name, 'type' => $p->promodata_type_name, 'group' => $p->promodata_type_name ? RangeCollections::subcategory($p->promodata_type_name) : null, 'tag' => app(RangeCollections::class)->tagFor($p->promodata_type_name), 'material' => $p->material,
                'image' => $p->hero_image_url, 'qty' => max($at, $moq), 'price' => $price !== null ? round($price, 2) : null,
                'moq' => $moq, 'lead_days' => $p->lead_time_days, 'score' => $score,
            ] + ($earliest !== null ? ['earliest' => $earliest] : []);
        })->filter()->when($sort, fn ($c) => $c->sortBy([['score', 'desc'], ['price', 'asc']]))->values();
    }
}
