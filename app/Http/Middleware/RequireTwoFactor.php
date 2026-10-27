<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every account uses two-factor sign-in: accounts without it are sent to set it
 * up, and each session must pass the code check once.
 */
class RequireTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return redirect()->route('login');
        }
        if (! $user->active) {
            auth()->logout();

            return redirect()->route('login')->withErrors(['email' => 'This account is switched off.']);
        }
        if (! $user->hasTwoFactor()) {
            return redirect()->route('two-factor.setup');
        }
        if ($request->session()->get('two_factor_passed') !== $user->id) {
            return redirect()->route('two-factor.challenge');
        }

        return $next($request);
    }
}
