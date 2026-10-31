<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\SearchLog;
use App\Search\BriefParser;
use App\Search\ClaudeCurator;
use App\Search\KeywordSearch;
use App\Search\PageToken;
use App\Search\Turnstile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Step 1 search on the CMAU website (docs/website-search.md).
 *
 * Every request passes cheap checks first (origin, honeypot, rate limit, text
 * sanity, signed page token). Keyword search always answers. Claude is called
 * only for a brief-style request (or when keyword search finds little), from a
 * visitor who passed Cloudflare Turnstile, within the per-visitor and daily
 * limits, and never for verified bots or signed agents: they get keyword
 * results at no cost. Every request is logged with the checks it passed.
 */
class WebsiteSearchController extends Controller
{
    public function token(Request $request, PageToken $tokens): JsonResponse
    {
        if ($blocked = $this->origin($request)) {
            return $blocked;
        }
        $key = 'search-token:'.$this->visitor($request);
        if (RateLimiter::tooManyAttempts($key, 30)) {
            return $this->reply($request, ['message' => 'Too many requests'], 429);
        }
        RateLimiter::hit($key, 60);

        return $this->reply($request, ['token' => $tokens->issue(), 'turnstile_site_key' => config('search.turnstile.site_key')]);
    }

    public function search(Request $request, PageToken $tokens, Turnstile $turnstile, BriefParser $parser, KeywordSearch $keyword, ClaudeCurator $claude): JsonResponse
    {
        $started = microtime(true);
        if ($blocked = $this->origin($request)) {
            return $blocked;
        }
        $visitor = $this->visitor($request);
        $q = trim(preg_replace('/\s+/', ' ', (string) $request->input('q', '')));
        $agent = $request->headers->get('X-Verified-Bot') === '1';
        $checks = ['agent' => $agent];
        $log = fn (string $mode, ?string $reason, array $results = [], array $usage = []) => SearchLog::query()->create([
            'visitor' => $visitor, 'query' => mb_substr($q, 0, 400), 'mode' => $mode, 'reason' => $reason, 'agent' => $agent,
            'checks' => $checks, 'result_ids' => array_column($results, 'id'),
            'tokens_in' => $usage['tokens_in'] ?? 0, 'tokens_out' => $usage['tokens_out'] ?? 0,
            'ms' => (int) ((microtime(true) - $started) * 1000), 'created_at' => now(),
        ]);

        // 1. Cheap checks that stop most bots outright.
        if (trim((string) $request->input('website', '')) !== '') {
            $log('blocked', 'honeypot');

            return $this->reply($request, ['mode' => 'keyword', 'results' => []]); // look normal, do nothing
        }
        $rate = 'search:'.$visitor;
        if (RateLimiter::tooManyAttempts($rate, (int) config('search.limits.searches_per_minute'))) {
            $log('blocked', 'rate_limit');

            return $this->reply($request, ['message' => 'Too many searches. Please wait a moment.'], 429);
        }
        RateLimiter::hit($rate, 60);
        if ($junk = $this->junk($q)) {
            $log('blocked', $junk);

            return $this->reply($request, ['mode' => 'keyword', 'results' => [], 'message' => 'Try describing the products you are after.'], 422);
        }
        if ($why = $tokens->check($request->input('token'))) {
            $log('blocked', $why);

            return $this->reply($request, ['message' => 'Please reload the page and try again.'], 403);
        }
        $checks['page_token'] = true;

        // 2. Keyword search: always available, no cost.
        $brief = $parser->parse($q);
        $hits = $keyword->search($brief);
        $answer = fn (string $mode, $rows, array $extra = []) => $this->reply($request, [
            'mode' => $mode,
            'understood' => ['qty' => $brief['qty'], 'budget' => $brief['budget'], 'under' => $brief['under'], 'eco' => $brief['eco'], 'colours' => $brief['colours'], 'types' => $brief['types']] + ($extra['understood'] ?? []),
            'results' => array_values(is_array($rows) ? $rows : $rows->all()),
        ]);

        // 3. Decide whether Claude is worth calling, and whether this visitor may use it.
        $reason = match (true) {
            ! $brief['briefLike'] && $hits->count() >= 3 => 'keyword_enough',
            $agent => 'agent_keyword_only',
            default => null,
        };
        if ($reason === null && ($why = $turnstile->check($request->input('cf_turnstile'), $request->ip()))) {
            $reason = $why;
        }
        $checks['turnstile'] = $reason === null;
        $aiHour = 'search-ai:'.$visitor;
        $dayKey = 'search:ai-day:'.now()->format('Ymd');
        if ($reason === null && RateLimiter::tooManyAttempts($aiHour, (int) config('search.limits.ai_per_hour'))) {
            $reason = 'ai_hourly_limit';
        }
        if ($reason === null && (int) Cache::get($dayKey, 0) >= (int) config('search.limits.ai_per_day')) {
            $reason = 'ai_daily_budget';
        }
        if ($reason !== null) {
            $log('keyword', $reason, $hits->all());

            return $answer('keyword', $hits);
        }

        // 4. Same brief asked before: reuse the answer at no cost.
        $cacheKey = 'search:ai:'.sha1($brief['text']);
        if ($cached = Cache::get($cacheKey)) {
            $log('cached', null, $cached['results']);

            return $answer('ai', $cached['results'], ['understood' => $cached['understood']]);
        }

        // 5. Claude picks from a shortlist the SaaS built and priced.
        RateLimiter::hit($aiHour, 3600);
        Cache::add($dayKey, 0, now()->endOfDay());
        Cache::increment($dayKey);
        try {
            $shortlist = $keyword->candidates($brief);
            $ai = $claude->curate($q, $shortlist);
            $byId = $shortlist->keyBy('id');
            $results = array_map(fn ($p) => $byId[$p['id']] + ['reason' => $p['reason']], $ai['picks']);
            $understood = array_intersect_key($ai['understood'], array_flip(['qty', 'budget_each', 'needed_by', 'eco', 'products']));
            Cache::put($cacheKey, ['results' => $results, 'understood' => $understood], now()->addMinutes((int) config('search.anthropic.cache_minutes')));
            $log('ai', null, $results, $ai);

            return $answer('ai', $results, ['understood' => $understood]);
        } catch (Throwable $e) {
            report($e);
            $log('keyword', 'ai_error', $hits->all());

            return $answer('keyword', $hits);
        }
    }

