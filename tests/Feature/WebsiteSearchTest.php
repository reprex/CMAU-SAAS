<?php

namespace Tests\Feature;

use App\Models\SearchLog;
use App\Search\BriefParser;
use App\Search\PageToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class WebsiteSearchTest extends TestCase
{
    use QuoteFixtures, RefreshDatabase;

    private const ORIGIN = 'https://crowdmerch.com.au';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.admin_allowed_ips' => [],
            'search.enabled' => true,
            'search.allowed_origins' => [self::ORIGIN],
            'search.turnstile.secret' => null,
            'search.turnstile.hostnames' => [],
            'search.anthropic.key' => 'test-key',
        ]);
        $this->supplier(3, 'Trends');
        $this->catalogueProduct(101, 3, 'Heavy brushed cotton cap', 'CH35')->update(['promodata_type_name' => 'Caps', 'material' => 'Cotton']);
        $this->catalogueProduct(102, 3, 'Trucker mesh cap', 'TC10')->update(['promodata_type_name' => 'Caps']);
        $this->catalogueProduct(103, 3, 'Jute tote bag', 'JT1')->update(['promodata_type_name' => 'Tote Bags', 'material' => 'Jute']);
        $this->catalogueProduct(104, 3, 'Cotton tote bag', 'CT2')->update(['promodata_type_name' => 'Tote Bags', 'material' => 'Cotton']);
    }

    private function token(int $secondsAgo = 10): string
    {
        return app(PageToken::class)->issue(time() - $secondsAgo);
    }

    private function search(array $body, array $headers = [])
    {
        return $this->withHeaders(['Origin' => self::ORIGIN] + $headers)->postJson('/crowdmerch/search', $body + ['token' => $this->token()]);
    }

    private function withTurnstile(bool $pass = true): void
    {
        config(['search.turnstile.secret' => 'test-secret']);
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => $pass, 'hostname' => 'crowdmerch.com.au']),
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => json_encode([
                    'understood' => ['qty' => 100, 'budget_each' => null, 'eco' => true, 'products' => ['tote bags']],
                    'picks' => [['id' => 103, 'reason' => 'Natural jute suits an eco brief.'], ['id' => 999, 'reason' => 'Made up'], ['id' => 104, 'reason' => '<b>Soft</b> cotton']],
                ])]],
                'usage' => ['input_tokens' => 900, 'output_tokens' => 120],
            ]),
        ]);
    }

    public function test_page_token_is_signed_and_timed(): void
    {
        $tokens = app(PageToken::class);
        $this->assertNull($tokens->check($tokens->issue(time() - 10)));
        $this->assertSame('too_fast', $tokens->check($tokens->issue(time())));
        $this->assertSame('page_token_expired', $tokens->check($tokens->issue(time() - 3 * 3600)));
        $this->assertSame('no_page_token', $tokens->check(null));
        [$ts, $nonce] = explode('.', $tokens->issue(time() - 10));
        $this->assertSame('bad_page_token', $tokens->check("{$ts}.{$nonce}.forged"));
    }

    public function test_token_endpoint_needs_an_allowed_origin(): void
    {
        $this->getJson('/crowdmerch/search/token')->assertForbidden();
        $this->withHeaders(['Origin' => self::ORIGIN])->getJson('/crowdmerch/search/token')
            ->assertOk()->assertHeader('Access-Control-Allow-Origin', self::ORIGIN)->assertJsonStructure(['token']);
    }

    public function test_bots_are_stopped_before_any_search(): void
    {
        $this->withHeaders(['Origin' => 'https://scraper.example'])->postJson('/crowdmerch/search', ['q' => 'caps'])->assertForbidden();

        $this->search(['q' => 'caps', 'website' => 'http://spam'])->assertOk()->assertJsonPath('results', []);
        $this->withHeaders(['Origin' => self::ORIGIN])->postJson('/crowdmerch/search', ['q' => 'caps'])->assertForbidden();
        $this->withHeaders(['Origin' => self::ORIGIN])->postJson('/crowdmerch/search', ['q' => 'caps', 'token' => $this->token(0)])->assertForbidden();
        $this->search(['q' => 'buy cheap https://spam.example now'])->assertStatus(422);
        $this->search(['q' => 'aaaaaaaaaaaa'])->assertStatus(422);

        $this->assertSame(['honeypot', 'no_page_token', 'too_fast', 'link_or_markup', 'repeated_characters'],
            SearchLog::query()->orderBy('id')->pluck('reason')->all());
        $this->assertSame(5, SearchLog::query()->where('mode', 'blocked')->count());
    }

    public function test_searches_are_rate_limited_per_visitor(): void
    {
        config(['search.limits.searches_per_minute' => 2]);
        $this->search(['q' => 'caps'])->assertOk();
        $this->search(['q' => 'caps'])->assertOk();
        $this->search(['q' => 'caps'])->assertStatus(429);
        $this->assertSame('rate_limit', SearchLog::query()->latest('id')->value('reason'));
    }

    public function test_plain_keyword_search_answers_without_claude(): void
    {
        Http::fake();
        $res = $this->search(['q' => '100 caps'])->assertOk()->assertJsonPath('mode', 'keyword')->assertJsonPath('understood.qty', 100);
        $this->assertEqualsCanonicalizing([101, 102], array_column($res->json('results'), 'id'));
        $this->assertNotNull($res->json('results.0.price'));
        Http::assertNothingSent();
    }

    public function test_brief_without_turnstile_falls_back_to_keyword(): void
    {
        Http::fake();
        $this->search(['q' => 'eco tote bags for our conference'])->assertOk()->assertJsonPath('mode', 'keyword');
        $this->assertSame('turnstile_not_set_up', SearchLog::query()->value('reason'));
        Http::assertNothingSent();
    }

    public function test_brief_from_a_human_uses_claude_with_saas_prices_then_caches(): void
    {
        $this->withTurnstile();
        $res = $this->search(['q' => 'eco tote bags for 100 conference guests', 'cf_turnstile' => 'ok'])->assertOk()->assertJsonPath('mode', 'ai');
        $this->assertSame([103, 104], array_column($res->json('results'), 'id'));        // made-up id 999 dropped
        $this->assertSame('Soft cotton', $res->json('results.1.reason'));                // markup stripped
        $this->assertNotNull($res->json('results.0.price'));                              // price from SaaS pricing
        $this->assertSame(900, (int) SearchLog::query()->where('mode', 'ai')->value('tokens_in'));

        $this->search(['q' => 'Eco tote bags for 100 conference guests ', 'cf_turnstile' => 'ok'])->assertOk()->assertJsonPath('mode', 'ai');
        $this->assertSame('cached', SearchLog::query()->latest('id')->value('mode'));
        Http::assertSentCount(3); // two Turnstile checks, one Claude call
    }

    public function test_failed_human_check_gets_keyword_results(): void
    {
        $this->withTurnstile(false);
        $this->search(['q' => 'gift ideas for our staff', 'cf_turnstile' => 'bad'])->assertOk()->assertJsonPath('mode', 'keyword');
        $this->assertSame('turnstile_failed', SearchLog::query()->value('reason'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'anthropic'));
    }

    public function test_verified_agents_get_keyword_results_only(): void
    {
        $this->withTurnstile();
        $this->search(['q' => 'eco tote bags for our conference', 'cf_turnstile' => 'ok'], ['X-Verified-Bot' => '1'])->assertOk()->assertJsonPath('mode', 'keyword');
        $log = SearchLog::query()->first();
        $this->assertTrue($log->agent);
        $this->assertSame('agent_keyword_only', $log->reason);
        Http::assertNothingSent();
    }

    public function test_hourly_and_daily_ai_limits(): void
    {
        $this->withTurnstile();
        config(['search.limits.ai_per_hour' => 1]);
        $this->search(['q' => 'eco tote bags for our conference', 'cf_turnstile' => 'ok'])->assertJsonPath('mode', 'ai');
        $this->search(['q' => 'something for our christmas party', 'cf_turnstile' => 'ok'])->assertJsonPath('mode', 'keyword');
        $this->assertSame('ai_hourly_limit', SearchLog::query()->latest('id')->value('reason'));

        config(['search.limits.ai_per_hour' => 50, 'search.limits.ai_per_day' => 1]);
        $this->search(['q' => 'welcome gifts for new staff', 'cf_turnstile' => 'ok'])->assertJsonPath('mode', 'keyword');
        $this->assertSame('ai_daily_budget', SearchLog::query()->latest('id')->value('reason'));
    }

    public function test_claude_failure_falls_back_to_keyword(): void
    {
        config(['search.turnstile.secret' => 'test-secret']);
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => true]),
            'api.anthropic.com/*' => Http::response(['error' => 'overloaded'], 529),
        ]);
        $this->search(['q' => 'eco tote bags for our conference', 'cf_turnstile' => 'ok'])->assertOk()->assertJsonPath('mode', 'keyword');
        $this->assertSame('ai_error', SearchLog::query()->value('reason'));
    }

    public function test_brief_parser_reads_quantity_budget_and_negatives(): void
    {
        $b = app(BriefParser::class)->parse('About 200 navy caps under $8 each, not trucker');
        $this->assertSame(200, $b['qty']);
        $this->assertEquals(8.0, $b['budget']);
        $this->assertTrue($b['under']);
        $this->assertContains('navy', $b['colours']);
        $this->assertTrue($b['briefLike']);
    }

    public function test_quote_api_can_require_turnstile(): void
    {
        config(['shopify.website_api.enabled' => true, 'shopify.website_api.allowed_origins' => [self::ORIGIN], 'search.turnstile.protect_website_api' => true]);
        $this->withTurnstile(false);
        $this->withHeaders(['Origin' => self::ORIGIN])->postJson('/crowdmerch/homepage/addToWishlist', ['variantId' => 1, 'quantity' => 50, 'cf_turnstile' => 'bad'])
            ->assertForbidden()->assertJsonPath('reason', 'turnstile_failed');
    }

    public function test_admin_search_log_screen(): void
    {
        SearchLog::query()->create(['visitor' => 'abc', 'query' => 'navy caps', 'mode' => 'keyword', 'reason' => 'keyword_enough', 'agent' => false, 'checks' => [], 'result_ids' => [101], 'created_at' => now()]);
        SearchLog::query()->create(['visitor' => 'def', 'query' => 'spam', 'mode' => 'blocked', 'reason' => 'honeypot', 'agent' => false, 'checks' => [], 'result_ids' => [], 'created_at' => now()]);
        $this->actingAs($this->staff('admin'))->get('/admin/search-log')->assertOk()
            ->assertSee('navy caps')->assertSee('Filled the hidden field (bot)')->assertSee('Keyword search was enough');
        $this->actingAs($this->staff('sales'))->get('/admin/search-log')->assertForbidden();
    }
}
