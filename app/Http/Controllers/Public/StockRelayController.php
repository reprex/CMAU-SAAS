<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Stock\PromodataStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The CMAU product page's "Check stock" button calls this instead of Promodata,
 * so the Promodata token stays on the server. Same URL and reply as the
 * stand-alone relay (fix/stock-relay): /stock.php?id=<APPAID>, Promodata's JSON unchanged.
 */
class StockRelayController extends Controller
{
    public function __invoke(Request $request, PromodataStock $stock)
    {
        $headers = ['Vary' => 'Origin', 'X-Content-Type-Options' => 'nosniff'];
        $origin = (string) $request->headers->get('Origin', '');
        if ($origin !== '') {
            if (! in_array($origin, config('quotes.stock_relay.allowed_origins'), true)) {
                return response()->json(['message' => 'Origin not allowed'], 403, $headers);
            }
            $headers += [
                'Access-Control-Allow-Origin' => $origin,
                'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
                'Access-Control-Max-Age' => '86400',
            ];
        }
        if ($request->isMethod('OPTIONS')) {
            return response('', 204, $headers);
        }

        $id = (string) $request->query('id', '');
        if (! preg_match('/^[1-9][0-9]{0,9}$/', $id)) {
            return response()->json(['message' => 'Invalid product id'], 400, $headers);
        }

        $cached = cache()->has("stock:{$id}");
        if (! $cached) {
            $window = (int) config('quotes.stock_relay.per_ip_window');
            $key = 'stock-relay:'.hash('sha256', (string) $request->ip());
            if (RateLimiter::tooManyAttempts($key, (int) config('quotes.stock_relay.per_ip_limit'))) {
                return response()->json(['message' => 'Too many stock checks, please try again shortly'], 429, $headers + ['Retry-After' => (string) RateLimiter::availableIn($key)]);
            }
            RateLimiter::hit($key, $window);
        }

        $result = $stock->raw((int) $id);
        if ($result['status'] !== 200) {
            return response()->json(['message' => 'Stock check is unavailable right now'], 502, $headers);
        }

        return response($result['body'], 200, $headers + [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'public, max-age=60',
            'X-Stock-Cache' => $result['cached'] ? 'hit' : 'miss',
        ]);
    }
}
