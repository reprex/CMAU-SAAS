<?php

namespace Tests\Feature;

use App\Curation\CurationApplier;
use App\Jobs\ApplyCurationRequest;
use App\Models\CurationRequest;
use App\Models\PositionRule;
use App\Models\Product;
use App\Models\WebsiteStaffPass;
use App\Shopify\ProductPublisher;
use App\Staff\StaffPasses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class WebsiteStaffToolsTest extends TestCase
{
    use QuoteFixtures, RefreshDatabase;

    private const ORIGIN = 'https://cmau-dev.myshopify.com';

    private const CDN = 'https://res.cloudinary.com/promodata/as-colour/';

    protected function setUp(): void
    {
        parent::setUp();
        config(['website.orders.allowed_origins' => [self::ORIGIN], 'app.admin_allowed_ips' => []]);
        $this->supplier(88, 'AS Colour');
    }

    private function tee(): Product
    {
        $p = $this->catalogueProduct(43446, 88, 'Staple Tee', '5001');
        $p->forceFill(['tier' => Product::TIER_LIVE, 'hero_image_url' => self::CDN.'main.jpg', 'promodata_type_name' => 'T-Shirts'])->save();
        foreach (['main.jpg', 'white.jpg', 'back.jpg'] as $i => $f) {
            $p->images()->create(['url' => self::CDN.$f, 'role' => $i ? 'other_style' : 'hero_style', 'position' => $i]);
        }
        foreach (['Black', 'White', 'Bone'] as $c) {
            $p->colours()->create(['name' => $c, 'image_url' => self::CDN.strtolower($c).'.jpg', 'position' => 0]);
        }

        return $p;
    }

    private function passFor(string $role): string
    {
        $user = $this->staff($role);

        return app(StaffPasses::class)->issue($user, self::ORIGIN)['token'];
    }

    private function api(string $token): static
    {
        return $this->withHeaders(['Origin' => self::ORIGIN, 'Authorization' => 'Bearer '.$token]);
    }

    public function test_connect_page_hands_a_pass_to_the_website_only(): void
    {
        $this->get('/website-staff/connect?origin='.urlencode(self::ORIGIN))->assertRedirect(); // signed out
        $this->staff('sales');
        $res = $this->get('/website-staff/connect?origin='.urlencode(self::ORIGIN))->assertOk()->assertHeader('Cache-Control');
        $res->assertSee('cm-staff-pass')->assertSee('postMessage');
        // The page's inline script is allowed by its nonce (the site's CSP blocks inline scripts otherwise).
        preg_match("/script-src 'self' 'nonce-([a-f0-9]+)'/", (string) $res->headers->get('Content-Security-Policy'), $m);
        $this->assertNotEmpty($m);
        $res->assertSee('<script nonce="'.$m[1].'">', false);
        $this->assertSame(1, WebsiteStaffPass::count());
        $this->get('/website-staff/connect?origin='.urlencode('https://evil.example'))->assertForbidden();
        $this->staff('readonly');
        $this->get('/website-staff/connect?origin='.urlencode(self::ORIGIN))->assertForbidden();
    }

    public function test_me_signout_and_expired_passes(): void
    {
        $token = $this->passFor('admin');
        $this->api($token)->getJson('/crowdmerch/staff/me')->assertOk()->assertJson(['success' => true, 'role' => 'admin', 'can_apply' => true]);
        $this->api('nope-'.str_repeat('x', 44))->getJson('/crowdmerch/staff/me')->assertStatus(401)->assertJson(['signed_out' => true]);
        $this->api($token)->postJson('/crowdmerch/staff/signout')->assertOk();
        $this->api($token)->getJson('/crowdmerch/staff/me')->assertStatus(401);
        $t2 = $this->passFor('sales');
        WebsiteStaffPass::query()->update(['expires_at' => now()->subMinute()]);
        $this->api($t2)->getJson('/crowdmerch/staff/me')->assertStatus(401);
        $this->withHeaders(['Origin' => 'https://evil.example', 'Authorization' => 'Bearer '.$t2])->getJson('/crowdmerch/staff/me')->assertForbidden();
    }

    public function test_curation_state(): void
    {
        $p = $this->tee();
        $token = $this->passFor('sales');
        $this->api($token)->getJson("/crowdmerch/staff/products/{$p->id}/curation")->assertOk()
            ->assertJson(['success' => true, 'can_apply' => false, 'on_website' => true, 'name' => 'Staple Tee'])
            ->assertJsonCount(3, 'colours')->assertJsonPath('images.0.role', 'hero_style');
        $this->api($token)->getJson('/crowdmerch/staff/products/999999/curation')->assertNotFound();
    }

    public function test_admin_changes_apply_and_republish_straight_away(): void
    {
        Bus::fake([ApplyCurationRequest::class]);
        $p = $this->tee();
        $token = $this->passFor('admin');
        $this->api($token)->postJson("/crowdmerch/staff/products/{$p->id}/curation", ['changes' => [
            'hero_image_url' => self::CDN.'white.jpg',
            'hidden_colours' => ['Bone'],
            'collection_moq' => 10,
            'title' => 'Staple Tee',
            'positions' => [['name' => 'Left chest', 'view' => 'front', 'width_mm' => 90, 'height_mm' => 90], ['name' => 'Back', 'view' => 'back', 'width_mm' => 280, 'height_mm' => 350]],
        ], 'note' => 'White photo is better'])->assertOk()->assertJson(['status' => 'approved']);
        $r = CurationRequest::first();
        Bus::assertDispatched(ApplyCurationRequest::class, fn ($j) => $j->requestId === $r->id);

        $publisher = Mockery::mock(ProductPublisher::class);
        $publisher->shouldReceive('publish')->once()->andReturn(['action' => 'update', 'ok' => true, 'message' => null]);
        $this->app->instance(ProductPublisher::class, $publisher);
        app(CurationApplier::class)->apply($r->fresh());

        $r->refresh();
        $this->assertSame('applied', $r->status);
        $p->refresh();
        $this->assertSame(self::CDN.'white.jpg', $p->overrideValue('hero_image_url'));
        $this->assertSame(['Bone'], $p->overrideValue('hidden_colours'));
        $this->assertSame(10, $p->overrideValue('collection_moq'));
        $this->assertSame('Left chest', PositionRule::where('product_id', $p->id)->first()->positions[0]['name']);
        $this->assertContains('Hidden colours: Bone', $r->summary());
    }

    public function test_sales_requests_wait_for_an_admin(): void
    {
        Bus::fake([ApplyCurationRequest::class]);
        $p = $this->tee();
        $token = $this->passFor('sales');
        $this->api($token)->postJson("/crowdmerch/staff/products/{$p->id}/curation", ['changes' => ['on_website' => false]])->assertOk()->assertJson(['status' => 'pending']);
        Bus::assertNotDispatched(ApplyCurationRequest::class);
        $this->api($token)->postJson("/crowdmerch/staff/products/{$p->id}/curation", ['changes' => ['hero_image_url' => 'https://elsewhere.example/x.jpg']])->assertStatus(422);
        $this->api($token)->postJson("/crowdmerch/staff/products/{$p->id}/curation", ['changes' => []])->assertStatus(422);
        $this->api($token)->postJson("/crowdmerch/staff/products/{$p->id}/curation", ['changes' => [], 'note' => 'Check the sizes'])->assertOk()->assertJson(['status' => 'pending']);

        $this->get(route('curation-requests.index'))->assertOk()->assertSee('On the website: take it off')->assertSee('Check the sizes');
        $r = CurationRequest::first();
        $this->post(route('curation-requests.decide', $r), ['decision' => 'approve'])->assertForbidden(); // sales can't approve
        $this->staff('admin');
        $this->post(route('curation-requests.decide', $r), ['decision' => 'approve'])->assertRedirect();
        $this->assertSame('approved', $r->fresh()->status);
        Bus::assertDispatched(ApplyCurationRequest::class);
        $note = CurationRequest::latest('id')->first();
        $this->post(route('curation-requests.decide', $note), ['decision' => 'done'])->assertRedirect();
        $this->assertSame('applied', $note->fresh()->status);
    }

    public function test_admin_outside_the_office_needs_approval(): void
    {
        Bus::fake([ApplyCurationRequest::class]);
        config(['app.admin_allowed_ips' => ['202.169.99.148']]);
        $p = $this->tee();
        $token = $this->passFor('admin');
        $this->api($token)->postJson("/crowdmerch/staff/products/{$p->id}/curation", ['changes' => ['collection_moq' => 25]])->assertOk()->assertJson(['status' => 'pending']);
    }
}
