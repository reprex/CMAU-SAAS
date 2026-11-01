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
    public function estimate(string $state, string $supplierName, int $stockDays, string $method, array $service, ?CarbonImmutable $start = null): array
    {
        $start = ($start ?? CarbonImmutable::now(config('app.timezone')))->startOfDay();
        $methods = config('website.methods');
        $make = (int) (config('website.lead_times.decoration.'.$method) ?? max(config('website.lead_times.decoration')));
        $stages = [
            ['key' => 'order_check', 'name' => 'Order check and invoice', 'days' => (int) config('website.lead_times.order_check')],
            ['key' => 'stock', 'name' => 'Stock from '.($supplierName ?: 'the supplier'), 'days' => $stockDays],
            ['key' => 'decoration', 'name' => ($methods[$method]['name'] ?? 'Branding').' decoration', 'method' => $method, 'days' => $make],
            ['key' => 'freight', 'name' => $service['name'], 'service' => $service['id'], 'days' => (int) max($service['days'])],
        ];

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
        foreach ($stages as $i => $stage) {
            $d = $this->days->add($d, (int) $stage['days'], $state);
            $stages[$i]['ends'] = $d->toDateString();
        }

        return [
            'start' => $start->toDateString(),
            'state' => $state,
            'stages' => $stages,
            'business_days' => array_sum(array_column($stages, 'days')),
            'delivery_date' => $d->toDateString(),
            'holidays_skipped' => $this->days->skipped($start, $d, $state),
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
