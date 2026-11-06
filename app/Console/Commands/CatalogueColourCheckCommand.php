<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Why products the live store sells in colours would go to a single "Custom Print" variant (read-only):
 * reads the latest variant-match CSV and, for each such product, shows Promodata's colours, the hidden
 * ones, the supplier's colour text and the live colours carried over.
 */
class CatalogueColourCheckCommand extends Command
{
    protected $signature = 'catalogue:colour-check {--examples=12}';

    protected $description = 'Why live colour products would become "Custom Print" (read-only)';

    public function handle(): int
    {
        $files = File::glob(storage_path('app/private/variant-match/*.csv')) ?: File::glob(storage_path('app/variant-match/*.csv'));
        if (! $files) {
            $this->error('Run shopify:variant-match first.');

            return self::FAILURE;
        }
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        $ids = [];
        $h = fopen($files[0], 'r');
        fgetcsv($h, 0, ',', '"', '');
        while (($r = fgetcsv($h, 0, ',', '"', '')) !== false) {
            if (($r[2] ?? '') === 'new' && ($r[5] ?? '') === 'Custom Print') {
                $ids[(int) $r[0]] = true;
            }
        }
        fclose($h);
        $buckets = ['no colours from Promodata' => [], 'all colours hidden' => [], 'other' => []];
        foreach (Product::query()->with(['colours', 'override', 'detail'])->whereIn('id', array_keys($ids))->get() as $p) {
            $hidden = (array) $p->overrideValue('hidden_colours', []);
            $names = $p->colours->pluck('name')->unique();
            $bucket = $names->isEmpty() ? 'no colours from Promodata' : ($names->diff($hidden)->isEmpty() ? 'all colours hidden' : 'other');
            $live = array_values((array) $p->overrideValue('carried.colours', []));
            $text = collect((array) ($p->detail?->colour_notes ?? []))->map(fn ($n) => is_array($n) ? trim(($n['name'] ?? '').': '.($n['detail'] ?? '')) : (string) $n)->implode(' | ');
            $buckets[$bucket][] = "{$p->id} {$p->name}: Promodata ".$names->count().', hidden '.count($hidden).', live ['.mb_strimwidth(implode(', ', $live), 0, 60, '…').']'.($text !== '' ? ' · text: '.mb_strimwidth($text, 0, 80, '…') : '');
        }
        foreach ($buckets as $name => $rows) {
            $this->info($name.': '.count($rows));
            foreach (array_slice($rows, 0, (int) $this->option('examples')) as $line) {
                $this->line('  '.$line);
            }
        }
        $this->line('Products with no Promodata colours now take the live colours (release after 7 Nov 06:10); run st vm again to see.');

        return self::SUCCESS;
    }
}
