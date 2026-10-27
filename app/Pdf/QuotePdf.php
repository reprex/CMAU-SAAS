<?php

namespace App\Pdf;

use App\Models\Quote;
use App\Models\QuoteExtra;
use App\Models\QuoteItem;
use App\Pricing\Money;
use App\Quotes\QuoteCalculator;
use App\Quotes\QuoteNumbers;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;

/**
 * The client PDFs, CrowdMerch branded (layouts agreed 25 Oct 2026, docs/quotes.md):
 *  - quote (MR...): items with pictures; setups, services and delivery under
 *    "Also included" right-aligned above the totals;
 *  - product comparison (PS...): two products per page, up to three quantity
 *    breaks each, every break with its own setup and delivery, ex and inc GST.
 *    "All in" only when the product has a delivery line.
 */
class QuotePdf
{
    public const MAX_BREAKS = 3;

    public function __construct(
        private readonly ImageEmbedder $images,
        private readonly QuoteCalculator $calculator,
    ) {}

    public function quote(Quote $quote): string
    {
        $quote->load(['items.skus', 'extras']);

        $items = $quote->items->map(fn (QuoteItem $i) => [
            'item' => $i,
            'image' => $this->images->dataUri($i->image_url),
            'sizes' => $i->sizeBreakdown(),
        ])->values();

        // Custom products read as items; services, setups and delivery as "also included".
        $customs = $quote->extras->where('kind', 'custom')->map(fn (QuoteExtra $x) => [
            'extra' => $x,
            'image' => $this->images->dataUri($x->image_url),
        ])->values();

        $included = collect();
        foreach ($quote->items as $i) {
            if ($i->setupPrice() > 0) {
                $included->push(['label' => ($i->decoration_name ? 'Setup: '.$i->decoration_name : 'Setup').' ('.$i->name.')', 'amount' => $i->setupPrice(), 'tbc' => false]);
            }
        }
        foreach ($quote->extras->where('kind', 'service') as $x) {
            $included->push(['label' => $x->description.((float) $x->quantity != 1.0 ? ' × '.rtrim(rtrim(number_format((float) $x->quantity, 2), '0'), '.') : ''), 'amount' => $x->total(), 'tbc' => false]);
        }
        foreach ($quote->extras->where('kind', 'freight') as $x) {
            $included->push(['label' => $x->description, 'amount' => $x->total(), 'tbc' => (bool) $x->tbc]);
        }

        return $this->render('pdf.quote', [
            'quote' => $quote,
            'items' => $items,
            'customs' => $customs,
            'included' => $included,
            'info' => $quote->extras->where('kind', 'info')->values(),
            'separate' => $quote->decoration_display === 'separate',
            'timing' => $this->timing($quote->lead_time),
            'anyTbc' => $included->contains('tbc', true),
        ] + $this->common());
    }

    public function comparison(Quote $quote): string
    {
        $quote->load(['items.skus', 'extras']);

        return $this->render('pdf.comparison', [
            'quote' => $quote,
            'number' => QuoteNumbers::comparisonNumber($quote->number),
            'cards' => $this->comparisonCards($quote),
        ] + $this->common());
    }

    /**
     * Items grouped by product + colour + decoration; each group's items are the
     * quantity breaks, each priced as an order on its own.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function comparisonCards(Quote $quote): Collection
    {
        return $quote->items
            ->groupBy(fn (QuoteItem $i) => implode('|', [$i->product_id ?? $i->name, mb_strtolower((string) $i->colour), $i->decoration_option_id ?? $i->decoration_name]))
            ->values()
            ->map(function (Collection $group) {
                /** @var QuoteItem $first */
                $first = $group->first();
                $options = $group->sortBy('quantity')->values()->map(function (QuoteItem $i) {
                    [$freight, $tbc] = $this->standaloneFreight($i);
                    $totalCents = $this->cents($i->total() + $freight);
                    $qty = max(1, $i->quantity);
                    $eachCents = intdiv(2 * $totalCents + $qty, 2 * $qty); // half up, as TOD's PDFs (230.50 / 100 = 2.31)

                    return [
                        'qty' => $i->quantity,
                        'unit' => $i->unitPrice(),
                        'goods' => $i->goodsTotal(),
                        'setup' => $i->setupPrice(),
                        'freight' => $freight,
                        'freight_tbc' => $tbc,
                        'total' => $totalCents / 100,
                        'total_inc' => $this->incGst($totalCents) / 100,
                        'each' => $eachCents / 100,
                        'each_inc' => $this->incGst($eachCents) / 100,
                    ];
                });

