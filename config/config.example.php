<?php
// Copy to config/config.php on the server and fill in. config.php is git-ignored.
return [
    'db' => [
        'dsn'      => 'mysql:host=localhost;dbname=cmau_saas;charset=utf8mb4',
        'user'     => 'cmau_saas',
        'password' => 'change-me',
    ],
    'competitor_prices' => [
        // Sent with every competitor request so site owners can see who we are.
        'user_agent' => 'Agent/CMAU-PriceCheck (+mailto:scott@replicat.com.au)',
    ],
];
