<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class AdminIpAllowListTest extends TestCase
{
    use QuoteFixtures, RefreshDatabase;

    private function adminPageFrom(string $ip)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->get(route('admin.users.index'));
    }

    public function test_office_ipv4_address_and_ipv6_prefix_are_allowed(): void
    {
        config(['app.admin_allowed_ips' => ['202.169.99.148', '2401:d002:3803:1a00::/56']]);
        $this->staff('admin');

        $this->adminPageFrom('202.169.99.148')->assertOk();
        $this->adminPageFrom('2401:d002:3803:1a00:29c8:6239:3011:df48')->assertOk();
        $this->adminPageFrom('2401:d002:3803:1a5c::1')->assertOk();
    }

    public function test_other_addresses_are_refused(): void
    {
        config(['app.admin_allowed_ips' => ['202.169.99.148', '2401:d002:3803:1a00::/56']]);
        $this->staff('admin');

        $this->adminPageFrom('203.0.113.9')->assertForbidden();
        $this->adminPageFrom('2401:d002:3803:1b00::1')->assertForbidden();
    }

    public function test_empty_list_allows_admins_anywhere(): void
    {
        config(['app.admin_allowed_ips' => []]);
        $this->staff('admin');

        $this->adminPageFrom('203.0.113.9')->assertOk();
    }
}
