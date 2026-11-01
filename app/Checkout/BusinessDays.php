<?php

namespace App\Checkout;

use App\Models\PublicHoliday;
use Carbon\CarbonImmutable;

/**
 * Business days in one Australian state: weekdays that are not that state's
 * public holidays (public_holidays table). The supplier's state is used for
 * the delivery estimate (docs/website-checkout.md).
 */
class BusinessDays
{
    /** @var array<string, array<string, string>> state => [Y-m-d => name] */
    private array $holidays = [];

    /** @return array<string, string> Y-m-d => holiday name */
    public function holidays(string $state): array
    {
        $state = mb_strtoupper($state);

        return $this->holidays[$state] ??= PublicHoliday::query()->where('state', $state)->orderBy('date')->get(['date', 'name'])
            ->mapWithKeys(fn (PublicHoliday $h) => [$h->date->toDateString() => $h->name])->all();
    }

    public function isBusinessDay(CarbonImmutable $day, string $state): bool
    {
        return ! $day->isWeekend() && ! isset($this->holidays($state)[$day->toDateString()]);
    }

    /** The date $days business days after $from (from itself does not count). */
    public function add(CarbonImmutable $from, int $days, string $state): CarbonImmutable
    {
        $d = $from->startOfDay();
        while ($days > 0) {
            $d = $d->addDay();
            if ($this->isBusinessDay($d, $state)) {
                $days--;
            }
        }

        return $d;
    }

    /** Business days after $from up to and including $to (0 when $to is not later). */
    public function between(CarbonImmutable $from, CarbonImmutable $to, string $state): int
    {
        $n = 0;
        $d = $from->startOfDay();
        $end = $to->startOfDay();
        while ($d->lt($end)) {
            $d = $d->addDay();
            if ($this->isBusinessDay($d, $state)) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Weekday public holidays after $from up to and including $to: the ones the estimate skipped.
     *
     * @return list<array{date: string, name: string}>
     */
    public function skipped(CarbonImmutable $from, CarbonImmutable $to, string $state): array
    {
        $out = [];
        foreach ($this->holidays($state) as $date => $name) {
            $d = CarbonImmutable::parse($date);
            if ($d->gt($from->startOfDay()) && $d->lte($to->startOfDay()) && ! $d->isWeekend()) {
                $out[] = ['date' => $date, 'name' => $name];
            }
        }

        return $out;
    }
}
