<?php

/*
| Shopify Admin API. Tokens live only in .env on the server.
|
| SAFETY: the app writes only to the development store unless SHOPIFY_ALLOW_LIVE
| is true. SHOPIFY_SHOP_DOMAIN must equal SHOPIFY_DEV_STORE otherwise, so a live token
| pasted by mistake cannot create or change anything on the live CMAU store.
*/

return [
    'store' => env('SHOPIFY_SHOP_DOMAIN'),       // e.g. cmau-dev.myshopify.com
    'dev_store' => env('SHOPIFY_DEV_STORE'),     // the CMAU_DEV store's myshopify domain
    'allow_live' => (bool) env('SHOPIFY_ALLOW_LIVE', false),
    'token' => env('SHOPIFY_ADMIN_TOKEN'),
    'api_version' => env('SHOPIFY_API_VERSION', '2026-07'),
    'webhook_secret' => env('SHOPIFY_WEBHOOK_SECRET'),

    // Pull draft orders changed since the last run (cron-friendly backup to webhooks).
    'pull_drafts' => (bool) env('SHOPIFY_PULL_DRAFTS', true),

    // Website quote API (replaces the app.crowdmerch.com.au draft order endpoints).
    'website_api' => [
        'enabled' => (bool) env('WEBSITE_QUOTE_API', true),
        'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('WEBSITE_QUOTE_ORIGINS', ''))))),
        'quotes_per_hour' => (int) env('WEBSITE_QUOTES_PER_HOUR', 10),     // per IP
        'wishlist_adds_per_hour' => (int) env('WEBSITE_WISHLIST_PER_HOUR', 60),
        'email_instant_quote' => (bool) env('WEBSITE_EMAIL_INSTANT_QUOTE', true),
    ],
];
