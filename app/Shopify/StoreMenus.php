<?php

namespace App\Shopify;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creates or replaces the CrowdMerch menus (config/shopify-menus.php; docs/menus.md) on the store
 * .env points at. Pages and collections are linked by id, so a page's handle can change without
 * breaking the menu; a target that isn't on the store is left out and reported. Only menus whose
 * handle is in the config are ever written.
 */
class StoreMenus
{
    /** @var array<string, ?array{type: string, id: ?string, url: string}> */
    private array $resolved = [];

    /** @var list<string> */
    public array $notes = [];

    public function __construct(private readonly ShopifyAdmin $shopify) {}

    /**
     * Works out every menu (no writes). Returns rows for a report.
     *
     * @return array<string, array{title: string, items: list<array<string, mixed>>, existing: ?string}>
     */
    public function plan(): array
    {
        $existing = $this->existingMenus();
        $out = [];
        foreach (config('shopify-menus.menus') as $handle => $menu) {
            $out[$handle] = ['title' => $menu['title'], 'items' => $this->items($menu['items'], $handle), 'existing' => $existing[$handle] ?? null];
        }

        return $out;
    }

    /** Writes the plan. Returns "created"/"updated"/"FAILED: …" per handle. */
    public function apply(array $plan): array
    {
        $this->shopify->assertWritable();
        $result = [];
        foreach ($plan as $handle => $menu) {
            $items = array_map(fn ($i) => $this->input($i), $menu['items']);
            if ($menu['existing']) {
                $r = $this->shopify->graphql('mutation($id: ID!, $title: String!, $handle: String!, $items: [MenuItemUpdateInput!]!) { menuUpdate(id: $id, title: $title, handle: $handle, items: $items) { menu { id } userErrors { field message } } }',
                    ['id' => $menu['existing'], 'title' => $menu['title'], 'handle' => $handle, 'items' => $items])['menuUpdate'] ?? [];
                $done = 'updated';
            } else {
                $r = $this->shopify->graphql('mutation($title: String!, $handle: String!, $items: [MenuItemCreateInput!]!) { menuCreate(title: $title, handle: $handle, items: $items) { menu { id } userErrors { field message } } }',
                    ['title' => $menu['title'], 'handle' => $handle, 'items' => $items])['menuCreate'] ?? [];
                $done = 'created';
            }
            $errors = collect($r['userErrors'] ?? [])->pluck('message')->implode('; ');
            $result[$handle] = $errors !== '' || empty($r['menu']['id']) ? 'FAILED: '.($errors ?: 'no menu returned') : $done;
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function input(array $item): array
    {
        $in = ['title' => $item['title'], 'type' => $item['type']];
        if ($item['id']) {
            $in['resourceId'] = $item['id'];
        } else {
            $in['url'] = $item['url'];
        }
        $in['items'] = array_map(fn ($c) => $this->input($c), $item['children'] ?? []);

        return $in;
    }

    /** @return list<array<string, mixed>> */
    private function items(array $specs, string $menu): array
    {
        $out = [];
        foreach ($specs as $spec) {
            [$title, $target] = $spec;
            $link = $this->resolve($target);
            if (! $link) {
                $this->notes[] = "{$menu}: \"{$title}\" left out, {$target} is not on the store.";

                continue;
            }
            $out[] = $link + ['title' => $title, 'target' => $target, 'children' => isset($spec[2]) ? $this->items($spec[2], $menu) : []];
        }

        return $out;
    }

    /** @return ?array{type: string, id: ?string, url: string} */
    private function resolve(string $target): ?array
    {
        if (array_key_exists($target, $this->resolved)) {
            return $this->resolved[$target];
        }
        if (str_contains($target, '|')) {
            // "a|b": the first target that is on the store.
            foreach (explode('|', $target) as $one) {
                if ($found = $this->resolve($one)) {
                    return $this->resolved[$target] = $found;
                }
            }

            return $this->resolved[$target] = null;
        }
        [$kind, $value] = explode(':', $target, 2);

        return $this->resolved[$target] = match ($kind) {
            'url' => ['type' => 'HTTP', 'id' => null, 'url' => $value],
            'page' => $this->find('pages', 'PAGE', 'handle:'.$value, '/pages/'),
            'collection' => $this->find('collections', 'COLLECTION', 'handle:'.$value, '/collections/'),
            'pick' => $this->pick($value),
            default => throw new RuntimeException("Unknown menu target {$target}"),
        };
    }

    private function pick(string $key): ?array
    {
        $pick = config("shopify-menus.picks.{$key}") ?? throw new RuntimeException("No pick {$key} in config/shopify-menus.php");
        foreach ($pick['tags'] as $tag) {
            $handle = DB::table('shopify_collections')->where('tag', $tag)->whereNotNull('handle')->value('handle');
            if ($handle && ($found = $this->find('collections', 'COLLECTION', 'handle:'.$handle, '/collections/'))) {
                return $found;
            }
        }
        foreach ($pick['titles'] as $title) {
            if ($found = $this->find('collections', 'COLLECTION', 'title:"'.$title.'"', '/collections/', $title)) {
                return $found;
            }
        }
        $this->notes[] = "{$key}: no collection found, linked to {$pick['fallback']} instead.";

        return $this->resolve($pick['fallback']);
    }

    /** One page or collection by a search query; an exact title match is required when given. */
    private function find(string $field, string $type, string $query, string $prefix, ?string $title = null): ?array
    {
        $nodes = $this->shopify->graphql("query(\$q: String!) { {$field}(first: 10, query: \$q) { nodes { id handle title } } }", ['q' => $query])[$field]['nodes'] ?? [];
        foreach ($nodes as $n) {
            $ok = $title !== null ? mb_strtolower($n['title']) === mb_strtolower($title) : 'handle:'.$n['handle'] === $query;
            if ($ok) {
                return ['type' => $type, 'id' => $n['id'], 'url' => $prefix.$n['handle']];
            }
        }

        return null;
    }

    /** @return array<string, string> handle => id */
    private function existingMenus(): array
    {
        $nodes = $this->shopify->graphql('{ menus(first: 100) { nodes { id handle } } }')['menus']['nodes'] ?? [];

        return collect($nodes)->pluck('id', 'handle')->all();
    }
}
