<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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

        $ips = config('app.admin_allowed_ips');
        if ($role === 'admin' && $ips !== [] && ! in_array($request->ip(), $ips, true)) {
            abort(403, 'Admin screens are only available from the office.');
        }

        return $next($request);
    }
}
