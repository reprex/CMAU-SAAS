<?php

/*
| Step 1 "What kind of merch are you after?" search (docs/website-search.md).
| Keyword search always works. Claude is used only when every bot check passes,
| the request reads like a brief (or keyword search finds little), and the
| per-visitor and daily limits allow it. Otherwise the visitor gets keyword
| results, so the page never breaks and a bot never costs anything.
*/
return [
    'enabled' => (bool) env('WEBSITE_SEARCH', true),
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('WEBSITE_SEARCH_ORIGINS', env('WEBSITE_QUOTE_ORIGINS', '')))))),

    // Cloudflare Turnstile (free, mostly invisible human check).
    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret' => env('TURNSTILE_SECRET'),
        'hostnames' => array_values(array_filter(array_map('trim', explode(',', (string) env('TURNSTILE_HOSTNAMES', ''))))),
        // Also require it on Add to my list and quote requests (switch on once the theme sends it).
        'protect_website_api' => (bool) env('TURNSTILE_PROTECT_WEBSITE_API', false),
    ],

    // Signed page token: issued when the page loads; a search must come at least
    // min_seconds and at most max_minutes after it.
    'page_token' => ['min_seconds' => 2, 'max_minutes' => 60],

    'limits' => [
        'searches_per_minute' => (int) env('WEBSITE_SEARCH_PER_MINUTE', 10),   // any search, per IP
        'ai_per_hour' => (int) env('WEBSITE_SEARCH_AI_PER_HOUR', 5),           // Claude searches, per IP
        'ai_per_day' => (int) env('WEBSITE_SEARCH_AI_PER_DAY', 300),            // Claude searches, whole site
        'min_chars' => 3,
        'max_chars' => 400,
    ],

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_SEARCH_MODEL', 'claude-haiku-5-5'),
        'timeout' => 12,
        'cache_minutes' => 1440,
    ],
];
