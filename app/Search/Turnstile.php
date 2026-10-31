<?php

namespace App\Search;

use Illuminate\Support\Facades\Http;
use Throwable;

/** Cloudflare Turnstile server-side check (https://developers.cloudflare.com/turnstile/). */
class Turnstile
{
    public function configured(): bool
    {
        return filled(config('search.turnstile.secret'));
    }

    /** null when the visitor passed, else the reason. */
    public function check(?string $token, ?string $ip): ?string
    {
        if (! $this->configured()) {
            return 'turnstile_not_set_up';
        }
        if (blank($token) || strlen((string) $token) > 2048) {
            return 'no_turnstile';
        }
        try {
            $r = Http::asForm()->timeout(5)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => config('search.turnstile.secret'),
                'response' => $token,
                'remoteip' => $ip,
            ])->json();
        } catch (Throwable) {
            return 'turnstile_unreachable';
        }
        if (! ($r['success'] ?? false)) {
            return 'turnstile_failed';
        }
        $hosts = config('search.turnstile.hostnames');
        if ($hosts && ! in_array($r['hostname'] ?? '', $hosts, true)) {
            return 'turnstile_wrong_host';
        }

        return null;
    }
}
