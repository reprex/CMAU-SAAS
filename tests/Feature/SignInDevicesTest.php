<?php

namespace Tests\Feature;

use App\Auth\DeviceTrust;
use App\Models\Passkey;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/** Sign-in (Scott, 6 Nov 2026): passkey on a phone; password + code on a computer, the code skipped for 30 days on a trusted device. */
class SignInDevicesTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

    private const PC = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    private function user(): User
    {
        return User::factory()->create(['role' => 'admin', 'password' => bcrypt('secret-pass-1'), 'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()]);
    }

    public function test_a_trusted_computer_skips_the_code_until_it_expires(): void
    {
        config(['app.admin_allowed_ips' => []]);
        $u = $this->user();
        $this->withHeader('User-Agent', self::PC)->post('/login', ['email' => $u->email, 'password' => 'secret-pass-1'])->assertRedirect();
        $this->get(route('security'))->assertRedirect(route('two-factor.challenge'));
        $code = app(Google2FA::class)->getCurrentOtp('JBSWY3DPEHPK3PXP');
        $r = $this->post(route('two-factor.challenge'), ['code' => $code, 'trust' => '1'])->assertRedirect();
        $cookie = collect($r->headers->getCookies())->first(fn ($c) => $c->getName() === DeviceTrust::COOKIE);
        $this->assertNotNull($cookie);
        $this->assertSame('Chrome on Windows', TrustedDevice::query()->value('name'));

        // A new session (signed out): password again, but no code on this browser.
        $this->post(route('logout'));
        $this->post('/login', ['email' => $u->email, 'password' => 'secret-pass-1']);
        $this->withUnencryptedCookie(DeviceTrust::COOKIE, $cookie->getValue())->get(route('security'))->assertOk();

        // Expired: the code is asked again.
        TrustedDevice::query()->update(['expires_at' => now()->subDay()]);
        $this->flushSession();
        $this->post('/login', ['email' => $u->email, 'password' => 'secret-pass-1']);
        $this->withUnencryptedCookie(DeviceTrust::COOKIE, $cookie->getValue())->get(route('security'))->assertRedirect(route('two-factor.challenge'));
    }

    public function test_passkey_sign_in_is_offered_on_a_phone_only_and_refuses_an_unknown_key(): void
    {
        $this->withHeader('User-Agent', self::PHONE)->get('/login')->assertSee('Sign in with Face ID');
        $this->withHeader('User-Agent', self::PC)->get('/login')->assertDontSee('Sign in with Face ID');

        $opts = $this->getJson(route('passkey.login.options'))->assertOk()->json('publicKey');
        $this->assertNotEmpty($opts['challenge']);
        $this->assertSame('required', $opts['userVerification']);
        $this->postJson(route('passkey.login'), ['id' => 'nope', 'clientDataJSON' => 'e30', 'authenticatorData' => 'AA', 'signature' => 'AA'])->assertStatus(422);
        $this->assertGuest();
    }

    public function test_security_page_and_removing_a_device(): void
    {
        config(['app.admin_allowed_ips' => []]);
        $u = $this->user();
        $this->actingAs($u)->withSession(['two_factor_passed' => $u->id]);
        Passkey::query()->create(['user_id' => $u->id, 'credential_id' => 'abc', 'public_key' => 'pem', 'name' => 'Safari on iPhone']);
        $d = TrustedDevice::query()->create(['user_id' => $u->id, 'token_hash' => str_repeat('a', 64), 'name' => 'Chrome on Windows', 'expires_at' => now()->addDays(30)]);
        $this->withHeader('User-Agent', self::PHONE)->get(route('security'))->assertOk()->assertSee('Safari on iPhone')->assertSee('Chrome on Windows')->assertSee('Add a passkey on this phone');
        $this->getJson(route('passkeys.options'))->assertOk()->assertJsonPath('publicKey.authenticatorSelection.userVerification', 'required');
        $this->delete(route('devices.destroy', $d))->assertRedirect();
        $this->assertSame(0, TrustedDevice::query()->count());
    }

    public function test_a_real_passkey_signature_signs_in_with_both_factors(): void
    {
        config(['app.admin_allowed_ips' => []]);
        $u = $this->user();
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $pem = openssl_pkey_get_details($key)['key'];
        Passkey::query()->create(['user_id' => $u->id, 'credential_id' => 'cred-1', 'public_key' => $pem, 'sign_count' => 0]);

        $b64u = fn ($b) => rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
        $challenge = $this->withHeader('User-Agent', self::PHONE)->getJson(route('passkey.login.options'))->json('publicKey.challenge');
        $host = parse_url(config('app.url'), PHP_URL_HOST) ?: 'localhost';
        $client = json_encode(['type' => 'webauthn.get', 'challenge' => $challenge, 'origin' => 'https://'.$host]);
        $auth = hash('sha256', $host, true).chr(0x05).pack('N', 7);   // user present + verified, counter 7
        openssl_sign($auth.hash('sha256', $client, true), $sig, $key, OPENSSL_ALGO_SHA256);

        $this->postJson(route('passkey.login'), ['id' => 'cred-1', 'clientDataJSON' => $b64u($client), 'authenticatorData' => $b64u($auth), 'signature' => $b64u($sig)])
            ->assertOk()->assertJsonStructure(['redirect']);
        $this->assertAuthenticatedAs($u);
        $this->get(route('security'))->assertOk();                        // no code asked: the passkey was both factors
        $this->assertSame(7, Passkey::query()->value('sign_count'));
    }
}
