<?php

namespace Tests\Feature;

use App\Jobs\RepriceProduct;
use App\Models\DecTypeCode;
use App\Models\PriceLine;
use App\Models\PriceOption;
use App\Models\Product;
use App\Models\SupplierDecType;
use App\Pricing\PriceOptionBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

/** Decoration types per supplier, from its own pages and rules (docs/decoration-types.md). */
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

    private function own(int $supplier): array
    {
        return SupplierDecType::forSupplier($supplier);
    }

    public function test_each_suppliers_types_come_from_its_own_pages_and_never_touch_the_shared_table(): void
    {
        $this->supplier(3, 'Trends');
        $this->supplier(19, 'Dex');
        $this->lines(3, 100, [
            ['Unbranded', 'UNDEC', null], ['Screen Print Per Colour/Position', 'PDADEC', null], ['J Hook Clip', 'PDADEC', null],
            ['Gift Box', 'PDADEC', 10], ['Repeat Screen Setup', 'PDADEC', null], ['Mystery Widget', 'PDADEC', null],
            ['DigiFlex Transfer Per Panel - Umbrellas (250mm x 150mm)', 'PDADEC', null],
        ]);
        $this->lines(19, 200, [['Local Blank Stock', 'UNDEC', null], ['7 Days Print Per Col/Pos', 'PDADEC', null], ['28 Days Print Per Col/Pos', 'PDADEC', 10],
            ['7 Days Data Upload', 'PDADEC', null], ['28 Days Digital Crystal Label (Per Set)', 'PDADEC', null], ['12 Days Digital Crystal Label (Per Set)', 'PDADEC', null]]);
        Queue::fake();

        $this->artisan('catalogue:classify')->expectsOutputToContain('change what pricing uses now')->expectsOutputToContain('Mystery Widget')->assertSuccessful();
        $this->assertSame([], $this->own(3)); // a dry run saves nothing

        $this->artisan('catalogue:classify', ['--apply' => true])->expectsOutputToContain('Trends · 10 → 11 · supplier pages: option')->assertSuccessful();

        $t = $this->own(3);
        $this->assertSame([12, 10, 11, 11, 10], [$t['unbranded'], $t['screenprintpercolourposition'], $t['jhookclip'], $t['giftbox'], $t['digiflextransferperpanelumbrellas250mmx150mm']]);
        $this->assertArrayNotHasKey('repeatscreensetup', $t);
        $d = $this->own(19);
        $this->assertSame([12, 10, 23, 11, 23, 11], [$d['localblankstock'], $d['7daysprintpercolpos'], $d['28daysprintpercolpos'], $d['7daysdataupload'], $d['28daysdigitalcrystallabelperset'], $d['12daysdigitalcrystallabelperset']]);
        $this->assertSame(10, DecTypeCode::find('giftbox')->type_code); // TOD's shared table untouched
        $this->assertNull(DecTypeCode::find('jhookclip')->type_code);
        Queue::assertPushed(RepriceProduct::class, 2);
    }

    public function test_the_same_wording_is_priced_by_each_suppliers_own_type(): void
    {
        $this->supplier(3, 'Trends');
        $this->supplier(88, 'AS Colour');
        DecTypeCode::query()->create(['code' => '1col1pospadprint', 'type_code' => 10, 'full_description' => '1 Col 1 Pos Pad Print']);
        SupplierDecType::query()->create(['supplier_id' => 3, 'code' => '1col1pospadprint', 'type_code' => 23, 'why' => 'test']);
        foreach ([[1, 3], [2, 88]] as [$id, $sid]) {
            $p = Product::create(['id' => $id, 'supplier_id' => $sid, 'name' => 'P', 'code' => 'C'.$id, 'tier' => 'live', 'hydrated_at' => now()]);
            $l = PriceLine::query()->create(['product_id' => $id, 'position' => 0, 'description' => '1 Col 1 Pos Pad Print', 'undecorated' => false]);
            $l->breaks()->create(['qty' => 50, 'unit_cost' => 1]);
        }
        $type = fn ($id) => app(PriceOptionBuilder::class)->build(Product::find($id))->firstWhere('code', '1col1pospadprint')->type_code;
        $this->assertSame([23, 10], [(int) $type(1), (int) $type(2)]); // Trends' own type; AS Colour falls back to TOD's table
    }
}
