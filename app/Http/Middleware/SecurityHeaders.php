<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // A page that needs one inline script or style (the website staff hand-over) sets a
        // per-request nonce; nothing else is allowed inline.
        $nonce = $request->attributes->get('csp_nonce');
        $n = $nonce ? " 'nonce-{$nonce}'" : '';
        $response->headers->set('Content-Security-Policy', "default-src 'self'; img-src 'self' https: data:; style-src 'self' https://fonts.googleapis.com{$n}; font-src 'self' https://fonts.gstatic.com; script-src 'self'{$n}; frame-ancestors 'none'; form-action 'self'");
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
