<?php

namespace Tests\Feature;

use App\Jobs\SendInstantQuote;
use App\Models\Quote;
use App\Models\User;
use App\Pdf\QuotePdf;
use App\Quotes\DraftOrderIntake;
use App\Shopify\ShopifyAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class QuoteDeskHttpTest extends TestCase
{
    use QuoteFixtures, RefreshDatabase;

    public function test_sign_in_needs_password_then_two_factor(): void
    {
        $user = User::factory()->create(['email' => 'sam@example.com', 'role' => 'sales']);

        $this->get('/quotes')->assertRedirect('/login');
        $this->post('/login', ['email' => 'sam@example.com', 'password' => 'password'])->assertRedirect(route('quotes.index'));
        $this->get('/quotes')->assertRedirect(route('two-factor.setup'));

        $this->get(route('two-factor.setup'))->assertOk()->assertSee('authenticator');
        $secret = session('two_factor_pending');
        $code = app(Google2FA::class)->getCurrentOtp($secret);
        $this->post(route('two-factor.setup'), ['code' => $code])->assertRedirect(route('quotes.index'));

        $this->assertTrue($user->fresh()->hasTwoFactor());
        $this->get('/quotes')->assertOk()->assertSee('Quotes');
    }

    public function test_first_admin_setup_only_with_the_token_and_only_once(): void
    {
        config(['app.setup_token' => 'tok-123']);
        $this->get('/setup')->assertOk();
        $this->post('/setup', ['setup_token' => 'wrong', 'name' => 'S', 'email' => 's@example.com', 'password' => 'a-long-password', 'password_confirmation' => 'a-long-password'])->assertForbidden();
        $this->post('/setup', ['setup_token' => 'tok-123', 'name' => 'Scott', 'email' => 's@example.com', 'password' => 'a-long-password', 'password_confirmation' => 'a-long-password'])->assertRedirect(route('two-factor.setup'));
        $this->assertSame('admin', User::first()->role);
        $this->get('/setup')->assertNotFound();
    }

    public function test_supplier_freight_is_admin_only_and_keeps_history(): void
    {
        $trends = $this->supplier(3, 'Trends');

        $this->staff('sales');
        $this->get(route('admin.suppliers.index'))->assertForbidden();
        $this->put(route('admin.suppliers.update', $trends), ['freight_sell' => 22.5, 'freight_basis' => 'per_delivery'])->assertForbidden();

        $admin = $this->staff('admin');
        $this->get(route('admin.suppliers.index', ['show' => 'all']))->assertOk()->assertSee('Trends');
        $this->put(route('admin.suppliers.update', $trends), ['freight_sell' => '22.50', 'freight_cost' => '14.00', 'freight_basis' => 'per_delivery', 'freight_decorator_leg' => '1'])
            ->assertRedirect(route('admin.suppliers.edit', $trends));

        $trends->refresh();
        $this->assertSame('22.50', $trends->freight_sell);
        $this->assertTrue($trends->freight_decorator_leg);
        $this->assertSame($admin->id, $trends->freightRates()->first()->changed_by);
        $this->get(route('admin.suppliers.edit', $trends))->assertOk()->assertSee('History');
    }

    public function test_read_only_users_can_look_but_not_change(): void
    {
        $quote = Quote::create(['number' => 'MR9']);
        $this->staff('readonly');
        $this->get(route('quotes.show', $quote))->assertOk()->assertDontSee('Set status');
        $this->post(route('quotes.status', $quote), ['status' => 'LOST'])->assertForbidden();
    }

    public function test_the_quote_editor_and_both_pdfs_render(): void
    {
        $this->supplier(53, 'Shiny', ['freight_sell' => 22.50]);
        $this->catalogueProduct(25384, 53);
        $quote = Quote::find(app(DraftOrderIntake::class)->receive($this->exportDraft())->quote_id);
        $item = $quote->items()->first();
        $item->update(['stage' => 'separated', 'description' => "Classic cap.\nFeatures:\n* Buckle back\n* Brushed cotton"]);
        $item->replicate()->fill(['quantity' => 250])->save();

        $this->staff('sales');
        $this->get(route('quotes.show', $quote))->assertOk()
            ->assertSee('Heavy brushed cotton cap buckle on back')
            ->assertSee('Size × qty split', false)
            ->assertSee('Delivery to Brunswick VIC');
        $this->get(route('quotes.index'))->assertOk()->assertSee($quote->number);

        $pdf = $this->get(route('quotes.pdf', $quote));
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $cmp = $this->get(route('quotes.comparison', $quote));
        $cmp->assertOk();
        $this->assertStringStartsWith('%PDF', $cmp->getContent());
    }

    public function test_comparison_options_are_all_in_prices_each(): void
    {
        $this->supplier(3, 'Trends', ['freight_sell' => 22.50]);
        $quote = Quote::create(['number' => 'MR11774']);
        foreach ([[50, 1.03], [100, 1.03]] as [$qty, $price]) {
            $quote->items()->create(['supplier_id' => 3, 'product_id' => 121632, 'name' => 'Bergen Stubby Cooler', 'quantity' => $qty,
                'product_unit_price' => $price, 'decoration_unit_price' => 0.45, 'decoration_setup_price' => 60, 'decoration_name' => 'Screen print', 'decoration_option_id' => '121632-sp']);
        }

        $cards = app(QuotePdf::class)->comparisonCards($quote->load('items'));
        $this->assertCount(1, $cards);
        $opts = $cards[0]['options'];
        // TOD PS11774: 50 -> $156.50, 100 -> $230.50
        $this->assertSame([156.5, 230.5], [$opts[0]['total'], $opts[1]['total']]);
        $this->assertSame([3.13, 2.31], [$opts[0]['each'], $opts[1]['each']]);
        $this->assertTrue($opts[1]['best']);
        $this->assertTrue($cards[0]['all_in']);
        $this->assertSame([172.15, 253.55], [$opts[0]['total_inc'], $opts[1]['total_inc']]);
        $this->assertSame([3.44, 2.54], [$opts[0]['each_inc'], $opts[1]['each_inc']]);
    }

    public function test_comparison_is_all_in_only_when_delivery_is_set_and_fits_two_products_a_page(): void
    {
        $this->supplier(3, 'Trends', ['freight_sell' => 22.50]);
        $this->supplier(9, 'No freight yet');
        $quote = Quote::create(['number' => 'MR11800', 'client_company' => 'Harbourside RC']);
        foreach ([[1, 3], [2, 9], [3, 3], [4, 9]] as [$product, $supplier]) {
            foreach ([50, 100, 250, 500] as $qty) {
                $quote->items()->create(['supplier_id' => $supplier, 'product_id' => $product, 'name' => "Product {$product}", 'quantity' => $qty,
                    'product_unit_price' => 1.00, 'decoration_unit_price' => 0.50, 'decoration_setup_price' => 60, 'decoration_name' => 'Print', 'decoration_option_id' => "{$product}-p",
                    'description' => 'A sturdy everyday product. Features: * One * Two']);
            }
        }

        $cards = app(QuotePdf::class)->comparisonCards($quote->load('items'));
        $this->assertTrue($cards[0]['all_in']);
        $this->assertFalse($cards[1]['all_in']);
        $this->assertCount(3, $cards[0]['options']);                 // three breaks shown
        $this->assertSame(500, $cards[0]['more'][0]['qty']);          // the rest listed underneath
        $this->assertSame(0.0, $cards[1]['options'][0]['freight']);
        $this->assertTrue($cards[1]['options'][0]['freight_tbc']);

        $pdf = app(QuotePdf::class)->comparison($quote);
        $this->assertSame(2, preg_match_all('#/Type\s*/Page[^s]#', $pdf));  // four products, two pages
    }

    public function test_stock_relay_checks_origin_and_id_and_caches(): void
    {
        config(['promodata.token' => 'test-token', 'quotes.stock_relay.allowed_origins' => ['https://crowdmerch.com.au']]);
        Http::fake(['*' => Http::response(['data' => ['stock' => [['Black', 'M', 3090, '5001-BLACK-M']]]])]);

        $this->get('/stock.php?id=abc')->assertStatus(400);
        $this->get('/stock.php?id=43446', ['Origin' => 'https://evil.example'])->assertForbidden();

        $first = $this->get('/stock.php?id=43446', ['Origin' => 'https://crowdmerch.com.au']);
        $first->assertOk()->assertHeader('Access-Control-Allow-Origin', 'https://crowdmerch.com.au')->assertHeader('X-Stock-Cache', 'miss');
        $this->assertSame(3090, $first->json('data.stock.0.2'));
        $this->get('/stock.php?id=43446')->assertHeader('X-Stock-Cache', 'hit');
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->hasHeader('X-Auth-Token', 'test-token') && str_contains($r->url(), '/products/43446/check-stock-levels'));
    }

    public function test_shopify_writes_are_blocked_unless_the_store_is_the_dev_store(): void
    {
        config(['shopify.store' => 'crowdmerch.myshopify.com', 'shopify.dev_store' => 'cmau-dev.myshopify.com', 'shopify.token' => 'x', 'shopify.allow_live' => false]);
        try {
            app(ShopifyAdmin::class)->assertWritable();
            $this->fail('Live store writes should be blocked.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('blocked', $e->getMessage());
        }

        config(['shopify.store' => 'cmau-dev.myshopify.com']);
        app(ShopifyAdmin::class)->assertWritable();
        $this->assertTrue(true);
    }

    public function test_website_wish_list_lives_in_the_saas_not_shopify(): void
    {
        config(['shopify.store' => 'cmau-dev.myshopify.com', 'shopify.dev_store' => 'cmau-dev.myshopify.com', 'shopify.token' => 'x',
            'shopify.website_api.allowed_origins' => ['https://cmau-dev.myshopify.com']]);
        Http::fake(['cmau-dev.myshopify.com/admin/api/*/variants/555.json' => Http::response(['variant' => ['id' => 555, 'sku' => '25384-navy', 'title' => 'Navy', 'option1' => 'Navy']])]);
        $this->supplier(53, 'Shiny');
        $this->catalogueProduct(25384, 53);

        $this->postJson('/crowdmerch/homepage/addToWishlist', ['variantId' => 555, 'quantity' => 50], ['Origin' => 'https://bad.example'])->assertForbidden();

        $add = $this->postJson('/crowdmerch/homepage/addToWishlist', ['variantId' => 555, 'quantity' => 50, 'decID' => '25384-embroideryto5kstitches'], ['Origin' => 'https://cmau-dev.myshopify.com']);
        $add->assertOk()->assertJson(['success' => true, 'count' => 1]);
        $token = $add->json('ActiveWL');

        $items = $this->postJson('/crowdmerch/homepage/getDraftOrderItems', ['ActiveWL' => $token], ['Origin' => 'https://cmau-dev.myshopify.com']);
        // 45/50 + 4.13 + 4.21 = 9.24
        $items->assertOk()->assertJsonPath('items.0.price', '9.24')->assertJsonPath('items.0.decName', 'Embroidery to 5K stitches');

        $wishlist = Quote::where('public_token', $token)->first();
        $this->assertSame('wishlist', $wishlist->kind);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'draft_orders') || str_contains($r->url(), 'graphql'));
    }

    public function test_quick_quote_creates_the_draft_on_the_dev_store_and_queues_the_email(): void
    {
        Queue::fake();
        config(['shopify.store' => 'cmau-dev.myshopify.com', 'shopify.dev_store' => 'cmau-dev.myshopify.com', 'shopify.token' => 'x',
            'shopify.website_api.allowed_origins' => ['https://cmau-dev.myshopify.com']]);
        $this->supplier(53, 'Shiny');
        $this->catalogueProduct(25384, 53);
        Http::fake([
            '*/variants/555.json' => Http::response(['variant' => ['id' => 555, 'sku' => '25384-navy', 'title' => 'Navy']]),
            '*/graphql.json' => Http::response(['data' => ['draftOrderCreate' => ['draftOrder' => ['id' => 'gid://shopify/DraftOrder/42', 'legacyResourceId' => '42', 'name' => '#D9001'], 'userErrors' => []]]]),
            '*/draft_orders/42.json' => Http::response(['draft_order' => [
                'id' => 42, 'name' => '#D9001', 'email' => 'new@example.com', 'note' => 'Urgency: Soon Notes: hi', 'updated_at' => '2026-10-25T10:00:00+11:00',
                'line_items' => [['title' => 'Heavy brushed cotton cap [53-CH35]', 'variant_title' => 'Navy', 'sku' => '25384-navy', 'quantity' => 100, 'price' => '8.15', 'variant_id' => 555,
                    'properties' => [['name' => 'undecunit', 'value' => '3.90'], ['name' => 'undecsetup', 'value' => '0.00'], ['name' => 'decunit', 'value' => '3.80'], ['name' => 'decsetup', 'value' => '45.00'],
                        ['name' => 'decdesc', 'value' => 'Embroidery to 5K stitches'], ['name' => 'decID', 'value' => '25384-embroideryto5kstitches']]]],
            ]]),
        ]);

        $r = $this->postJson('/crowdmerch/homepage/createQuoteDraftOrder', ['email' => 'new@example.com', 'variantId' => 555, 'quantity' => 100,
            'decID' => '25384-embroideryto5kstitches', 'urgency' => 'Soon', 'comments' => 'hi'], ['Origin' => 'https://cmau-dev.myshopify.com']);

        $r->assertOk()->assertJson(['success' => true, 'draftOrderId' => 42]);
        Http::assertSent(function ($req) {
            if (! str_contains($req->url(), 'graphql.json')) {
                return false;
            }
            $line = $req['variables']['input']['lineItems'][0];

            return $line['priceOverride']['amount'] === '8.15' && $line['quantity'] === 100; // 45/100 + 3.80 + 3.90
        });
        $quote = Quote::where('shopify_draft_order_id', 42)->first();
        $this->assertSame('website', $quote->source);
        Queue::assertPushed(SendInstantQuote::class);
    }
}
