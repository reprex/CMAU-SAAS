<?php

namespace App\Pdf;

use App\Models\Quote;
use App\Models\QuoteItem;
use App\Pricing\Money;
use App\Quotes\QuoteCalculator;
use App\Quotes\QuoteNumbers;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;

/**
 * The client PDFs:
 *  - quote (MR...): items with pictures and size breakdown, setups and freight as
 *    their own lines, totals;
 *  - product comparison (PS...): one card per product, its quantity options side
 *    by side as an all-in price each (product, branding, setup and delivery),
 *    no grand total.
 */
class QuotePdf
{
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
        ]);

        $setups = $quote->items
            ->filter(fn (QuoteItem $i) => $i->setupPrice() > 0)
            ->map(fn (QuoteItem $i) => [
                'label' => trim(($i->decoration_name ?: 'Setup').' setup ('.$i->name.')'),
                'total' => $i->setupPrice(),
            ])->values();

        return $this->render('pdf.quote', [
            'quote' => $quote,
            'items' => $items,
            'setups' => $setups,
            'extras' => $quote->extras->whereIn('kind', ['custom', 'service']),
            'freight' => $quote->extras->where('kind', 'freight'),
            'info' => $quote->extras->where('kind', 'info'),
            'separate' => $quote->decoration_display === 'separate',
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
     * quantity options.
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
                    $total = Money::roundToCent($i->total() + $freight);

                    return [
                        'qty' => $i->quantity,
                        'total' => $total,
                        // Each price rounds half up, as the TOD comparison PDF does (230.50 / 100 = 2.31).
                        'each' => round(Money::toUnits($total) / 100 / max(1, $i->quantity)) / 100,
                        'setup' => $i->setupPrice(),
                        'freight' => $freight,
                        'freight_tbc' => $tbc,
                    ];
                });
                $best = $options->count() > 1 ? $options->sortBy('each')->keys()->first() : null;

                return [
                    'item' => $first,
                    'image' => $this->images->dataUri($first->image_url),
                    'features' => $this->features($first->description),
                    'intro' => $this->intro($first->description),
                    'options' => $options->map(fn ($o, $k) => $o + ['best' => $k === $best])->all(),
                ];
            });
    }

    /** @return array{0: float, 1: bool} freight for this option ordered on its own, and whether any of it is TBC */
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

    /** First paragraph of a Promodata description (before "Features:"). */
    private function intro(?string $description): ?string
    {
        if (! $description) {
            return null;
        }
        $text = trim(preg_split('/features\s*:/i', strip_tags($description))[0]);
        $text = preg_replace('/\s+/', ' ', $text);

        return mb_strlen($text) > 320 ? rtrim(mb_substr($text, 0, 317), ' ,.;').'…' : $text;
    }

    /** "* feature" lines after "Features:", at most four. */
    private function features(?string $description): array
    {
        if (! $description || ! preg_match('/features\s*:(.*)$/is', strip_tags($description), $m)) {
            return [];
        }
        $lines = preg_split('/\r?\n|(?=\*\s)/', $m[1]);

        return collect($lines)->map(fn ($l) => trim(ltrim(trim($l), "*•- \t")))->filter()->take(4)->values()->all();
    }

    private function common(): array
    {
        $cfg = config('quotes.pdf');

        return [
            'banner' => $this->images->localDataUri($cfg['banner']),
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
        $options->setDefaultFont('Poppins');
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

        // "Page 1 of 2" bottom right (CSS page counters are not reliable in dompdf).
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('Poppins');
        $canvas->page_text($canvas->get_width() - 100, $canvas->get_height() - 24, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7, [0.55, 0.55, 0.58]);

        return (string) $dompdf->output();
    }
}