                $shown = $options->take(self::MAX_BREAKS)->values();
                $best = $shown->count() > 1 ? $shown->sortBy('each')->keys()->first() : null;

                return [
                    'item' => $first,
                    'image' => $this->images->dataUri($first->image_url),
                    'intro' => $this->intro($first->description),
                    'all_in' => $shown->every(fn ($o) => ! $o['freight_tbc']),
                    'options' => $shown->map(fn ($o, $k) => $o + ['best' => $k === $best])->all(),
                    'more' => $options->slice(self::MAX_BREAKS)->values()->all(),
                ];
            });
    }

    /** @return array{0: float, 1: bool} freight for this break ordered on its own, and whether any of it is still to be confirmed */
    private function standaloneFreight(QuoteItem $item): array
    {
        $temp = new Quote;
        $temp->setRelation('items', collect([$item]));
        $temp->setRelation('extras', collect());

        $sum = 0.0;
        $tbc = false;
        foreach ($this->calculator->freightLegs($temp) as $leg) {
            $charge = $this->calculator->legCharge($leg);
            $charge === null ? $tbc = true : $sum += $charge;
        }

        return [Money::roundToCent($sum), $tbc];
    }

    /** The quote's timing line, always inviting an earlier deadline. */
    private function timing(?string $leadTime): ?string
    {
        $leadTime = trim((string) ($leadTime ?: config('quotes.default_lead_time')));
        if ($leadTime === '') {
            return null;
        }
        if (stripos($leadTime, 'urgent') !== false) {
            return $leadTime;
        }

        return rtrim($leadTime, '. ').', but please let us know if you have a more urgent deadline.';
    }

    private function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    /** GST-inclusive cents, rounded half up. */
    private function incGst(int $cents): int
    {
        return intdiv($cents * 11 + 5, 10);
    }

    /** First paragraph of a Promodata description (before "Features:"). */
    private function intro(?string $description): ?string
    {
        if (! $description) {
            return null;
        }
        $text = trim(preg_split('/features\s*:/i', strip_tags($description))[0]);
        $text = preg_replace('/\s+/', ' ', $text);

        return mb_strlen($text) > 220 ? rtrim(mb_substr($text, 0, 217), ' ,.;').'…' : $text;
    }

    private function common(): array
    {
        $cfg = config('quotes.pdf');

        return [
            'logo' => $this->images->localDataUri($cfg['logo']),
            'signature' => $this->images->localDataUri($cfg['signature']),
            'brand' => $cfg,
            'fontDir' => resource_path('fonts'),
        ];
    }

    private function render(string $view, array $data): string
    {
        $options = new Options;
        $options->setChroot([base_path()]);
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('Mulish');
        $options->setDpi(96);
        $fontCache = storage_path('fonts');
        if (! is_dir($fontCache)) {
            mkdir($fontCache, 0750, true);
        }
        $options->setFontDir($fontCache);
        $options->setFontCache($fontCache);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view($view, $data)->render(), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        // Page numbers bottom centre, under the footer line (CSS page counters are not reliable in dompdf).
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('Mulish');
        $canvas->page_text($canvas->get_width() / 2 - 20, $canvas->get_height() - 16, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 6.5, [0.45, 0.45, 0.42]);

        return (string) $dompdf->output();
    }
}
