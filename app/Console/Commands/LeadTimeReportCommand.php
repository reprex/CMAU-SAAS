<?php

namespace App\Console\Commands;

use App\Catalogue\DecorationAreas;
use App\Catalogue\ProductFacts;
use App\Models\PriceLine;
use App\Models\PriceOption;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * What Promodata says about lead times on the live range, per supplier (docs/lead-times.md, step 1).
 * Read-only. Decides which suppliers' lead times can come from Promodata, and which need their websites.
 */
class LeadTimeReportCommand extends Command
{
    protected $signature = 'catalogue:lead-time-report
        {--supplier= : Only this supplier, with more examples}
        {--examples=4 : Lead-time wordings to show per supplier}';

    protected $description = 'Report Promodata lead times on the live range, per supplier and decoration (read-only)';

    private const MENTIONS = '/lead\s*time|production\s*time|turn\s*-?\s*around|working\s+days|business\s+days|dispatch/i';

    public function handle(): int
    {
        $names = Supplier::query()->pluck('name', 'id');
        $live = Product::query()->where('tier', Product::TIER_LIVE)->where('discontinued', false)
            ->when($this->option('supplier'), fn ($q, $s) => $q->where('supplier_id', (int) $s));
        $bySupplier = (clone $live)->selectRaw('supplier_id, count(*) n')->groupBy('supplier_id')->pluck('n', 'supplier_id')->sortDesc();
        $examples = max(1, (int) $this->option('examples')) * ($this->option('supplier') ? 4 : 1);

        $this->info('Promodata lead times on the live range ('.number_format($bySupplier->sum()).' products), per supplier:');
        $this->line('  products: with a lead time at all · on the base (blank or branded) price · on decoration lines · borrowed decorations (e.g. Melbourne Merch) · wording in the product details');
        foreach ($bySupplier as $sid => $n) {
            $ids = (clone $live)->where('supplier_id', $sid)->pluck('id');
            $lines = PriceLine::query()->whereIn('product_id', $ids)->get(['product_id', 'base_price_line_id', 'description', 'lead_time', 'type']);
            $timed = $lines->filter(fn ($l) => filled($l->lead_time));
            $base = $timed->whereNull('base_price_line_id')->pluck('product_id')->unique()->count();
            $decoLines = $lines->whereNotNull('base_price_line_id');
            $decoTimed = $decoLines->filter(fn ($l) => filled($l->lead_time));
            $borrowed = PriceOption::query()->whereIn('product_id', $ids)->where('kind', PriceOption::KIND_EXTERNAL)->distinct()->count('product_id');
            $details = DB::table('product_details')->whereIn('product_id', $ids)->get(['product_id', 'details', 'promodata_attributes'])
                ->filter(fn ($d) => preg_match(self::MENTIONS, (string) $d->details.' '.(string) $d->promodata_attributes));
            $mentions = $details->count();

            $this->newLine();
            $this->line(sprintf('%s (%d): %d products · %d with a lead time (%d%%) · base %d · decoration lines %d of %d · borrowed %d · details %d',
                $names[$sid] ?? "Supplier {$sid}", $sid, $n, $timed->pluck('product_id')->unique()->count(),
                $n ? round(100 * $timed->pluck('product_id')->unique()->count() / $n) : 0, $base, $decoTimed->count(), $decoLines->count(), $borrowed, $mentions));

            // Does the lead time differ by decoration method? (Trends: yes, by its own pages.)
            $byMethod = $decoTimed->groupBy(fn ($l) => DecorationAreas::method((string) $l->description) ?? 'other')
                ->map(fn ($g) => $g->map(fn ($l) => ProductFacts::parseLeadTime((string) $l->lead_time))->filter()->countBy()->sortDesc()->keys()->take(3)->implode('/'))
                ->filter();
            if ($byMethod->isNotEmpty()) {
                $this->line('    by method (working days, most common first): '.$byMethod->map(fn ($d, $m) => "{$m} {$d}")->implode(' · '));
            }
            $wordings = $timed->countBy(fn ($l) => trim((string) $l->lead_time))->sortDesc();
            foreach ($wordings->take($examples) as $text => $count) {
                $days = ProductFacts::parseLeadTime($text);
                $this->line(sprintf('    %4d × "%s" → %s', $count, mb_strimwidth($text, 0, 90, '…'), $days === null ? 'NOT READ' : "{$days} days"));
            }
            $unread = $wordings->filter(fn ($c, $t) => ProductFacts::parseLeadTime($t) === null)->sum();
            if ($unread > 0) {
                $this->line("    {$unread} lines have wording that isn't read as days yet.");
            }
            if ($this->option('supplier') && $mentions > 0) {
                $d = $details->first();
                $hit = collect(array_merge((array) json_decode((string) $d->details, true), (array) json_decode((string) $d->promodata_attributes, true)))
                    ->first(fn ($x) => preg_match(self::MENTIONS, json_encode($x)));
                $this->line("    e.g. product {$d->product_id} details: ".mb_strimwidth(json_encode($hit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 200, '…'));
            }
        }

        return self::SUCCESS;
    }
}
