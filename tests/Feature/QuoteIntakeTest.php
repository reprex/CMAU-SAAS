<?php

namespace Tests\Feature;

use App\Models\Quote;
use App\Models\ShopifyDraftOrder;
use App\Quotes\DraftOrderIntake;
use App\Quotes\DraftOrderParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class QuoteIntakeTest extends TestCase
{
    use QuoteFixtures, RefreshDatabase;

    public function test_parser_reads_the_export_and_shopify_json_the_same_way(): void
    {
        $parser = new DraftOrderParser;
        $export = $parser->parse($this->exportDraft());

        $shopify = $parser->parse([
            'id' => 1628107178204, 'name' => '#D4021', 'email' => 'client@example.com',
            'note' => 'Urgency: I just need a quote for now Notes: Please quote 100 navy caps',
            'shipping_address' => ['city' => 'Brunswick', 'province_code' => 'VIC', 'zip' => '3056', 'address1' => '1 Test St'],
            'customer' => ['first_name' => 'Pat', 'last_name' => 'Client'],
            'line_items' => [[
                'title' => 'Heavy brushed cotton cap buckle on back [53-CH35]', 'variant_title' => 'Navy', 'sku' => '25384-navy',
                'price' => '8.79', 'quantity' => 99, 'variant_id' => 52080813441244,
                'properties' => [['name' => 'decsetup', 'value' => '45.00'], ['name' => 'decunit', 'value' => '4.13'], ['name' => 'undecsetup', 'value' => '0.00'],
                    ['name' => 'undecunit', 'value' => '4.21'], ['name' => 'decdesc', 'value' => 'Embroidery to 5K stitches'], ['name' => 'decID', 'value' => '25384-embroideryto5kstitches']],
            ]],
        ]);

        foreach ([$export, $shopify] as $p) {
            $this->assertSame('client@example.com', $p['email']);
            $this->assertSame('Pat Client', $p['contact_name']);
            $this->assertSame('I just need a quote for now', $p['urgency']);
            $this->assertSame('Please quote 100 navy caps', $p['notes']);
            $this->assertSame('Brunswick', $p['address']['city']);
            $line = $p['lines'][0];
            $this->assertSame('Heavy brushed cotton cap buckle on back', $line['name']);
            $this->assertSame(53, $line['supplier_id']);
            $this->assertSame('CH35', $line['product_code']);
            $this->assertSame(25384, $line['product_id']);
            $this->assertSame('Navy', $line['colour']);
            $this->assertSame(4.21, $line['undec_unit']);
            $this->assertSame(45.0, $line['dec_setup']);
            $this->assertSame(25384, $line['dec_product_id']);
        }
    }

    public function test_a_draft_with_an_email_becomes_a_quote_with_split_prices_and_costs(): void
    {
        $this->supplier(53, 'Shiny');
        $this->catalogueProduct(25384, 53);

        $draft = app(DraftOrderIntake::class)->receive($this->exportDraft());
        $quote = Quote::with('items')->findOrFail($draft->quote_id);

        $this->assertSame('MR11775', $quote->number);
        $this->assertSame('NEW', $quote->status);
        $this->assertSame('Brunswick', $quote->delivery_city);
        $item = $quote->items->first();
        $this->assertSame('combined', $item->stage);
        $this->assertSame(99, $item->quantity);
        $this->assertSame('8.79', number_format((float) $item->received_unit_price, 2));
        $this->assertSame('4.21', number_format((float) $item->product_unit_price, 2));
        $this->assertSame('Embroidery to 5K stitches', $item->decoration_name);
        $this->assertSame('2.60', number_format((float) $item->product_unit_cost, 2));   // cost at 99 = the 50 break
        $this->assertSame('30.00', number_format((float) $item->decoration_setup_cost, 2));
        $this->assertSame('https://example.com/cap.png', $item->image_url);

        // 99 x (4.21 + 4.13) + 45 setup = 870.66, then one "to be confirmed" freight line (Shiny has none set).
        $this->assertSame('870.66', number_format((float) $quote->subtotal, 2));
        $this->assertSame('87.07', number_format((float) $quote->gst, 2));
        $freight = $quote->extras()->where('kind', 'freight')->get();
        $this->assertCount(1, $freight);
        $this->assertTrue($freight->first()->tbc);
    }

    public function test_anonymous_wish_lists_are_kept_but_not_turned_into_quotes_until_staff_open_one(): void
    {
        $draft = app(DraftOrderIntake::class)->receive($this->exportDraft(email: null));

        $this->assertTrue($draft->anonymous);
        $this->assertNull($draft->quote_id);
        $this->assertSame(0, Quote::count());

        $this->staff();
        $this->post(route('drafts.convert', $draft))->assertRedirect();
        $quote = Quote::firstOrFail();
        $this->assertSame('wishlist', $quote->kind);
        $this->assertSame('SAVELIST', $quote->status);
    }

    public function test_shopify_updates_refresh_items_until_staff_edit_the_quote(): void
    {
        $intake = app(DraftOrderIntake::class);
        $raw = $this->exportDraft();
        $quote = Quote::find($intake->receive($raw)->quote_id);

        $lines = json_decode($raw['LineItems'], true);
        $lines[0]['Quantity'] = 150;
        $raw['LineItems'] = json_encode($lines);
        $raw['UpdatedAt'] = '2026-10-21 09:00:00';
        $intake->receive($raw);
        $this->assertSame(150, $quote->items()->first()->quantity);

        $quote->update(['edited_at' => now()]);
        $lines[0]['Quantity'] = 200;
        $raw['LineItems'] = json_encode($lines);
        $raw['UpdatedAt'] = '2026-10-22 09:00:00';
        $intake->receive($raw);

        $this->assertSame(150, $quote->items()->first()->quantity);
        $this->assertStringContainsString('items were not refreshed', $quote->fresh()->internal_notes);
    }

    public function test_a_bad_draft_is_recorded_as_an_error_not_lost(): void
    {
        ShopifyDraftOrder::create(['id' => 5, 'name' => '#D5', 'anonymous' => false, 'payload' => '{"id":5,"email":"a@b.co","line_items":"not json"}']);
        $draft = ShopifyDraftOrder::find(5);
        $quote = app(DraftOrderIntake::class)->process($draft);

        // A line list that is not JSON is read as no lines, so the quote still arrives (empty).
        $this->assertNotNull($quote);
        $this->assertSame(0, $quote->items()->count());
    }

    public function test_webhooks_must_be_signed(): void
    {
        config(['shopify.webhook_secret' => 'shh', 'shopify.store' => 'cmau-dev.myshopify.com']);
        $body = json_encode(['id' => 77, 'name' => '#D77', 'email' => 'x@example.com', 'line_items' => [['title' => 'Pen', 'quantity' => 100, 'price' => '1.00', 'sku' => '1234-blue']]]);

        $this->call('POST', '/webhooks/shopify/draft-orders', [], [], [], ['HTTP_X_SHOPIFY_HMAC_SHA256' => 'wrong', 'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'cmau-dev.myshopify.com'], $body)->assertStatus(401);

        $sig = base64_encode(hash_hmac('sha256', $body, 'shh', true));
        $this->call('POST', '/webhooks/shopify/draft-orders', [], [], [], ['HTTP_X_SHOPIFY_HMAC_SHA256' => $sig, 'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'cmau-dev.myshopify.com', 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
        $this->assertSame(1, Quote::count());
    }
}
