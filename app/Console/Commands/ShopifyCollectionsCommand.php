<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Shopify\RangeCollections;
use App\Shopify\ShopifyAdmin;
use Illuminate\Console\Command;

/**
 * Lists the store's collections (smart or manual, rules, sort order, product count) next to
 * the web range's product types (the tag every product gets), so missing collections show.
 */
class ShopifyCollectionsCommand extends Command
{
    protected $signature = 'shopify:collections
        {--sync : Match each range subcategory to its smart collection (dry run unless --create)}
        {--create : With --sync, create the missing collections}
        {--sort : Set the product order in the range collections}
        {--seconds=45 : Time budget for --sort}';

    protected $description = 'List the Shopify collections and the range product types they cover';

    private const QUERY = <<<'GQL'
    query($after: String) {
      collections(first: 100, after: $after) {
        pageInfo { hasNextPage endCursor }
        nodes { title handle sortOrder templateSuffix productsCount { count }
          ruleSet { appliedDisjunctively rules { column relation condition } } }
      }
    }
    GQL;

    public function handle(ShopifyAdmin $shopify, RangeCollections $collections): int
    {
        if ($this->option('sync')) {
            $rows = $collections->sync((bool) $this->option('create'));
            $this->table(['Product type', 'Tag', 'Collection', 'Handle', 'Products'], array_map(fn ($r) => [$r['type'], $r['tag'], $r['status'], $r['handle'], $r['products']], $rows));
            $by = collect($rows)->countBy(fn ($r) => str_starts_with($r['status'], 'FAILED') ? 'failed' : $r['status']);
            $this->info($by->map(fn ($n, $k) => "{$n} {$k}")->implode(', ').($this->option('create') ? '' : '. Add --create to make the missing ones.'));

            return self::SUCCESS;
        }
        if ($this->option('sort')) {
            $n = $collections->sort((int) $this->option('seconds'));
            $this->info("Sorted {$n} collections.");

            return self::SUCCESS;
        }

        $rows = [];
        $after = null;
        do {
            $d = $shopify->graphql(self::QUERY, ['after' => $after])['collections'] ?? [];
            foreach ($d['nodes'] ?? [] as $c) {
                $rules = $c['ruleSet'] ? implode($c['ruleSet']['appliedDisjunctively'] ? ' OR ' : ' AND ', array_map(fn ($r) => "{$r['column']} {$r['relation']} {$r['condition']}", $c['ruleSet']['rules'])) : '(manual)';
                $rows[] = [$c['title'], $c['handle'], $c['ruleSet'] ? 'smart' : 'manual', mb_strimwidth($rules, 0, 70, '…'), $c['sortOrder'], $c['templateSuffix'] ?: '-', $c['productsCount']['count'] ?? 0];
            }
            $after = ($d['pageInfo']['hasNextPage'] ?? false) ? $d['pageInfo']['endCursor'] : null;
        } while ($after);
        $this->info(count($rows).' collections on '.config('shopify.store'));
        $this->table(['Title', 'Handle', 'Type', 'Rules', 'Sort', 'Template', 'Products'], $rows);

        $tagRules = collect($rows)->filter(fn ($r) => str_starts_with($r[3], 'TAG EQUALS '))->map(fn ($r) => mb_strtolower(substr($r[3], 11)))->all();
        $types = Product::query()->where('tier', Product::TIER_LIVE)->where('discontinued', false)->whereNotNull('promodata_type_name')
            ->selectRaw('promodata_type_name as t, count(*) as n')->groupBy('promodata_type_name')->orderByDesc('n')->get();
        $this->info($types->count().' product types in the web range (each is a product tag):');
        $this->table(['Product type (tag)', 'Products', 'Has a TAG EQUALS collection'], $types->map(fn ($t) => [$t->t, $t->n, in_array(mb_strtolower($t->t), $tagRules, true) ? 'yes' : '-'])->all());

        return self::SUCCESS;
    }
}
