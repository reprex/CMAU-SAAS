<?php

namespace App\Checkout;

use App\Catalogue\ProductFacts;
use App\Models\PriceLine;
use App\Models\PriceOption;
use App\Models\Product;
use App\Models\ProductWebPrice;
use App\Models\Supplier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Lead times v1 (Scott, 5 Nov 2026; docs/lead-times.md):
 *  - per decoration: Promodata's lead time on that decoration's price line, else the method's days;
 *    the slowest decoration sets the time and each further one adds a working day;
 *  - the Christmas rush: +2 working days from the end of October until production restarts in
 *    January (not where the client chose a stated speed, e.g. Dex);
 *  - overseas suppliers: working days in transit to the client (Trends from New Zealand, 4);
 *  - each supplier's closures (entered dates, else the standard Christmas shutdown).
 */
class LeadTimes
{
    /** @var array<string, ?int> option id => Promodata working days */
    private array $cache = [];

    public function __construct(private readonly BusinessDays $days) {}

    /** Working days for one decoration option: Promodata's figure for that line, else the method's. */
    public function optionDays(?string $optionId, ?string $method): int
    {
        if ($optionId !== null && ($d = $this->promodataDays($optionId)) !== null) {
            return $d;
        }
        $table = (array) config('website.lead_times.decoration');

        return (int) ($method !== null && isset($table[$method]) ? $table[$method] : max($table));
    }

    /**
     * The branding stage for a set of positions: the slowest, plus a day for each further position.
     *
     * @param  list<array{decoration_option_id?: ?string, method?: ?string}>  $positions
     * @return array{days: int, slowest: int, extra: int, from_promodata: bool}
     */
    public function decorationDays(array $positions): array
    {
        if ($positions === []) {
            return ['days' => 0, 'slowest' => 0, 'extra' => 0, 'from_promodata' => false];
        }
        $each = [];
        $promodata = false;
        foreach ($positions as $p) {
            $id = $p['decoration_option_id'] ?? null;
            $pd = $id !== null ? $this->promodataDays((string) $id) : null;
            $promodata = $promodata || $pd !== null;
            $each[] = $pd ?? $this->optionDays(null, $p['method'] ?? null);
        }
        $slowest = max($each);
        $extra = (count($each) - 1) * (int) config('website.lead_times.extra_decoration_days', 1);

        return ['days' => $slowest + $extra, 'slowest' => $slowest, 'extra' => $extra, 'from_promodata' => $promodata];
    }

    /** Promodata's lead time for the price line behind a decoration option, in working days. */
    public function promodataDays(string $optionId): ?int
    {
        if (array_key_exists($optionId, $this->cache)) {
            return $this->cache[$optionId];
        }
        $opt = PriceOption::query()->where('option_id', $optionId)->first(['product_id', 'description', 'promodata_decoration']);
        $text = null;
        if ($opt) {
            $line = PriceLine::query()->where('product_id', $opt->product_id)->whereNotNull('base_price_line_id')->whereNotNull('lead_time')
                ->where(fn ($q) => $q->where('description', $opt->description)
                    ->when(filled($opt->promodata_decoration), fn ($q) => $q->orWhere('promodata_decoration', $opt->promodata_decoration)))
                ->value('lead_time');
            $text = $line ? (string) $line : null;
        }

        return $this->cache[$optionId] = $text !== null ? ProductFacts::parseLeadTime($text) : null;
    }

    /** Extra working days for the Christmas rush on an order started $day (0 outside it). */
    public function christmasDays(CarbonImmutable $day): int
    {
        $c = (array) config('website.lead_times.christmas');
        if ((int) ($c['days'] ?? 0) <= 0) {
            return 0;
        }
        // The rush runs from its start date (e.g. 31 Oct) until production restarts in January.
        $year = $day->month >= 7 ? $day->year : $day->year - 1;
        $from = CarbonImmutable::parse($year.'-'.($c['from'] ?? '10-31'))->startOfDay();
        $until = $this->standardReopen($year);

        return $day->gte($from) && $day->lt($until) ? (int) $c['days'] : 0;
    }

    /** The first Monday after 5 January following the Christmas of $year. */
    public function standardReopen(int $year): CarbonImmutable
    {
        $after = CarbonImmutable::parse(($year + 1).'-'.(config('website.lead_times.standard_closure.reopen_after') ?? '01-05'))->startOfDay();

        return $after->next(CarbonImmutable::MONDAY);
    }

