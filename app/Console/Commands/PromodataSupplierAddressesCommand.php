<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use App\Sources\Promodata\PromodataClient;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

/**
 * Suppliers' dispatch suburb and postcode from Promodata (Scott, 7 Nov 2026; docs/freight.md).
 * Reads Promodata's supplier list (and, as a fallback, the supplier block on a product), shows
 * what Promodata holds, and with --apply fills the dispatch address ONLY where both suburb and
 * postcode are still empty in the SaaS. Staff edits in Admin › Suppliers are never overwritten.
 */
class PromodataSupplierAddressesCommand extends Command
{
    protected $signature = 'promodata:supplier-addresses {--apply : Fill empty dispatch addresses}';

    protected $description = 'Read suppliers\' addresses from Promodata and fill empty dispatch suburbs and postcodes';

    public function handle(PromodataClient $promodata): int
    {
        $records = [];
        foreach (['/suppliers', '/supplier', '/suppliers/list'] as $path) {
            $json = $promodata->get($path);
            if ($json === null) {
                $this->line("{$path}: not found");

                continue;
            }
            $rows = $this->rows($json);
            $this->info("{$path}: ".count($rows).' records');
            if ($rows) {
                $this->line('  Fields: '.implode(', ', array_keys(Arr::dot($rows[0]))));
                $records = $rows;
                break;
            }
        }
        if ($records === []) {
            $product = $promodata->listProducts(1)['data'][0] ?? null;
            $block = (array) ($product['supplier'] ?? []);
            $this->warn('No supplier list from Promodata. A product\'s supplier block has: '.implode(', ', array_keys(Arr::dot($block))));
            $records = $block ? [$block] : [];
        }

        $found = 0;
        $filled = 0;
        $rows = [];
        foreach ($records as $r) {
            $flat = Arr::dot($r);
            $id = (int) ($r['supplier_id'] ?? $r['id'] ?? 0);
            $postcode = $this->pick($flat, '/(^|\.|_)(post_?code|postal_?code|zip)$/i');
            $suburb = $this->pick($flat, '/(^|\.|_)(suburb|city|town|locality)$/i');
            if (! $id || ! $postcode) {
                continue;
            }
            $found++;
            $s = Supplier::query()->find($id);
            $status = ! $s ? 'not in SaaS' : (filled($s->dispatch_postcode) || filled($s->dispatch_suburb) ? 'kept SaaS value' : ($this->option('apply') ? 'filled' : 'would fill'));
            if ($s && $status === 'filled') {
                $s->update(['dispatch_postcode' => $postcode, 'dispatch_suburb' => $suburb ? mb_strtoupper($suburb) : null, 'dispatch_source' => 'promodata']);
                $filled++;
            }
            $rows[] = [$id, $s?->name ?? ($r['name'] ?? $r['supplier'] ?? '?'), $suburb ?: '-', $postcode, $status];
        }
        if ($rows) {
            $this->table(['Id', 'Supplier', 'Suburb', 'Postcode', 'SaaS'], $rows);
        }
        $this->line("Promodata gave a postcode for {$found} supplier(s).".($this->option('apply') ? " Filled {$filled}." : ' Nothing written; add --apply (st suppaddrgo).'));

        return self::SUCCESS;
    }

    /** @return list<array<string, mixed>> */
    private function rows(array $json): array
    {
        $list = $json['data'] ?? $json['suppliers'] ?? $json;

        return array_values(array_filter((array) $list, 'is_array'));
    }

    private function pick(array $flat, string $pattern): ?string
    {
        foreach ($flat as $k => $v) {
            if (is_scalar($v) && filled($v) && preg_match($pattern, (string) $k)) {
                return trim((string) $v);
            }
        }

        return null;
    }
}
