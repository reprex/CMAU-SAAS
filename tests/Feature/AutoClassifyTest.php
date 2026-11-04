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
}
