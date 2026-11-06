<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Shopify\ProductPayload;
use App\Support\SheetReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Before switching to the live store (docs/publisher.md, "Switching to the live store"): compares a
 * Matrixify export of the live store's products and variants with what the SaaS would publish.
 * Read-only: reads the file and the database, never calls Shopify.
 *
 * The publisher finds each live product by its handle (APPAID) and sends its colour variants by
 * colour name. A colour whose name is unchanged keeps its variant; a renamed or dropped colour's
 * variant is replaced or removed; a new colour is added.
 */
class ShopifyVariantMatchCommand extends Command
{
    protected $signature = 'shopify:variant-match
        {file : Matrixify export of the live store (Products with variants, .xlsx or .csv), uploaded to the server}
        {--sheet=Products : Worksheet name in an .xlsx}
        {--examples=15 : Examples to show per group}';

    protected $description = 'Compare a live-store Matrixify export with what the SaaS would publish (read-only)';

    public function handle(ProductPayload $payload): int
    {
        $path = (string) $this->argument('file');
        $live = [];
        $titles = [];
        $status = [];
        try {
            foreach (SheetReader::rows($path, (string) $this->option('sheet')) as $r) {
                $handle = trim((string) ($r['Handle'] ?? ''));
                if ($handle === '') {
                    continue;
                }
                if (filled($r['Title'] ?? null)) {
                    $titles[$handle] ??= (string) $r['Title'];
                }
                if (filled($r['Status'] ?? null)) {
                    $status[$handle] ??= mb_strtolower((string) $r['Status']);
                }
                $value = trim((string) ($r['Option1 Value'] ?? ''));
                if ($value === '' && ! filled($r['Variant SKU'] ?? null)) {
                    $live[$handle] ??= [];

                    continue;
                }
                $live[$handle][] = ['label' => $value, 'sku' => (string) ($r['Variant SKU'] ?? ''), 'id' => (string) ($r['Variant ID'] ?? '')];
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if ($live === []) {
            $this->error('No rows with a Handle in that file. Export Products with their variants (Handle, Title, Status, Option1 Value, Variant SKU, Variant ID).');

            return self::FAILURE;
        }

        $range = Product::query()->with(['images', 'colours', 'override'])->where('tier', Product::TIER_LIVE)->where('discontinued', false)->orderBy('id');
        $onRange = [];
        $rows = [];
        $count = ['products_both' => 0, 'products_new' => 0, 'products_live_only' => 0, 'products_live_only_archived' => 0,
            'same' => 0, 'renamed' => 0, 'dropped' => 0, 'new' => 0, 'sku_differs' => 0, 'products_all_same' => 0, 'new_product_variants' => 0];
        $examples = [];
        $add = function (string $group, string $text) use (&$examples) {
            $examples[$group] ??= [];
            if (count($examples[$group]) < (int) $this->option('examples')) {
                $examples[$group][] = $text;
            }
        };

        $range->chunk(200, function ($products) use ($payload, &$live, &$onRange, &$rows, &$count, $add) {
            foreach ($products as $product) {
                $h = (string) $product->id;
                $onRange[$h] = true;
                $ours = $payload->variants($product);
                if (! array_key_exists($h, $live)) {
                    $count['products_new']++;
                    $count['new_product_variants'] += count($ours);
                    foreach ($ours as $v) {
                        $rows[] = [$h, $product->name, 'new product', '', '', $v['label'], $v['sku'], ''];
                    }
                    $add('new products', "{$h} {$product->name} (".count($ours).' colours)');

                    continue;
                }
                $count['products_both']++;
                $theirs = $live[$h];
                $byExact = [];
                $byLoose = [];
                foreach ($theirs as $i => $t) {
                    $byExact[$t['label']] ??= $i;
                    $byLoose[self::loose($t['label'])] ??= $i;
                }
                $usedLive = [];
                $allSame = true;
                foreach ($ours as $v) {
                    $i = $byExact[$v['label']] ?? null;
                    $kind = 'same';
                    if ($i === null || isset($usedLive[$i])) {
                        $i = $byLoose[self::loose($v['label'])] ?? null;
                        $kind = ($i !== null && ! isset($usedLive[$i])) ? 'renamed' : 'new';
                        if ($kind === 'new') {
                            $i = null;
                        }
                    }
                    $t = $i !== null ? $theirs[$i] : null;
                    if ($i !== null) {
                        $usedLive[$i] = true;
                    }
                    $count[$kind]++;
                    if ($kind !== 'same') {
                        $allSame = false;
                        $add($kind === 'renamed' ? 'renamed colours' : 'new colours on live products', "{$h}: ".($t ? "\"{$t['label']}\" -> " : '')."\"{$v['label']}\"");
                    }
                    if ($t && $t['sku'] !== '' && $t['sku'] !== $v['sku']) {
                        $count['sku_differs']++;
                        $add('SKU differs', "{$h} {$v['label']}: live {$t['sku']}, SaaS {$v['sku']}");
                    }
                    $rows[] = [$h, $product->name, $kind, $t['label'] ?? '', $t['sku'] ?? '', $v['label'], $v['sku'], $t['id'] ?? ''];
                }
                foreach ($theirs as $i => $t) {
                    if (! isset($usedLive[$i]) && $t['label'] !== '') {
                        $count['dropped']++;
                        $allSame = false;
                        $add('colours on live that the SaaS drops', "{$h}: \"{$t['label']}\"");
                        $rows[] = [$h, $product->name, 'dropped', $t['label'], $t['sku'], '', '', $t['id']];
                    }
                }
                if ($allSame) {
                    $count['products_all_same']++;
                }
            }
        });

        foreach ($live as $h => $theirs) {
            if (isset($onRange[$h])) {
                continue;
            }
            $archived = ($status[$h] ?? '') === 'archived';
            $count[$archived ? 'products_live_only_archived' : 'products_live_only']++;
            if (! $archived) {
                $add('live products not on the SaaS range', $h.' '.($titles[$h] ?? ''));
            }
            foreach ($theirs ?: [['label' => '', 'sku' => '', 'id' => '']] as $t) {
                $rows[] = [$h, $titles[$h] ?? '', $archived ? 'live only (archived)' : 'live only', $t['label'], $t['sku'], '', '', $t['id']];
            }
        }

        $file = 'variant-match/variant-match-'.now()->format('Ymd-His').'.csv';
        $csv = fopen('php://temp', 'r+');
        fputcsv($csv, ['handle', 'title', 'result', 'live colour', 'live sku', 'saas colour', 'saas sku', 'live variant id'], ',', '"', '');
        foreach ($rows as $r) {
            fputcsv($csv, $r, ',', '"', '');
        }
        rewind($csv);
        Storage::disk('local')->put($file, stream_get_contents($csv));

        $n = fn ($v) => number_format((int) $v);
        $this->info('Products');
        $this->table(['', 'Products'], [
            ['On the live store and the SaaS range', $n($count['products_both'])],
            ['  of which every colour matches', $n($count['products_all_same'])],
            ['On the SaaS range, not on live (created)', $n($count['products_new']).' ('.$n($count['new_product_variants']).' variants)'],
            ['On live (active or draft), not on the SaaS range', $n($count['products_live_only'])],
            ['On live (archived), not on the SaaS range', $n($count['products_live_only_archived'])],
        ]);
        $this->info('Colour variants of products on both');
        $this->table(['', 'Variants'], [
            ['Same colour name: variant kept', $n($count['same'])],
            ['Same colour, different spelling or case: replaced', $n($count['renamed'])],
            ['Colour new in the SaaS: added', $n($count['new'])],
            ['Colour on live the SaaS drops: removed', $n($count['dropped'])],
            ['SKU differs on a matched colour', $n($count['sku_differs'])],
        ]);
        foreach ($examples as $group => $list) {
            $this->line("<comment>{$group}</comment>");
            foreach ($list as $line) {
                $this->line('  '.$line);
            }
        }
        $this->line('Every row: '.Storage::disk('local')->path($file));

        return self::SUCCESS;
    }

    /** Spelling-insensitive key: "Navy / White" == "navy-white" == "NavyWhite". */
    private static function loose(string $label): string
    {
        return mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $label) ?? $label);
    }
}
