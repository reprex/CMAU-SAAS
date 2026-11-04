<?php

namespace Tests\Feature;

use App\Console\Commands\PricingVerifyPagesCommand;
use App\Models\PriceCheck;
use App\Models\PriceLine;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

/** Price check from the blank prices on the suppliers' own pages (docs/price-check.md). */
class PriceVerifyPagesTest extends TestCase
{
    use QuoteFixtures, RefreshDatabase;

    private function product(int $id, array $bases): Product
    {
        $p = Product::create(['id' => $id, 'supplier_id' => 19, 'name' => 'P', 'code' => 'AP00'.$id, 'tier' => 'live', 'hydrated_at' => now()]);
        foreach ($bases as $i => [$desc, $undec, $cost]) {
            $l = PriceLine::query()->create(['product_id' => $id, 'position' => $i, 'description' => $desc, 'undecorated' => $undec]);
            $l->breaks()->create(['qty' => 50, 'unit_cost' => $cost]);
        }

        return $p;
    }

    public function test_the_page_blank_price_says_whether_the_base_is_blank_or_branded(): void
    {
        $this->supplier(19, 'Dex');
        $cmd = app(PricingVerifyPagesCommand::class);
        $this->assertSame('base_unbranded', $cmd->verdict($this->product(1, [['Local Blank Stock', true, 3.30], ['28 Days CE Blank Stock', true, 2.50]]), 50, 3.30)[0]);
        $this->assertSame('base_branded', $cmd->verdict($this->product(2, [['1 Col Print', false, 4.10]]), 50, 3.30)[0]);
        $this->assertSame('differs', $cmd->verdict($this->product(3, [['Local Blank Stock', true, 3.60]]), 50, 3.30)[0]);

        $this->artisan('pricing:verify-pages', ['--supplier' => 19])->assertSuccessful();
        $this->artisan('pricing:verify-pages', ['--supplier' => 19])->assertSuccessful(); // again: replaced, not added
        $this->assertLessThanOrEqual(3, PriceCheck::query()->count());
    }
}
