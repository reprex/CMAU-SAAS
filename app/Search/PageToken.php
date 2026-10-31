<?php

namespace App\Search;

/**
 * A short-lived signed token the step 1 page fetches when it loads. A search
 * must carry one, at least a couple of seconds old, so the search endpoint
 * can't be called directly or instantly by a script.
 */
class PageToken
{
    public function issue(?int $now = null): string
    {
        $ts = $now ?? time();
        $nonce = bin2hex(random_bytes(8));

        return "{$ts}.{$nonce}.".$this->sign("{$ts}.{$nonce}");
    }

    /** null when valid, else the reason. */
    public function check(?string $token, ?int $now = null): ?string
    {
        $now ??= time();
        $parts = explode('.', (string) $token);
        if (count($parts) !== 3 || ! ctype_digit($parts[0])) {
            return 'no_page_token';
        }
        [$ts, $nonce, $sig] = $parts;
        if (! hash_equals($this->sign("{$ts}.{$nonce}"), $sig)) {
            return 'bad_page_token';
        }
        $age = $now - (int) $ts;
        if ($age < (int) config('search.page_token.min_seconds')) {
            return 'too_fast';
        }
        if ($age > 60 * (int) config('search.page_token.max_minutes')) {
            return 'page_token_expired';
        }

        return null;
    }

    private function sign(string $payload): string
    {
        return substr(hash_hmac('sha256', $payload, (string) config('app.key')), 0, 32);
    }
}
