<?php

namespace App\Http\Middleware;

use App\Support\Divisions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Which division a screen belongs to, and whether the user may open it (docs/divisions.md). Opening
 * a division's screen makes it the division being viewed; shared screens keep the one you came from.
 */
class SetDivision
{
    public function handle(Request $request, Closure $next): Response
    {
        $division = Divisions::forRoute($request->route()?->getName());
        app()->instance('division.current', $division);
        $user = $request->user();
        if ($user && $user->access($division) === null) {
            // Signed in but not for this division: go to one they can open, rather than an error page.
            $first = $user->divisionKeys()[0] ?? null;
            if ($request->isMethod('GET') && $first && $division !== $first && $division !== Divisions::SHARED) {
                session(['division' => $first]);

                return redirect()->route(config("divisions.list.{$first}.home"));
            }
            abort(403, 'Your account has no access to '.Divisions::name($division).'.');
        }
        if ($division !== Divisions::SHARED) {
            session(['division' => $division]);
        }

        return $next($request);
    }
}
