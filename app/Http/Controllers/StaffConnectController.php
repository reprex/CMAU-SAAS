<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Staff\StaffPasses;
use Illuminate\Http\Request;

/**
 * Opened in a pop-up from the website's "Staff sign-in" (docs/website-staff.md). The staff
 * member is already signed in to the SaaS with the two-step code (middleware); this hands a
 * fresh pass to the website window that opened it (postMessage to that exact origin only).
 */
class StaffConnectController extends Controller
{
    public function __invoke(Request $request, StaffPasses $passes)
    {
        $origin = (string) $request->query('origin', '');
        abort_unless(in_array($origin, config('website.orders.allowed_origins'), true), 403, 'That website is not allowed to use staff tools.');
        $user = $request->user();
        abort_unless($user->canEdit(), 403, 'Your account can view but not change things, so it has no staff tools on the website.');

        ['token' => $token, 'pass' => $pass] = $passes->issue($user, $origin);
        AuditLog::record('website_staff.signed_in', null, ['origin' => $origin]);

        return response()->view('staff.connect', [
            'origin' => $origin,
            'payload' => [
                'type' => 'cm-staff-pass',
                'token' => $token,
                'name' => $user->name,
                'role' => $user->role,
                'scopes' => $pass->scopes,
                'can_apply' => $passes->canApply($user, $request),
                'expires_at' => $pass->expires_at->toIso8601String(),
            ],
        ])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
