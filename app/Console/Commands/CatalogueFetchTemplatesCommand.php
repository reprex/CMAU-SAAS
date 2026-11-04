<?php

namespace App\Console\Commands;

use App\Catalogue\TemplateFiles;
use App\Models\BrandingTemplate;
use App\Models\Product;
use Illuminate\Console\Command;

/**
 * Fetch a supplier's branding templates from its own website straight onto the SaaS's private templates
 * disk (Scott, 5 Nov 2026: "download the individual PDFs directly to there rather than my laptop"). The
 * addresses are read from the supplier's product pages by Claude in Scott's browser and kept in
 * database/data/supplier-pages/<supplier>-templates.json; the server fetches the files, which must be
 * public. Each lands in storage/app/private/branding-templates/<supplier id>/, is attached to quote and
 * invoice emails, and is read for positions by the minute cron. Safe to run again: fetched files are
 * skipped.
 */
class CatalogueFetchTemplatesCommand extends Command
{
    /** Supplier id => data file under database/data/supplier-pages. */
    public const FILES = [57 => 'legendlife-templates.json'];

    protected $signature = 'catalogue:fetch-templates {supplier : Promodata supplier id} {--limit=0 : At most this many files (0: all)}';

    protected $description = 'Fetch a supplier\'s branding templates from its website onto the private templates disk';

    public function handle(TemplateFiles $files): int
    {
        $supplier = (int) $this->argument('supplier');
        $file = self::FILES[$supplier] ?? null;
        $data = $file ? json_decode((string) @file_get_contents(database_path('data/supplier-pages/'.$file)), true) : null;
        if (! is_array($data['products'] ?? null)) {
            $this->error("No template list for supplier {$supplier} (database/data/supplier-pages).");

            return self::FAILURE;
        }
        $products = Product::query()->where('supplier_id', $supplier)->whereIn('tier', [Product::TIER_LIVE, Product::TIER_ACTIVE])->get(['id', 'supplier_id', 'code', 'tier'])
            ->keyBy(fn ($p) => mb_strtolower((string) $p->code));
        $out = ['imported' => 0, 'same' => 0, 'unmatched' => []];
        $limit = (int) $this->option('limit');
        $done = 0;
        $this->info(count($data['products']).' products with templates on the supplier\'s pages…');
        foreach ($data['products'] as $code => $paths) {
            $product = $products[mb_strtolower((string) $code)] ?? null;
            if (! $product) {
                $out['unmatched'][] = "{$code} (not on the range or kept products)";

                continue;
            }
            foreach ((array) $paths as $path) {
                if ($limit && $done >= $limit) {
                    break 2;
                }
                $url = preg_match('#^https?://#', $path) ? $path : rtrim((string) $data['base'], '/').'/'.implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
                if (BrandingTemplate::query()->where('product_id', $product->id)->where('url', 'like', 'file:'.$supplier.'/%')->whereNotNull('file_path')->exists()) {
                    $out['same']++;

                    continue;
                }
                $files->importUrl($product, $url, $out);
                $done++;
                if ($done % 25 === 0) {
                    $this->line("{$done} fetched…");
                }
            }
        }
        $this->info("Fetched {$out['imported']} templates, {$out['same']} already there.");
        if ($out['unmatched']) {
            $this->warn(count($out['unmatched']).' not fetched:');
            foreach (array_slice($out['unmatched'], 0, 40) as $u) {
                $this->line('  '.$u);
            }
        }

        return self::SUCCESS;
    }
}
