<?php

namespace Tests\Feature;

use App\Checkout\DeliveryEstimator;
use App\Checkout\LeadTimes;
use App\Models\PriceLine;
use App\Models\PriceOption;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class LeadTimesTest extends TestCase
{
    use QuoteFixtures, RefreshDatabase;

    private const ROAD = ['id' => 'tnt_road', 'name' => 'TNT Road Express', 'days' => [1, 2]];

    public function test_promodata_days_per_decoration_then_a_day_per_extra_position(): void
    {
        $this->supplier(3, 'Trends');
        $p = $this->catalogueProduct(2568, 3, 'Borg Pen', '110811');
        $base = PriceLine::query()->create(['product_id' => $p->id, 'description' => 'Unbranded']);
        PriceLine::query()->create(['product_id' => $p->id, 'base_price_line_id' => $base->id, 'description' => 'Pad Print 1 colour', 'lead_time' => '10-12 working days']);
        PriceOption::query()->create(['product_id' => $p->id, 'option_id' => '2568-pad', 'code' => 'pad', 'kind' => 'PDADEC', 'description' => 'Pad Print 1 colour']);
        $lt = app(LeadTimes::class);

        $this->assertSame(12, $lt->optionDays('2568-pad', 'pad'));
        $this->assertSame(7, $lt->optionDays('2568-nope', 'pad'), 'no Promodata line: the method');
        $two = $lt->decorationDays([['decoration_option_id' => '2568-pad', 'method' => 'pad'], ['decoration_option_id' => null, 'method' => 'screen']]);
        $this->assertSame(['days' => 13, 'slowest' => 12, 'extra' => 1, 'from_promodata' => true], $two);
    }

    public function test_christmas_rush_runs_from_end_of_october_to_the_january_restart(): void
    {
        $lt = app(LeadTimes::class);

        $this->assertSame(0, $lt->christmasDays(CarbonImmutable::parse('2026-10-30')));
        $this->assertSame(2, $lt->christmasDays(CarbonImmutable::parse('2026-10-31')));
        $this->assertSame(2, $lt->christmasDays(CarbonImmutable::parse('2027-01-08')));
        $this->assertSame('2027-01-11', $lt->standardReopen(2026)->toDateString()); // 5 Jan 2027 is a Tuesday
        $this->assertSame(0, $lt->christmasDays(CarbonImmutable::parse('2027-01-11')));
    }

    public function test_standard_shutdown_unless_the_supplier_has_its_own_dates(): void
    {
        $s = $this->supplier(88, 'AS Colour');
        $s->update(['state' => 'VIC']);
        $lt = app(LeadTimes::class);
        $closed = $lt->closedDays($s, CarbonImmutable::parse('2026-12-01'), CarbonImmutable::parse('2027-02-01'));

        $this->assertArrayNotHasKey('2026-12-22', $closed);
        $this->assertArrayHasKey('2026-12-23', $closed); // last 2 working days before Christmas: Wed 23, Thu 24
        $this->assertArrayHasKey('2027-01-10', $closed);
        $this->assertArrayNotHasKey('2027-01-11', $closed); // reopens Monday 11 January

        DB::table('supplier_closures')->insert(['supplier_id' => 88, 'closed_from' => '2026-12-18', 'closed_to' => '2027-01-04', 'note' => 'Christmas shutdown', 'created_at' => now(), 'updated_at' => now()]);
        $own = app(LeadTimes::class)->closedDays($s, CarbonImmutable::parse('2026-12-01'), CarbonImmutable::parse('2027-02-01'));
        $this->assertArrayHasKey('2026-12-18', $own);
        $this->assertArrayNotHasKey('2027-01-05', $own);
    }

    public function test_estimate_adds_christmas_rush_nz_transit_and_skips_the_shutdown(): void
    {
        $lt = app(LeadTimes::class);
        $start = CarbonImmutable::parse('2026-12-14'); // a Monday in the rush
        $e = app(DeliveryEstimator::class)->estimate('NZ', '', 2, 'pad', self::ROAD, $start, null, [
            'decoration_days' => 7,
            'christmas_days' => $lt->christmasDays($start),
            'transit_days' => 4, 'ships_from' => 'NZ',
            'closed' => $lt->closedDays(null, $start, $start->addDays(90)),
        ]);

        $this->assertSame(['order_check', 'stock', 'decoration', 'christmas', 'transit', 'freight'], array_column($e['stages'], 'key'));
        $this->assertSame('Shipping from New Zealand', $e['stages'][4]['name']);
        $this->assertGreaterThan(0, $e['closed_days_skipped']);
        $this->assertGreaterThanOrEqual('2027-01-11', $e['stages'][3]['ends'], 'production resumes after the shutdown');

        // A chosen production speed (Dex) gets no Christmas stage.
        $dex = app(DeliveryEstimator::class)->estimate('VIC', '', 0, 'pad', self::ROAD, $start, 3, ['christmas_days' => 2]);
        $this->assertNotContains('christmas', array_column($dex['stages'], 'key'));
    }
}
