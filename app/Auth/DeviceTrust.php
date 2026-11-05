<?php

namespace App\Auth;

use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * "Trust this device for 30 days" (Scott, 6 Nov 2026): after the code is entered once, a long random token in a
 * cookie (HttpOnly, Secure, encrypted) lets that browser skip the code until it expires. The password is still
 * asked whenever the session has ended. Only the token's hash is stored; staff can remove a device on Security.
 */
class DeviceTrust
{
    public const COOKIE = 'cmau_trusted';

    public const DAYS = 30;

    public static function remember(Request $request, User $user): void
    {
        $token = Str::random(64);
        $device = TrustedDevice::query()->create([
            'user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'name' => self::deviceName($request),
            'last_used_at' => now(), 'expires_at' => now()->addDays(self::DAYS),
        ]);
        Cookie::queue(Cookie::make(self::COOKIE, $device->id.'|'.$token, self::DAYS * 24 * 60, null, null, true, true, false, 'lax'));
    }

    /** This browser is trusted for this user (and the trust hasn't expired). */
    public static function passes(Request $request, User $user): bool
    {
        [$id, $token] = array_pad(explode('|', (string) $request->cookie(self::COOKIE), 2), 2, '');
        if (! ctype_digit($id) || $token === '') {
            return false;
        }
        $device = TrustedDevice::query()->where('id', (int) $id)->where('user_id', $user->id)->where('expires_at', '>', now())->first();
        if (! $device || ! hash_equals($device->token_hash, hash('sha256', $token))) {
            return false;
        }
        $device->forceFill(['last_used_at' => now()])->save();

        return true;
    }

    public static function deviceName(Request $request): string
    {
        $ua = (string) $request->userAgent();
        $os = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone', str_contains($ua, 'iPad') => 'iPad', str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows', str_contains($ua, 'Mac OS') => 'Mac', default => 'Device',
        };
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge', str_contains($ua, 'CriOS') || str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Firefox') => 'Firefox', str_contains($ua, 'Safari') => 'Safari', default => 'browser',
        };

        return "{$browser} on {$os}";
    }

    public static function isMobile(Request $request): bool
    {
        return (bool) preg_match('/iPhone|iPad|Android|Mobile/i', (string) $request->userAgent());
    }
}
