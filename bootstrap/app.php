<?php

use App\Http\CloudflareIps;
use App\Http\Middleware\RequireRole;
use App\Http\Middleware\RequireTwoFactor;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'two-factor' => RequireTwoFactor::class,
            'role' => RequireRole::class,
        ]);
        $middleware->web(append: [SecurityHeaders::class]);
        // Shopify webhooks are verified by HMAC instead of a CSRF token.
        $middleware->validateCsrfTokens(except: ['webhooks/*', 'crowdmerch/homepage/*']);
        $middleware->redirectGuestsTo('/login');
        // Behind Cloudflare: trust only its edge ranges for the client IP.
        $middleware->trustProxies(at: CloudflareIps::trusted());
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
