<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductOverride;
use App\Support\SheetReader;
use Illuminate\Console\Command;
use Throwable;

/**
 * Carries the live store's tagging over to the SaaS (docs/publisher.md, "Tagging carried from the
 * live store"): the category, special and collection tags, the essentials, urgent and ecorec
 * subcategories, the under-MOQ text, the PDP collection name, the product tags the SaaS doesn't make
 * itself, and the master sort order. TOD's tagging tables never moved to the SaaS; without this the
 * first publish to live would blank the filters and collections that depend on them.
 *
 * Reads a Matrixify export (Products, with those metafields and Tags). Never calls Shopify.
 */
class ShopifyCarryTagsCommand extends Command
{
    protected $signature = 'shopify:carry-tags
        {file : Matrixify export of the live store (.xlsx or .csv)}
        {--sheet=Products : Worksheet name in an .xlsx}
        {--apply : Save them (without it, report only)}';

    protected $description = 'Carry the live store\'s tags, subcategories and sort order into the SaaS (report only without --apply)';

    /** Metafield key in the export => key under the product's carried overrides. */
    public const FIELDS = [
        'custom.tagcategory' => 'tagcategory',
        'custom.tagspecial' => 'tagspecial',
        'custom.tagcollection' => 'tagcollection',
        'custom.essentialssubcat' => 'essentialssubcat',
        'custom.urgentsubcat' => 'urgentsubcat',
        'custom.ecorecsubcat' => 'ecorecsubcat',
        'custom.undermoqtext' => 'undermoqtext',
        'my_fields.pdpcollectionname' => 'pdpcollectionname',
    ];

    public function handle(): int
    {
        $found = [];
        try {
            foreach (SheetReader::rows((string) $this->argument('file'), (string) $this->option('sheet')) as $r) {
                $h = trim((string) ($r['Handle'] ?? ''));
                if (! ctype_digit($h) || isset($found[$h])) {
                    continue; // variant rows repeat the handle; the product's values are on its first row
                }
                $carried = [];
                $sort = null;
                foreach ($r as $col => $value) {
                    $key = self::metafieldKey((string) $col);
                    if ($key === null || $value === '') {
                        continue;
                    }
                    if ($key === 'custom.mastersortorder') {
                        $sort = (int) $value;
                    } elseif (isset(self::FIELDS[$key])) {
                        $carried[self::FIELDS[$key]] = $value;
                    }
                }
                if (filled($r['Tags'] ?? null)) {
                    $carried['tags'] = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $r['Tags'])))));
                }
                $found[$h] = ['carried' => $carried, 'sort' => $sort];
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if ($found === []) {
            $this->error('No products in that file (it needs a Handle column, and the tag metafields and Tags).');

            return self::FAILURE;
        }

        $products = Product::query()->with('override')->whereIn('id', array_map('intval', array_keys($found)))->get()->keyBy('id');
        $n = ['in_file' => count($found), 'known' => $products->count(), 'on_range' => 0, 'changed' => 0, 'sort_set' => 0, 'sort_kept' => 0];
        $fieldCounts = array_fill_keys(array_merge(array_values(self::FIELDS), ['tags']), 0);
        foreach ($found as $f) {
            foreach ($f['carried'] as $k => $_) {
                $fieldCounts[$k]++;
            }
        }
        foreach ($products as $id => $product) {
            $f = $found[(string) $id];
            $n['on_range'] += $product->tier === Product::TIER_LIVE ? 1 : 0;
            $data = (array) ($product->override?->data ?? []);
            $before = $data;
            $data['carried'] = $f['carried'];
            if ($f['sort'] && $f['sort'] > 0 && $f['sort'] !== 99999) {
                if (isset($data['master_sort_order']) && ! isset($data['master_sort_order_carried'])) {
                    $n['sort_kept']++; // a staff sort order wins
                } else {
                    $data['master_sort_order'] = $f['sort'];
                    $data['master_sort_order_carried'] = true;
                    $n['sort_set']++;
                }
            }
            if ($data == $before) {
                continue;
            }
            $n['changed']++;
            if ($this->option('apply')) {
                ProductOverride::query()->updateOrCreate(['product_id' => $id], ['data' => $data]);
                if ($product->tier === Product::TIER_LIVE) {
                    $product->forceFill(['range_changed_at' => now()])->saveQuietly();
                }
            }
        }

        $this->table(['', 'Products'], [
            ['In the file', number_format($n['in_file'])],
            ['Known to the SaaS', number_format($n['known'])],
            ['  of which on the web range', number_format($n['on_range'])],
            ['Would change / changed', number_format($n['changed'])],
            ['Master sort order carried', number_format($n['sort_set'])],
            ['Master sort order kept (set by staff)', number_format($n['sort_kept'])],
        ]);
        $this->table(['Field in the file', 'Products with a value'], collect($fieldCounts)->map(fn ($c, $k) => [$k, number_format($c)])->values()->all());
        $this->line($this->option('apply') ? 'Saved. Products on the range are marked to publish again.' : 'Report only. Run again with --apply to save.');

        return self::SUCCESS;
    }

    /** "Metafield: custom.tagcategory [single_line_text_field]" or "Metafield:custom.mastersortorder [number_integer]" -> "custom.tagcategory" */
    public static function metafieldKey(string $column): ?string
    {
        return preg_match('/^\s*metafield:\s*([a-z0-9_]+\.[a-z0-9_]+)/i', $column, $m) ? mb_strtolower($m[1]) : null;
    }
}