    public function preflight(Request $request): JsonResponse
    {
        return $this->origin($request) ?? $this->reply($request, [], 204);
    }

    // ---------------------------------------------------------------------

    private function junk(string $q): ?string
    {
        $len = mb_strlen($q);

        return match (true) {
            $len < (int) config('search.limits.min_chars') => 'too_short',
            $len > (int) config('search.limits.max_chars') => 'too_long',
            (bool) preg_match('~https?://|www\.|<[a-z/]|\[url~i', $q) => 'link_or_markup',
            (bool) preg_match('/(.)\1{7,}/u', $q) => 'repeated_characters',
            ! preg_match('/[a-z]{2,}/i', $q) => 'no_words',
            default => null,
        };
    }

    private function visitor(Request $request): string
    {
        return substr(hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')), 0, 16);
    }

    private function origin(Request $request): ?JsonResponse
    {
        if (! config('search.enabled')) {
            return response()->json(['message' => 'Not available'], 404);
        }
        if (! in_array((string) $request->headers->get('Origin', ''), config('search.allowed_origins'), true)) {
            return response()->json(['message' => 'Origin not allowed'], 403);
        }

        return null;
    }

    private function reply(Request $request, array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status, [
            'Vary' => 'Origin',
            'Access-Control-Allow-Origin' => (string) $request->headers->get('Origin'),
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type',
            'Access-Control-Max-Age' => '86400',
            'Cache-Control' => 'no-store',
        ]);
    }
}
