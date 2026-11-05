<?php

namespace App\Http\Controllers\Auth;

use App\Auth\DeviceTrust;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Passkey;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use lbuchs\WebAuthn\Binary\ByteBuffer;
use lbuchs\WebAuthn\WebAuthn;
use Throwable;

/**
 * Passkeys (Scott, 6 Nov 2026): on a phone, sign in with Face ID (or the phone's passcode) instead of password and
 * code. A passkey is made on the phone after signing in the usual way (Security page) and never leaves it; the SaaS
 * keeps only its public key. User verification is required, so a passkey counts as both factors.
 */
class PasskeyController extends Controller
{
    private function webAuthn(Request $request): WebAuthn
    {
        return new WebAuthn('CMAU SaaS', $request->getHost(), ['none'], true);
    }

    /** Sign in: the challenge for the phone. */
    public function loginOptions(Request $request): JsonResponse
    {
        $wa = $this->webAuthn($request);
        $args = $wa->getGetArgs([], 60, false, false, false, false, true, 'required'); // the phone's own passkey only
        $request->session()->put('passkey_challenge', $wa->getChallenge()->getHex());

        return response()->json($args);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['id' => 'required|string|max:512', 'clientDataJSON' => 'required|string', 'authenticatorData' => 'required|string', 'signature' => 'required|string']);
        $key = 'passkey:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            return response()->json(['error' => 'Too many attempts. Try again in a few minutes.'], 429);
        }
        $challenge = (string) $request->session()->pull('passkey_challenge', '');
        $passkey = Passkey::query()->with('user')->where('credential_id', $data['id'])->first();
        try {
            if ($challenge === '' || ! $passkey || ! $passkey->user?->active) {
                throw new \RuntimeException('Unknown passkey');
            }
            $wa = $this->webAuthn($request);
            $wa->processGet(self::b64($data['clientDataJSON']), self::b64($data['authenticatorData']), self::b64($data['signature']),
                $passkey->public_key, ByteBuffer::fromHex($challenge), $passkey->sign_count ?: null, true);
        } catch (Throwable) {
            RateLimiter::hit($key, 300);

            return response()->json(['error' => 'That passkey wasn\'t accepted. Sign in with your password instead.'], 422);
        }
        RateLimiter::clear($key);
        $passkey->forceFill(['sign_count' => (int) ($wa->getSignatureCounter() ?? $passkey->sign_count), 'last_used_at' => now()])->save();

        $user = $passkey->user;
        Auth::login($user, true);
        $request->session()->regenerate();
        $request->session()->put('two_factor_passed', $user->id);
        $user->forceFill(['last_login_at' => now()])->save();
        AuditLog::record('user.login_passkey', $user);

        return response()->json(['redirect' => redirect()->intended(url('/'))->getTargetUrl()]);
    }

    /** Security page: passkeys and trusted devices. */
    public function security(Request $request): View
    {
        $user = $request->user();

        return view('auth.security', [
            'passkeys' => Passkey::query()->where('user_id', $user->id)->latest()->get(),
            'devices' => TrustedDevice::query()->where('user_id', $user->id)->where('expires_at', '>', now())->latest('last_used_at')->get(),
            'mobile' => DeviceTrust::isMobile($request),
        ]);
    }

    public function registerOptions(Request $request): JsonResponse
    {
        $user = $request->user();
        $wa = $this->webAuthn($request);
        $exclude = Passkey::query()->where('user_id', $user->id)->pluck('credential_id')->map(fn ($id) => self::b64($id))->all();
        $args = $wa->getCreateArgs((string) $user->id, (string) $user->email, (string) $user->name, 60, true, 'required', false, $exclude);
        $request->session()->put('passkey_register_challenge', $wa->getChallenge()->getHex());

        return response()->json($args);
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate(['clientDataJSON' => 'required|string', 'attestationObject' => 'required|string']);
        $challenge = (string) $request->session()->pull('passkey_register_challenge', '');
        try {
            $made = $this->webAuthn($request)->processCreate(self::b64($data['clientDataJSON']), self::b64($data['attestationObject']),
                ByteBuffer::fromHex($challenge), true, true, false);
        } catch (Throwable $e) {
            return response()->json(['error' => 'The passkey couldn\'t be saved: '.$e->getMessage()], 422);
        }
        Passkey::query()->create([
            'user_id' => $request->user()->id, 'credential_id' => rtrim(strtr(base64_encode($made->credentialId), '+/', '-_'), '='),
            'public_key' => $made->credentialPublicKey, 'sign_count' => (int) ($made->signatureCounter ?? 0), 'name' => DeviceTrust::deviceName($request),
        ]);
        AuditLog::record('user.passkey_added', $request->user());

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, Passkey $passkey): RedirectResponse
    {
        abort_unless($passkey->user_id === $request->user()->id || $request->user()->isAdmin(), 403);
        $passkey->delete();
        AuditLog::record('user.passkey_removed', $request->user());

        return back()->with('status', 'Passkey removed.');
    }

    public function forgetDevice(Request $request, TrustedDevice $device): RedirectResponse
    {
        abort_unless($device->user_id === $request->user()->id || $request->user()->isAdmin(), 403);
        $device->delete();

        return back()->with('status', 'That device will be asked for a code again.');
    }

    /** base64 or base64url → binary. */
    private static function b64(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/').str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }
}
