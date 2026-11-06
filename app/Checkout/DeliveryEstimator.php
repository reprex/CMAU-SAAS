<?php

namespace App\Checkout;

use Carbon\CarbonImmutable;

/**
 * Estimated delivery date for a website order (Scott, 1 Nov 2026). Business
 * days in the supplier's state, added one stage after another:
 *   order check and invoice + stock from the supplier + decoration (slowest
 *   method in the order) + freight (the slowest transit day of the service).
 */
class DeliveryEstimator
{
    public function __construct(private readonly BusinessDays $days) {}

    /**
     * @param  array{id: string, name: string, days: array{0: int, 1: int}}  $service
     * @return array<string, mixed>
     */
    /**
     * $productionDays: a production time the customer chose (Dex), which covers stock and branding together.
     * $lead (lead times v1, docs/lead-times.md), all optional:
     *  - decoration_days: the branding stage worked out by LeadTimes (Promodata per decoration, + a day per extra position)
     *  - christmas_days: the Christmas rush (not added with $productionDays)
     *  - transit_days / ships_from: overseas to the client (Trends: 4 from NZ)
     *  - closed: the supplier's closed days (Y-m-d => true), skipped by the stock and branding stages
     *  - delivery_state: the holidays transit and freight skip (default: the configured state)
     *
     * @param  array<string, mixed>  $lead
     */
    public function estimate(string $state, string $supplierName, int $stockDays, string $method, array $service, ?CarbonImmutable $start = null, ?int $productionDays = null, array $lead = []): array
    {
        $start = ($start ?? CarbonImmutable::now(config('app.timezone')))->startOfDay();
        $methods = config('website.methods');
        $make = $productionDays ?? (int) ($lead['decoration_days'] ?? config('website.lead_times.decoration.'.$method) ?? max(config('website.lead_times.decoration')));
        $closed = (array) ($lead['closed'] ?? []);
        $delivery = (string) ($lead['delivery_state'] ?? ($state === 'NZ' ? config('website.lead_times.default_state') : $state));
        $stages = [
            ['key' => 'order_check', 'name' => 'Order check and invoice', 'days' => (int) config('website.lead_times.order_check')],
            ['key' => 'stock', 'name' => 'Stock from '.($supplierName ?: 'the supplier'), 'days' => $stockDays, 'closed' => $closed],
            ['key' => 'decoration', 'name' => $productionDays !== null ? 'Production ('.($productionDays ?: 'same day').($productionDays ? ' working '.($productionDays === 1 ? 'day' : 'days') : '').')' : ($methods[$method]['name'] ?? 'Branding').' decoration', 'method' => $method, 'days' => $make, 'closed' => $closed],
        ];
        if ($productionDays === null && (int) ($lead['christmas_days'] ?? 0) > 0) {
            $stages[] = ['key' => 'christmas', 'name' => 'Christmas rush (factories at capacity)', 'days' => (int) $lead['christmas_days'], 'closed' => $closed];
        }
        if ((int) ($lead['transit_days'] ?? 0) > 0) {
            $from = ['NZ' => 'New Zealand'][$lead['ships_from'] ?? ''] ?? 'overseas';
            $stages[] = ['key' => 'transit', 'name' => 'Shipping from '.$from, 'days' => (int) $lead['transit_days'], 'state' => $delivery];
        }
        $stages[] = ['key' => 'freight', 'name' => $service['name'], 'service' => $service['id'], 'days' => (int) max($service['days']), 'state' => $delivery];

        return $this->stages($state, $stages, $start);
    }

    /**
     * Any list of stages, one after another in business days from $start
     * (also used for samples: order check, factory supply, freight).
     *
     * @param  list<array{key: string, name: string, days: int}>  $stages
     * @return array<string, mixed>
     */
    public function stages(string $state, array $stages, ?CarbonImmutable $start = null): array
    {
        $start = ($start ?? CarbonImmutable::now(config('app.timezone')))->startOfDay();
        $d = $start;
        $closures = 0;
        foreach ($stages as $i => $stage) {
            $before = $d;
            $d = $this->days->add($d, (int) $stage['days'], (string) ($stage['state'] ?? $state), (array) ($stage['closed'] ?? []));
            if (! empty($stage['closed'])) {
                $closures += count(array_filter(array_keys($stage['closed']), fn ($k) => $k > $before->toDateString() && $k <= $d->toDateString()));
            }
            unset($stages[$i]['closed'], $stages[$i]['state']);
            $stages[$i]['ends'] = $d->toDateString();
        }

        return [
            'start' => $start->toDateString(),
            'state' => $state,
            'stages' => $stages,
            'business_days' => array_sum(array_column($stages, 'days')),
            'delivery_date' => $d->toDateString(),
            'holidays_skipped' => $this->days->skipped($start, $d, $state),
            'closed_days_skipped' => $closures,
        ];
    }

    /**
     * Does the chosen service meet the need-by date? If not, which faster one does.
     *
     * @param  array<string, array<string, mixed>>  $estimates  service id => estimate(), every service on offer
     * @param  array<string, string>  $names  service id => name
     * @return array<string, mixed>
     */
    public function deadline(array $estimates, string $chosen, CarbonImmutable $needBy, string $state, array $names): array
    {
        $need = $needBy->startOfDay();
        $mine = CarbonImmutable::parse($estimates[$chosen]['delivery_date']);
        $met = $mine->lte($need);

        $faster = null;
        if (! $met) {
            $ok = array_filter($estimates, fn ($e, $id) => $id !== $chosen && CarbonImmutable::parse($e['delivery_date'])->lte($need), ARRAY_FILTER_USE_BOTH);
            uasort($ok, fn ($a, $b) => strcmp($a['delivery_date'], $b['delivery_date']));
            if ($ok !== []) {
                $id = array_key_first($ok);
                $faster = ['id' => $id, 'name' => $names[$id] ?? $id, 'delivery_date' => $ok[$id]['delivery_date']];
            }
        }
        $earliest = $estimates;
        uasort($earliest, fn ($a, $b) => strcmp($a['delivery_date'], $b['delivery_date']));
        $eid = array_key_first($earliest);

        return [
            'need_by' => $need->toDateString(),
            'met' => $met,
            'spare_business_days' => $met ? $this->days->between($mine, $need, $state) : 0,
            'late_business_days' => $met ? 0 : $this->days->between($need, $mine, $state),
            'faster_service' => $faster,
            'earliest' => ['id' => $eid, 'name' => $names[$eid] ?? $eid, 'delivery_date' => $earliest[$eid]['delivery_date']],
        ];
    }
}
