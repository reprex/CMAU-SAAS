<?php

namespace Tests\Feature;

use App\Models\Quote;
use App\Quotes\QuoteCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class LineTransferTest extends TestCase
{
    use QuoteFixtures, RefreshDatabase;

    private Quote $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supplier(3, 'Trends', ['freight_sell' => 22.50]);
        $this->source = Quote::create(['number' => 'MR100', 'client_email' => 'Pat@Example.com', 'client_company' => 'Harbourside RC', 'delivery_city' => 'Brunswick']);
        foreach (['Tee' => 25, 'Cooler' => 100] as $name => $qty) {
            $this->source->items()->create(['supplier_id' => 3, 'name' => $name, 'quantity' => $qty, 'product_unit_price' => 2, 'stage' => 'sku']);
        }
        $this->source->items()->first()->skus()->createMany([['size' => 'M', 'quantity' => 15], ['size' => 'L', 'quantity' => 10]]);
        app(QuoteCalculator::class)->recalculate($this->source);
        $this->staff('sales');
    }

    public function test_move_a_line_to_a_new_quote_for_the_same_client(): void
    {
        $tee = $this->source->items()->where('name', 'Tee')->first();
        $this->post(route('items.transfer', $tee), ['action' => 'move_new'])->assertRedirect(route('quotes.show', $this->source));

        $new = Quote::whereKeyNot($this->source->id)->firstOrFail();
        $this->assertSame('Pat@Example.com', $new->client_email);
        $this->assertSame('Brunswick', $new->delivery_city);
        $this->assertSame(['Tee'], $new->items()->pluck('name')->all());
        $this->assertSame(2, $new->items()->first()->skus()->count());         // sizes go with it
        $this->assertSame(['Cooler'], $this->source->items()->pluck('name')->all());
        // both quotes now carry their own freight
        $this->assertSame(1, $new->extras()->where('kind', 'freight')->count());
        $this->assertSame(1, $this->source->extras()->where('kind', 'freight')->count());
    }

    public function test_copy_a_line_to_a_new_quote_keeps_the_original(): void
    {
        $tee = $this->source->items()->where('name', 'Tee')->first();
        $this->post(route('items.transfer', $tee), ['action' => 'copy_new'])->assertRedirect();

        $new = Quote::whereKeyNot($this->source->id)->firstOrFail();
        $this->assertSame(2, $this->source->items()->count());
        $this->assertSame(1, $new->items()->count());
        $this->assertSame(2, $new->items()->first()->skus()->count());
        $this->assertSame(2, $tee->fresh()->skus()->count());
    }

    public function test_move_to_an_existing_quote_only_for_the_same_email(): void
    {
        $same = Quote::create(['number' => 'MR101', 'client_email' => 'pat@example.com']);
        $other = Quote::create(['number' => 'MR102', 'client_email' => 'someone@else.com']);
        $cooler = $this->source->items()->where('name', 'Cooler')->first();

        $this->get(route('quotes.show', $this->source))->assertOk()->assertSee('MR101')->assertDontSee('MR102');

        $this->post(route('items.transfer', $cooler), ['action' => 'move_existing', 'target_quote_id' => $other->id])->assertSessionHasErrors('line');
        $this->assertSame($this->source->id, $cooler->fresh()->quote_id);

        $this->post(route('items.transfer', $cooler), ['action' => 'move_existing', 'target_quote_id' => $same->id])->assertSessionHasNoErrors();
        $this->assertSame($same->id, $cooler->fresh()->quote_id);
        $this->assertNotNull($same->fresh()->edited_at);
    }

    public function test_extra_lines_move_but_automatic_freight_stays(): void
    {
        $service = $this->source->extras()->create(['kind' => 'service', 'description' => 'Artwork setup', 'unit_price' => 45]);
        $freight = $this->source->extras()->where('kind', 'freight')->first();

        $this->post(route('extras.transfer', $freight), ['action' => 'move_new'])->assertSessionHasErrors('line');
        $this->post(route('extras.transfer', $service), ['action' => 'move_new'])->assertSessionHasNoErrors();

        $new = Quote::whereKeyNot($this->source->id)->firstOrFail();
        $this->assertSame($new->id, $service->fresh()->quote_id);
    }

    public function test_read_only_users_cannot_move_lines(): void
    {
        $this->staff('readonly');
        $this->post(route('items.transfer', $this->source->items()->first()), ['action' => 'move_new'])->assertForbidden();
    }
}
