<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

/** Authenticator-app codes (TOTP), required on every account. */
class TwoFactorController extends Controller
{
    public function __construct(private readonly Google2FA $google2fa) {}

    public function setup(Request $request)
    {
        $user = $request->user();
        if ($user->hasTwoFactor()) {
            return redirect()->route('two-factor.challenge');
        }

        $secret = $request->session()->get('two_factor_pending') ?? $this->google2fa->generateSecretKey(32);
        $request->session()->put('two_factor_pending', $secret);

        $uri = $this->google2fa->getQRCodeUrl(config('app.name'), $user->email, $secret);
        $svg = (new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd)))->writeString($uri);

        return view('auth.two-factor-setup', ['secret' => $secret, 'qr' => 'data:image/svg+xml;base64,'.base64_encode($svg)]);
    }

    public function confirm(Request $request)
    {
        $secret = (string) $request->session()->get('two_factor_pending');
        $this->verify($request, $secret);

        $request->user()->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();
        $request->session()->forget('two_factor_pending');
        $request->session()->put('two_factor_passed', $request->user()->id);
        AuditLog::record('user.two_factor_enabled', $request->user());

        return redirect()->route('quotes.index');
    }

    public function challenge(Request $request)
    {
        if (! $request->user()->hasTwoFactor()) {
            return redirect()->route('two-factor.setup');
        }

        return view('auth.two-factor-challenge');
    }

    public function verifyChallenge(Request $request)
    {
        $this->verify($request, (string) $request->user()->two_factor_secret);
        $request->session()->regenerate();
        $request->session()->put('two_factor_passed', $request->user()->id);

        return redirect()->intended(route('quotes.index'));
    }

    private function verify(Request $request, string $secret): void
    {
        $code = preg_replace('/\D/', '', (string) $request->input('code'));
        $key = 'two-factor:'.$request->user()->id;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => 'Too many attempts. Try again in a few minutes.']);
        }
        if ($secret === '' || strlen($code) !== 6 || ! $this->google2fa->verifyKey($secret, $code, 1)) {
            RateLimiter::hit($key, 300);
            throw ValidationException::withMessages(['code' => 'That code is not right. Use the current 6-digit code from your authenticator app.']);
        }
        RateLimiter::clear($key);
    }
}
