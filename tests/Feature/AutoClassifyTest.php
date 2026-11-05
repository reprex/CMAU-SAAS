<?php

namespace Tests\Feature;

use App\Jobs\RepriceProduct;
use App\Models\DecTypeCode;
use App\Models\PriceOption;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

/** Decoration types set from the supplier rules and pages (docs/decoration-types.md). */
class AutoClassifyTest extends TestCase
{
    use QuoteFixtures, RefreshDatabase;

    private function lines(int $supplier, int $id, array $lines): void
    {
        Product::create(['id' => $id, 'supplier_id' => $supplier, 'name' => 'P'.$id, 'code' => 'C'.$id, 'tier' => 'live']);
        foreach ($lines as $i => [$desc, $kind, $set]) {
            $code = strtolower(preg_replace('/[^a-z0-9]/i', '', $desc));
            PriceOption::create(['product_id' => $id, 'option_id' => "{$id}-{$code}", 'code' => $code, 'kind' => $kind, 'type_code' => $set,
                'description' => $desc, 'price_string' => '50:1', 'cost_string' => '50:1', 'position' => $i]);
            DecTypeCode::query()->firstOrCreate(['code' => $code], ['type_code' => $set, 'full_description' => $desc]);
        }
    }

    public function test_types_come_from_the_supplier_pages_and_staff_types_are_never_changed(): void
    {
        $this->supplier(3, 'Trends');
        $this->supplier(19, 'Dex');
        $this->lines(3, 100, [
            ['Unbranded', 'UNDEC', null], ['Screen Print Per Colour/Position', 'PDADEC', null], ['J Hook Clip', 'PDADEC', null],
            ['Gift Box', 'PDADEC', 10], ['Repeat Screen Setup', 'PDADEC', null], ['Mystery Widget', 'PDADEC', null], ['DigiFlex Transfer Per Panel - Umbrellas (250mm x 150mm)', 'PDADEC', null],
        ]);
        $this->lines(19, 200, [['Local Blank Stock', 'UNDEC', null], ['7 Days Print Per Col/Pos', 'PDADEC', null], ['28 Days Print Per Col/Pos', 'PDADEC', null], ['7 Days Data Upload', 'PDADEC', null]]);
        Queue::fake();

        $this->artisan('catalogue:classify')->expectsOutputToContain('to fill in')->expectsOutputToContain('Mystery Widget')->assertSuccessful();
        $this->assertNull(DecTypeCode::find('jhookclip')->type_code); // a dry run saves nothing

        $this->artisan('catalogue:classify', ['--apply' => true])
            ->expectsOutputToContain('Gift Box: set 10 (decoration), supplier says 11 (option)')
            ->expectsOutputToContain('Repeat Screen Setup')->assertSuccessful();

        $t = DecTypeCode::query()->pluck('type_code', 'code');
        $this->assertSame([12, 10, 11, 10, null, null, 10], [$t['unbranded'], $t['screenprintpercolourposition'], $t['jhookclip'], $t['giftbox'], $t['repeatscreensetup'], $t['mysterywidget'], $t['digiflextransferperpanelumbrellas250mmx150mm']]);
        $this->assertSame([12, 10, 23, 11], [$t['localblankstock'], $t['7daysprintpercolpos'], $t['28daysprintpercolpos'], $t['7daysdataupload']]);
        Queue::assertPushed(RepriceProduct::class, 2);
    }

    public function test_one_suppliers_rule_never_sets_wording_other_suppliers_share(): void
    {
        $this->supplier(2, 'Logo Line');
        $this->supplier(88, 'AS Colour');
        $this->lines(2, 300, [['1 Col 1 Pos Pad Print', 'PDADEC', 10], ['1 Col 1 Pos Pad Print on Hip Flask', 'PDADEC', 10]]); // TOD rule: hidden
        Product::create(['id' => 301, 'supplier_id' => 88, 'name' => 'Other', 'code' => 'O', 'tier' => 'live']);
        PriceOption::create(['product_id' => 301, 'option_id' => '301-1col1pospadprint', 'code' => '1col1pospadprint', 'kind' => 'PDADEC', 'type_code' => 10, 'description' => '1 Col 1 Pos Pad Print', 'price_string' => '50:1', 'position' => 0]);
        Queue::fake();

        $this->artisan('catalogue:classify', ['--supplier' => [2], '--apply' => true, '--replace' => true])->expectsOutputToContain('other suppliers use too')->assertSuccessful();
        $t = DecTypeCode::query()->pluck('type_code', 'code');
        $this->assertSame([10, 23], [$t['1col1pospadprint'], $t['1col1pospadprintonhipflask']]); // shared kept; Logo Line's own wording follows its rule
    }

    public function test_dex_add_ons_at_a_time_dex_does_not_offer_are_hidden(): void
    {
        $this->supplier(19, 'Dex');
        $this->lines(19, 400, [['28 Days Digital Crystal Label (Per Set)', 'PDADEC', null], ['12 Days Digital Crystal Label (Per Set)', 'PDADEC', null]]);
        Queue::fake();
        $this->artisan('catalogue:classify', ['--supplier' => [19], '--apply' => true])->assertSuccessful();
        $t = DecTypeCode::query()->pluck('type_code', 'code');
        $this->assertSame([23, 11], [$t['28daysdigitalcrystallabelperset'], $t['12daysdigitalcrystallabelperset']]);
    }
}
