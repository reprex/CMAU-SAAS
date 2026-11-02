<?php

namespace App\Staff;

use App\Models\User;
use App\Models\WebsiteStaffPass;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Staff passes for the website's staff tools (docs/website-staff.md). A pass is made in the
 * SaaS after a normal sign-in with the two-step code, handed to the website in a pop-up, and
 * sent back as "Authorization: Bearer …". 12 hours, or until signed out.
 */
class StaffPasses
{
    public const SCOPES = ['curate', 'assist'];

    /** @return array{token: string, pass: WebsiteStaffPass} */
    public function issue(User $user, string $origin): array
    {
        $token = Str::random(48);
        $pass = WebsiteStaffPass::query()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'scopes' => $this->scopesFor($user),
            'origin' => $origin,
            'expires_at' => now()->addHours((int) config('website.staff.pass_hours', 12)),
        ]);

        return ['token' => $token, 'pass' => $pass];
    }

    public function scopesFor(User $user): array
    {
        return $user->canEdit() ? self::SCOPES : [];
    }

    public function resolve(Request $request, ?string $scope = null): ?WebsiteStaffPass
    {
        $token = (string) $request->bearerToken();
        if (strlen($token) < 40) {
            return null;
        }
        $pass = WebsiteStaffPass::query()->with('user')->where('token_hash', hash('sha256', $token))->first();
        if (! $pass || $pass->revoked_at || $pass->expires_at->isPast() || ! $pass->user || ! $pass->user->active) {
            return null;
        }
        if ($scope && ! in_array($scope, (array) $pass->scopes, true)) {
            return null;
        }
        if (! $pass->last_used_at || $pass->last_used_at->lt(now()->subMinute())) {
            $pass->forceFill(['last_used_at' => now()])->save();
        }

        return $pass;
    }

    /** Admin changes apply straight away, but only from the office when admin screens are limited to it. */
    public function canApply(User $user, Request $request): bool
    {
        if (! $user->isAdmin()) {
            return false;
        }
        $ips = config('app.admin_allowed_ips');

        return $ips === [] || IpUtils::checkIp((string) $request->ip(), $ips);
    }

    public function revoke(WebsiteStaffPass $pass): void
    {
        $pass->forceFill(['revoked_at' => now()])->save();
    }
}
