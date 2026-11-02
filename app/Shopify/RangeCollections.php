<?php

namespace App\Shopify;

use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Range collections (Scott, 3 Nov 2026; docs/publisher.md). Every web-range product carries its
 * subcategory tag (the TOD style: "Bags > Duffle/Sports Bags" -> "dufflesportsbags", "Other Food
 * & Drink" -> "fooddrink"), each subcategory has a smart collection "TAG EQUALS <tag>" (the
 * store's existing ones are matched; missing ones are created the same way), and the SaaS sets
 * the order of products in each (manual sort): staff sort order, then the cheapest at the
 * display quantity, then products with colour photos, then name.
 */
class RangeCollections
{
    private const LIST = <<<'GQL'
    query($after: String) {
      collections(first: 100, after: $after) {
        pageInfo { hasNextPage endCursor }
        nodes { id title handle sortOrder templateSuffix ruleSet { rules { column relation condition } } }
      }
    }
    GQL;

    public function __construct(private readonly ShopifyAdmin $shopify) {}

    /** The subcategory part of a Promodata type: "Bags > Duffle/Sports Bags" -> "Duffle/Sports Bags". */
    public static function subcategory(string $type): string
    {
        $parts = array_map('trim', explode('>', $type));

        return (string) end($parts);
    }

    /** The TOD-style tag for a subcategory name. */
    public static function slug(string $name): string
    {
        $name = preg_replace('/^other\s+/i', '', trim($name));

        return Str::of(Str::ascii($name))->lower()->replaceMatches('/[^a-z0-9]+/', '')->toString();
    }

    /** The tag a product of this type carries: the matched collection's, else the slug. */
    public function tagFor(?string $type): ?string
    {
        if (! filled($type)) {
            return null;
        }
        $map = Cache::remember('range-collections:tags', 600, fn () => DB::table('shopify_collections')->pluck('tag', 'product_type')->all());

        return $map[$type] ?? (self::slug(self::subcategory($type)) ?: null);
    }

    /** @return list<array{id: string, title: string, handle: string, tag: ?string, sort: string, template: ?string}> */
    public function storeCollections(): array
    {
        $out = [];
        $after = null;
        do {
            $d = $this->shopify->graphql(self::LIST, ['after' => $after])['collections'] ?? [];
            foreach ($d['nodes'] ?? [] as $c) {
                $rules = $c['ruleSet']['rules'] ?? [];
                $tag = count($rules) === 1 && $rules[0]['column'] === 'TAG' && $rules[0]['relation'] === 'EQUALS' ? mb_strtolower($rules[0]['condition']) : null;
                $out[] = ['id' => $c['id'], 'title' => $c['title'], 'handle' => $c['handle'], 'tag' => $tag, 'sort' => $c['sortOrder'], 'template' => $c['templateSuffix']];
            }
            $after = ($d['pageInfo']['hasNextPage'] ?? false) ? $d['pageInfo']['endCursor'] : null;
        } while ($after);

        return $out;
    }

    /**
     * Match every range type to a store collection (by rule tag, then by title); with $create,
     * make the missing ones. Returns rows for a report.
     *
     * @return list<array{type: string, tag: string, status: string, handle: ?string, products: int}>
     */
    public function sync(bool $create): array
    {
        $store = collect($this->storeCollections());
        $byTag = $store->filter(fn ($c) => $c['tag'])->keyBy('tag');
        $byTitle = $store->keyBy(fn ($c) => self::slug($c['title']));
        $types = Product::query()->where('tier', Product::TIER_LIVE)->where('discontinued', false)->whereNotNull('promodata_type_name')
            ->selectRaw('promodata_type_name as t, count(*) as n')->groupBy('promodata_type_name')->orderByDesc('n')->get();
        $rows = [];
        $usedHandles = $store->pluck('handle')->flip()->all();
        foreach ($types as $t) {
            $sub = self::subcategory($t->t);
            $slug = self::slug($sub);
            $match = $byTag->get($slug) ?? (($c = $byTitle->get($slug)) && $c['tag'] ? $c : null);
            if ($match) {
                $this->remember($t->t, $match['tag'], $match, false);
                $rows[] = ['type' => $t->t, 'tag' => $match['tag'], 'status' => 'existing', 'handle' => $match['handle'], 'products' => (int) $t->n];

                continue;
            }
            if (! $create) {
                $rows[] = ['type' => $t->t, 'tag' => $slug, 'status' => 'to create', 'handle' => 'custom-printed-'.$slug, 'products' => (int) $t->n];

                continue;
            }
            $handle = 'custom-printed-'.$slug;
            if (isset($usedHandles[$handle])) {
                $handle .= '-'.Str::random(4);
            }
            $made = $this->shopify->graphql('mutation($input: CollectionInput!) { collectionCreate(input: $input) { collection { id handle } userErrors { field message } } }', ['input' => [
                'title' => $sub,
                'handle' => $handle,
                'ruleSet' => ['appliedDisjunctively' => false, 'rules' => [['column' => 'TAG', 'relation' => 'EQUALS', 'condition' => $slug]]],
                'sortOrder' => 'MANUAL',
                'templateSuffix' => 'col_as_variant',
            ]])['collectionCreate'] ?? [];
            if (! empty($made['userErrors']) || empty($made['collection']['id'])) {
                $rows[] = ['type' => $t->t, 'tag' => $slug, 'status' => 'FAILED: '.collect($made['userErrors'] ?? [])->pluck('message')->implode('; '), 'handle' => $handle, 'products' => (int) $t->n];

                continue;
            }
            app(ProductPublisher::class)->showOnOnlineStore($made['collection']['id']);
            $usedHandles[$made['collection']['handle']] = true;
            $byTag->put($slug, ['id' => $made['collection']['id'], 'handle' => $made['collection']['handle'], 'title' => $sub, 'tag' => $slug]);
            $this->remember($t->t, $slug, ['id' => $made['collection']['id'], 'handle' => $made['collection']['handle'], 'title' => $sub], true);
            $rows[] = ['type' => $t->t, 'tag' => $slug, 'status' => 'created', 'handle' => $made['collection']['handle'], 'products' => (int) $t->n];
        }
        Cache::forget('range-collections:tags');

        return $rows;
    }

    private function remember(string $type, string $tag, array $c, bool $created): void
    {
        $values = ['tag' => $tag, 'title' => $c['title'] ?? null, 'handle' => $c['handle'] ?? null, 'shopify_gid' => $c['id'] ?? null, 'updated_at' => now()];
        if (DB::table('shopify_collections')->where('product_type', $type)->exists()) {
            DB::table('shopify_collections')->where('product_type', $type)->update($values + ($created ? ['created_by_saas' => true] : []));
        } else {
            DB::table('shopify_collections')->insert($values + ['product_type' => $type, 'created_by_saas' => $created, 'created_at' => now()]);
        }
    }

    /** The order products should have in a collection: published products of these types. */
    public function order(array $types): array
    {
        $rows = Product::query()->with(['override', 'webPrice'])->withCount('colours')
            ->where('tier', Product::TIER_LIVE)->where('discontinued', false)->whereIn('promodata_type_name', $types)->whereNotNull('shopify_product_id')->get();

        return $rows->sortBy(fn (Product $p) => [
            (int) ($p->overrideValue('master_sort_order') ?: 99999),
            $p->webPrice?->collection_variant_price !== null ? (float) $p->webPrice->collection_variant_price : 999999.0,
            $p->colours_count > 0 ? 0 : 1,
            mb_strtolower($p->name),
        ])->pluck('shopify_product_id')->values()->all();
    }

    /** Sets the manual order of each range collection, oldest-sorted first, within a time budget. */
    public function sort(int $seconds = 45): int
    {
        $started = microtime(true);
        $done = 0;
        $cols = DB::table('shopify_collections')->whereNotNull('shopify_gid')->orderByRaw('sorted_at is not null')->orderBy('sorted_at')->get()->groupBy('shopify_gid');
        foreach ($cols as $gid => $group) {
            if (microtime(true) - $started > $seconds) {
                break;
            }
            $ids = $this->order($group->pluck('product_type')->all());
            foreach (array_chunk($ids, 250, true) as $chunk) {
                $moves = [];
                foreach ($chunk as $pos => $id) {
                    $moves[] = ['id' => 'gid://shopify/Product/'.$id, 'newPosition' => (string) $pos];
                }
                $r = $this->shopify->graphql('mutation($id: ID!, $moves: [MoveInput!]!) { collectionReorderProducts(id: $id, moves: $moves) { userErrors { field message } } }', ['id' => $gid, 'moves' => $moves]);
                if (! empty($r['collectionReorderProducts']['userErrors'])) {
                    break;
                }
            }
            DB::table('shopify_collections')->where('shopify_gid', $gid)->update(['sorted_at' => now()]);
            $done++;
        }

        return $done;
    }
}
