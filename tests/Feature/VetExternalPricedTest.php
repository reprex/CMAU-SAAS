<?php

namespace Tests\Feature;

use App\Catalogue\VetQueue;
use App\Models\DecTypeCode;
use App\Models\PriceOption;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

/** Size and colour price lines on a product priced externally (AS Colour) need no decoration type (Scott, 7 Nov 2026). */
class VetExternalPricedTest extends TestCase
{
    use QuoteFixtures, RefreshDatabase;

    public function test_wording_on_externally_priced_products_is_not_listed(): void
    {
        $this->supplier(88, 'AS Colour');
        $tee = $this->catalogueProduct(43446, 88, 'Staple Tee', '5001');
        $mug = $this->catalogueProduct(43447, 88, 'Mug', 'M1');
        Product::query()->whereKey([$tee->id, $mug->id])->update(['tier' => Product::TIER_LIVE, 'discontinued' => false]);
        DecTypeCode::query()->create(['code' => 'black4xl5xl', 'type_code' => null, 'full_description' => 'BLACK 4XL-5XL']);
        DecTypeCode::query()->create(['code' => 'oddline', 'type_code' => null, 'full_description' => 'Odd line']);
        PriceOption::query()->create(['product_id' => $tee->id, 'option_id' => 't-1', 'code' => 'black4xl5xl', 'kind' => PriceOption::KIND_UNDECORATED, 'description' => 'BLACK 4XL-5XL']);
        PriceOption::query()->create(['product_id' => $tee->id, 'option_id' => 't-ext', 'code' => 'mmdtf', 'kind' => PriceOption::KIND_EXTERNAL, 'description' => 'DTF print']);
        PriceOption::query()->create(['product_id' => $mug->id, 'option_id' => 'm-1', 'code' => 'oddline', 'kind' => PriceOption::KIND_DECORATED, 'description' => 'Odd line']);

        $codes = array_column(app(VetQueue::class)->sections()['types']['left'], 'code');

        $this->assertNotContains('black4xl5xl', $codes);
        $this->assertContains('oddline', $codes);
    }
}
