<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::get('/_test/ip', fn (Request $r) => $r->ip());
    }

    public function test_cloudflare_edge_passes_the_client_ip(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '162.158.1.10', 'HTTP_X_FORWARDED_FOR' => '202.169.99.148'])
            ->get('/_test/ip')->assertSee('202.169.99.148');
    }

    public function test_a_direct_caller_cannot_forge_its_ip(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_FORWARDED_FOR' => '202.169.99.148'])
            ->get('/_test/ip')->assertSee('198.51.100.7')->assertDontSee('202.169.99.148');
    }
}
