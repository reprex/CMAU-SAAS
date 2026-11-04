<?php

namespace Tests\Feature;

use App\Models\BrandingTemplate;
use App\Models\DecTypeCode;
use App\Models\PriceOption;
use App\Models\Product;
use App\Models\RangeSuggestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

/** One Vet screen for every exception that still needs a person (docs/vet.md). */
class VetScreenTest extends TestCase
{
    use QuoteFixtures, RefreshDatabase;

    public function test_every_exception_is_on_the_one_screen_and_settled_ones_drop_off(): void
    {
        $this->supplier(3, 'Trends');
        Product::create(['id' => 1, 'supplier_id' => 3, 'name' => 'Vulcan Pen', 'code' => '105947', 'tier' => 'live']);
        Product::create(['id' => 2, 'supplier_id' => 3, 'name' => 'New Bottle', 'code' => '120000', 'tier' => 'active']);
        RangeSuggestion::query()->create(['product_id' => 2, 'supplier_id' => 3, 'kind' => 'new', 'status' => 'pending', 'ready' => true]);
        PriceOption::create(['product_id' => 1, 'option_id' => '1-mysterywidget', 'code' => 'mysterywidget', 'kind' => 'PDADEC', 'description' => 'Mystery Widget', 'price_string' => '1:1', 'position' => 0]);
        DecTypeCode::query()->create(['code' => 'mysterywidget', 'type_code' => null, 'full_description' => 'Mystery Widget', 'first_supplier_id' => 3]);
        DecTypeCode::query()->create(['code' => 'giftbox', 'type_code' => 10, 'full_description' => 'Gift Box']);
        Cache::forever('vet:classify', ['at' => now()->toIso8601String(), 'mixed' => [], 'disagree' => [
            ['code' => 'giftbox', 'supplier_id' => 3, 'description' => 'Gift Box', 'current' => 10, 'derived' => 11, 'why' => 'supplier pages: packaging option', 'products' => 4, 'on_range' => 4],
        ]]);
        BrandingTemplate::query()->create(['product_id' => 1, 'url' => 'file:3/x.pdf', 'filename' => '105947 Vulcan Pen.pdf', 'status' => 'failed', 'message' => 'Claude: 529 overloaded']);
        $this->staff('admin');

        $this->get(route('vet.index'))->assertOk()
            ->assertSeeInOrder(['New products from suppliers', 'New Bottle', 'Decoration types', 'Gift Box', 'Trends says 11', 'Mystery Widget', 'Branding templates that could not be read', 'Claude: 529 overloaded', 'Read again', 'Price check', 'For information', 'Mockups'])
            ->assertSee('Vet <span class="pill warn small">4</span>', false); // new product, two types, one template

        DecTypeCode::query()->whereKey('giftbox')->update(['type_code' => 11]); // settled under Decoration types
        Cache::forget('vet:count');
        $this->get(route('vet.index'))->assertOk()->assertDontSee('Trends says 11');
    }
}
