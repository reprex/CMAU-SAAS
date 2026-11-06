<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

/** Replicat, CrowdMerch and CrowdLink views with access per division; shared modules (docs/divisions.md). */
class DivisionsTest extends TestCase
{
    use QuoteFixtures, RefreshDatabase;

    private function as(array $divisions): User
    {
        $u = $this->staff('sales');
        $u->update(['divisions' => $divisions]);

        return $u;
    }

    public function test_the_switcher_shows_only_the_divisions_a_user_can_open(): void
    {
        $this->as(['replicat' => 'staff', 'crowdmerch' => 'view', 'crowdlink' => null, 'shared' => 'view']);

        $this->get('/quotes')->assertOk()->assertSee('aria-label="Division"', false)->assertSee('>Replicat</a>', false)->assertDontSee('>CrowdLink</a>', false)
            ->assertSee('Dashboard')->assertDontSee('Users &amp; access', false)->assertDontSee('CROWDMERCH ADMIN');
        $this->get(route('division.switch', 'replicat'))->assertRedirect(route('replicat.home'));
        $this->get(route('replicat.home'))->assertOk()->assertSee('Replicat home')->assertDontSee('Website orders');
        $this->get(route('division.switch', 'crowdlink'))->assertForbidden();
        $this->get(route('crowdlink.home'))->assertRedirect(route('replicat.home')); // no CrowdLink: to a division they can open
        $this->get(route('dashboard'))->assertOk()->assertSee('Business dashboard')->assertSee('Quotes this month')->assertSee('Figures appear when Replicat moves');
        $this->get(route('reports.index'))->assertOk()->assertSee('Sales by division');
        $this->get(route('admin.users.index'))->assertForbidden(); // shared admin only
        $this->get(route('admin.sync.index'))->assertForbidden(); // CrowdMerch admin only
    }

    public function test_admin_rights_are_per_division(): void
    {
        $this->as(['replicat' => 'view', 'crowdmerch' => 'admin', 'crowdlink' => null, 'shared' => 'admin']);
        $this->get(route('admin.sync.index'))->assertOk();
        $this->get('/quotes')->assertSee('CROWDMERCH ADMIN')->assertSee('Users &amp; access', false);

        $other = User::factory()->create(['role' => 'sales']);
        $this->put(route('admin.users.update', $other), ['active' => 1, 'access' => ['replicat' => 'staff', 'crowdmerch' => '', 'crowdlink' => 'admin', 'shared' => 'view']])->assertRedirect();
        $other->refresh();
        $this->assertSame(['staff', null, 'admin', 'view'], [$other->access('replicat'), $other->access('crowdmerch'), $other->access('crowdlink'), $other->access('shared')]);
        $this->assertFalse($other->canView('crowdmerch'));
        $this->assertTrue($other->isAdmin('crowdlink'));
    }

    public function test_crowdmerch_staff_can_change_but_view_only_cannot(): void
    {
        $this->as(['crowdmerch' => 'view', 'shared' => 'view']);
        $this->post(route('quotes.store'), [])->assertForbidden();
        $this->get('/')->assertRedirect(route('desk'));
    }

    public function test_accounts_from_before_divisions_keep_their_access(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $sales = User::factory()->create(['role' => 'sales']);
        $this->assertSame(['admin', 'admin', 'admin'], [$admin->access('replicat'), $admin->access('crowdmerch'), $admin->access('shared')]);
        $this->assertSame([null, 'staff', 'staff', null], [$sales->access('replicat'), $sales->access('crowdmerch'), $sales->access('shared'), $sales->access('crowdlink')]);
    }
}