    /**
     * Days a supplier is closed between two dates: its entered closures, else (for each Christmas in the
     * window) the standard closure: the last N working days before Christmas Day until the reopening.
     *
     * @return array<string, true> Y-m-d => true
     */
    public function closedDays(?Supplier $supplier, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $closed = [];
        $mark = function (CarbonImmutable $a, CarbonImmutable $b) use (&$closed) {
            for ($d = $a->startOfDay(); $d->lte($b); $d = $d->addDay()) {
                $closed[$d->toDateString()] = true;
            }
        };
        $entered = $supplier ? DB::table('supplier_closures')->where('supplier_id', $supplier->id)
            ->where('closed_to', '>=', $from->toDateString())->where('closed_from', '<=', $to->toDateString())->get() : collect();
        $state = (string) ($supplier?->state ?: config('website.lead_times.default_state'));
        for ($y = $from->year - 1; $y <= $to->year; $y++) {
            $christmas = CarbonImmutable::parse("{$y}-12-25");
            $reopen = $this->standardReopen($y);
            // Entered dates for this Christmas replace the standard closure.
            $own = $entered->filter(fn ($c) => $c->closed_from <= $reopen->toDateString() && $c->closed_to >= $christmas->subDays(20)->toDateString());
            if ($own->isNotEmpty()) {
                continue;
            }
            $start = $christmas;
            $n = (int) config('website.lead_times.standard_closure.days_before_christmas', 2);
            while ($n > 0) {
                $start = $start->subDay();
                if ($this->days->isBusinessDay($start, $state)) {
                    $n--;
                }
            }
            if ($reopen->gte($from) && $start->lte($to)) {
                $mark($start, $reopen->subDay());
            }
        }
        foreach ($entered as $c) {
            $mark(CarbonImmutable::parse($c->closed_from), CarbonImmutable::parse($c->closed_to));
        }

        return $closed;
    }

    /**
     * The product page's lead-time data (additive metafield custom.leadtimes, docs/lead-times.md): the theme
     * adds the stages for the branding chosen and counts business days. Stable for the year (no "today"),
     * so it doesn't make every product publish again each day.
     *
     * @return array<string, mixed>|null
     */
    public function forShopify(Product $product): ?array
    {
        $supplier = $product->supplier;
        $decProductId = (int) (ProductWebPrice::query()->find($product->id)?->decoration_product_id ?: $product->id);
        $options = app(BrandingPricer::class)->options($decProductId);
        $decorations = [];
        foreach ($options as $id => $o) {
            $label = (string) ($o->display_description ?: $o->description);
            $decorations[(string) $id] = $this->optionDays((string) $id, DecorationMethods::classify($label));
        }
        if ($decorations === []) {
            return null;
        }
        $state = (string) ($supplier?->state ?: config('website.lead_times.default_state'));
        $delivery = $state === 'NZ' ? (string) config('website.lead_times.default_state') : $state;
        $year = (int) now()->year;
        $from = CarbonImmutable::parse(($year).'-01-01');
        $to = CarbonImmutable::parse(($year + 1).'-12-31');
        $holidays = fn (string $st) => array_values(array_filter(array_keys($this->days->holidays($st)), fn ($d) => $d >= $from->toDateString() && $d <= $to->toDateString()));
        // Closed days as ranges [first, last].
        $ranges = [];
        $prev = null;
        foreach (array_keys($this->closedDays($supplier, $from, $to)) as $d) {
            if ($prev !== null && CarbonImmutable::parse($prev)->addDay()->toDateString() === $d) {
                $ranges[count($ranges) - 1][1] = $d;
            } else {
                $ranges[] = [$d, $d];
            }
            $prev = $d;
        }
        $c = (array) config('website.lead_times.christmas');
        $rush = [];
        foreach ([$year - 1, $year] as $y) {
            if ((int) ($c['days'] ?? 0) > 0) {
                $rush[] = ['from' => $y.'-'.($c['from'] ?? '10-31'), 'until' => $this->standardReopen($y)->toDateString(), 'days' => (int) $c['days']];
            }
        }

        return [
            'order_check' => (int) config('website.lead_times.order_check'),
            'stock' => $supplier?->stockLeadDays() ?? (int) config('website.lead_times.stock_default'),
            'decorations' => $decorations,
            'extra_per_decoration' => (int) config('website.lead_times.extra_decoration_days', 1),
            'christmas' => $rush,
            'transit' => (int) ($supplier?->transit_days ?? 0),
            'ships_from' => $supplier?->ships_from,
            'freight' => (int) config('website.lead_times.typical_freight_days', 3),
            'state' => $state,
            'holidays' => $holidays($state),
            'delivery_holidays' => $delivery === $state ? [] : $holidays($delivery),
            'closed' => $ranges,
        ];
    }

    /**
     * The earliest delivery for a product (search by date): order check, stock, its fastest branding
     * (Promodata's fastest lead time, else the fastest method), the Christmas rush, transit, typical freight.
     */
    public function earliest(Product $product, ?CarbonImmutable $today = null): string
    {
        $today = ($today ?? CarbonImmutable::now(config('app.timezone')))->startOfDay();
        $supplier = $product->supplier;
        $state = (string) ($supplier?->state ?: config('website.lead_times.default_state'));
        $fastest = $product->lead_time_days ? (int) ceil((float) $product->lead_time_days) : (int) min((array) config('website.lead_times.decoration'));
        $days = (int) config('website.lead_times.typical_freight_days', 3);
        $e = app(DeliveryEstimator::class)->estimate($state, '', $supplier?->stockLeadDays() ?? (int) config('website.lead_times.stock_default'), 'dtf',
            ['id' => 'typical', 'name' => 'Freight', 'days' => [$days, $days]], $today, null, [
                'decoration_days' => $fastest,
                'christmas_days' => $this->christmasDays($today),
                'transit_days' => (int) ($supplier?->transit_days ?? 0),
                'ships_from' => $supplier?->ships_from,
                'closed' => $this->closedDays($supplier, $today, $today->addDays(120)),
            ]);

        return $e['delivery_date'];
    }
}
