<?php

namespace App\Console\Commands;

use App\Models\Product;
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
            // Further pages, if Promodata pages the list.
            for ($page = 2; $page <= 50 && $rows && ($json['next_page_url'] ?? $json['links']['next'] ?? (($json['meta']['current_page'] ?? $json['current_page'] ?? 1) < ($json['meta']['last_page'] ?? $json['last_page'] ?? 1))); $page++) {
                $json = $promodata->get($path, ['page' => $page]) ?? [];
                $more = $this->rows($json);
                if (! $more) {
                    break;
                }
                $rows = array_merge($rows, $more);
            }
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
            $id = (int) ($r['supplier_id'] ?? $r['id'] ?? 0);
            $addr = $this->address($r);
            if (! $id || ! $addr) {
                continue;
            }
            ['suburb' => $suburb, 'postcode' => $postcode, 'type' => $type] = $addr;
            $found++;
            $s = Supplier::query()->find($id);
            $status = ! $s ? 'not in SaaS' : (filled($s->dispatch_postcode) || filled($s->dispatch_suburb) ? 'kept SaaS value' : ($this->option('apply') ? 'filled' : 'would fill'));
            if ($s && $status === 'filled') {
                $s->update(['dispatch_postcode' => $postcode, 'dispatch_suburb' => $suburb ? mb_strtoupper($suburb) : null, 'dispatch_source' => 'promodata']);
                $filled++;
            }
            $rows[] = [$id, $s?->name ?? ($r['name'] ?? $r['supplier'] ?? '?'), $suburb ?: '-', $postcode, $type ?: '-', $status];
        }
        if ($rows) {
            $this->table(['Id', 'Supplier', 'Suburb', 'Postcode', 'Address type', 'SaaS'], $rows);
        }
        $withAddress = array_column($rows, 0);
        $missing = Supplier::query()->whereNotIn('id', $withAddress ?: [0])->whereNull('dispatch_postcode')
            ->whereHas('products', fn ($q) => $q->where('tier', Product::TIER_LIVE))->orderBy('name')->pluck('name');
        if ($missing->isNotEmpty()) {
            $this->warn('Suppliers with live products and no address from Promodata or staff ('.$missing->count().'): '.$missing->implode(', ').'. Add them in Admin › Suppliers.');
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

    /**
     * The address goods ship from: a warehouse / dispatch / physical address first, never a PO Box
     * or Locked Bag (their postcodes aren't delivery points); Australian only.
     *
     * @return array{suburb: ?string, postcode: string, type: ?string}|null
     */
    private function address(array $r): ?array
    {
        $list = array_values(array_filter((array) ($r['details']['addresses'] ?? $r['addresses'] ?? []), 'is_array'));
        $ok = array_filter($list, fn ($a) => preg_match('/^\d{4}$/', trim((string) ($a['postcode'] ?? '')))
            && ! preg_match('/\b(p\.?\s*o\.?\s*box|locked\s+bag|gpo\s+box)\b/i', (string) ($a['address'] ?? ''))
            && in_array(mb_strtoupper((string) ($a['country'] ?? 'AU')), ['AU', 'AUS', 'AUSTRALIA', ''], true));
        if (! $ok) {
            return null;
        }
        usort($ok, fn ($a, $b) => $this->rank((string) ($a['type'] ?? '')) <=> $this->rank((string) ($b['type'] ?? '')));
        $a = $ok[0];

        return ['suburb' => filled($a['suburb'] ?? null) ? trim((string) $a['suburb']) : null, 'postcode' => trim((string) $a['postcode']), 'type' => $a['type'] ?? null];
    }

    private function rank(string $type): int
    {
        return match (true) {
            (bool) preg_match('/warehouse|dispatch|despatch|pick.?up|distribution/i', $type) => 0,
            (bool) preg_match('/physical|street|delivery|office|head/i', $type) => 1,
            (bool) preg_match('/postal|mail|billing/i', $type) => 3,
            default => 2,
        };
    }
}
