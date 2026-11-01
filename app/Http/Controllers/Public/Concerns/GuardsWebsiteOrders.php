<?php

namespace App\Http\Controllers\Public\Concerns;

use App\Search\Turnstile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The website API guard (same pattern as WebsiteQuoteController): origin
 * allow-list, honeypot, per-IP rate limit, the optional Turnstile check on
 * writes, and CORS replies for the CMAU theme. Config: website.orders.
 */
trait GuardsWebsiteOrders
{
    protected function guard(Request $request, string $bucket, int $perHour, bool $write = false, array $honeypotReply = ['success' => true]): ?JsonResponse
    {
        if (! config('website.orders.enabled')) {
            return response()->json(['success' => false, 'message' => 'Not available'], 404);
        }
        $origin = (string) $request->headers->get('Origin', '');
        if (! in_array($origin, config('website.orders.allowed_origins'), true)) {
            return response()->json(['success' => false, 'message' => 'Origin not allowed'], 403);
        }
        if (trim((string) $request->input('website', '')) !== '') {
            return $this->reply($request, $honeypotReply); // honeypot: look normal, do nothing
        }
        $key = "website-{$bucket}:".hash('sha256', (string) $request->ip());
        if (RateLimiter::tooManyAttempts($key, $perHour)) {
            return $this->reply($request, ['success' => false, 'message' => 'Too many requests. Please try again later.'], 429);
        }
        RateLimiter::hit($key, 3600);
        if ($write && config('search.turnstile.protect_website_api')
            && ($why = app(Turnstile::class)->check($request->input('cf_turnstile'), $request->ip()))) {
            return $this->reply($request, ['success' => false, 'message' => 'Please confirm you are human and try again.', 'reason' => $why], 403);
        }

        return null;
    }

    protected function reply(Request $request, array $body, int $status = 200): JsonResponse
    {
        $origin = (string) $request->headers->get('Origin', '');
        $headers = ['Vary' => 'Origin', 'Cache-Control' => 'no-store'];
        if (in_array($origin, config('website.orders.allowed_origins'), true)) {
            $headers += [
                'Access-Control-Allow-Origin' => $origin,
                'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type, X-Requested-With, Authorization',
                'Access-Control-Max-Age' => '86400',
            ];
        }

        return response()->json($body, $status, $headers);
    }

    public function preflight(Request $request): JsonResponse
    {
        return $this->reply($request, [], 204);
    }
}
