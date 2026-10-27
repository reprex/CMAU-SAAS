<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/** role:admin (admin only) or role:edit (admin or sales). */
class RequireRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();
        $allowed = match ($role) {
            'admin' => $user?->isAdmin(),
            'edit' => $user?->canEdit(),
            default => false,
        };

        abort_unless($allowed, 403, $role === 'admin' ? 'Admins only.' : 'Your account can view but not change quotes.');

        // Addresses or ranges: the office is 202.169.99.148 on IPv4, but reaches the
        // site over IPv6 from a prefix whose last part changes (privacy addresses).
        $ips = config('app.admin_allowed_ips');
        if ($role === 'admin' && $ips !== [] && ! IpUtils::checkIp((string) $request->ip(), $ips)) {
            abort(403, 'Admin screens are only available from the office.');
        }

        return $next($request);
    }
}
