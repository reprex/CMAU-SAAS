<?php
// Copy to config/config.php on the server and fill in. config.php is git-ignored.
return [
    'db' => [
        'dsn'      => 'mysql:host=localhost;dbname=cmau_saas;charset=utf8mb4',
        'user'     => 'cmau_saas',
        'password' => 'change-me',
    ],
    // Stock relay for the PDP "Check stock" button (public/stock.php).
    'promodata_stock' => [
        'token'           => 'paste-the-NEW-Promodata-token-here',
        'allowed_origins' => [
            'https://crowdmerch.com.au',
            'https://www.crowdmerch.com.au',
            'https://merchrep.myshopify.com',
            // add the CMAUDEV store address, e.g. 'https://your-cmaudev-store.myshopify.com'
        ],
        'cache_seconds'   => 300, // one Promodata call per product per 5 minutes
        'per_ip_limit'    => 30,  // uncached look-ups per visitor ...
        'per_ip_window'   => 300, // ... per 5 minutes
    ],
    'competitor_prices' => [
        // Sent with every competitor request so site owners can see who we are.
        'user_agent' => 'Agent/CMAU-PriceCheck (+mailto:scott@replicat.com.au)',
    ],
];
