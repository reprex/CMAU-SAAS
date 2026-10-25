<?php

return [

    // Quote numbers continue TOD's series (last seen: MR11770 / PS11774).
    'number_prefix' => env('QUOTE_NUMBER_PREFIX', 'MR'),
    'comparison_prefix' => env('QUOTE_COMPARISON_PREFIX', 'PS'),
    'number_start' => (int) env('QUOTE_NUMBER_START', 11775),

    'valid_days' => (int) env('QUOTE_VALID_DAYS', 30),
    'default_lead_time' => env('QUOTE_LEAD_TIME', 'About 20 working days plus delivery, from your artwork approval.'),
    'gst_rate' => 0.10,

    // Live stock (Promodata check-stock-levels).
    'stock_cache_seconds' => (int) env('STOCK_CACHE_SECONDS', 300),
    'stock_timeout' => (int) env('STOCK_TIMEOUT', 20),

    // Public stock relay for the CMAU product page (/stock.php?id=...).
    'stock_relay' => [
        'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('STOCK_RELAY_ORIGINS', 'https://crowdmerch.com.au,https://www.crowdmerch.com.au'))))),
        'per_ip_limit' => (int) env('STOCK_RELAY_PER_IP', 30), // uncached look-ups per window
        'per_ip_window' => (int) env('STOCK_RELAY_WINDOW', 300),
    ],

    // PDFs. The signature image is optional and kept out of Git: upload it
    // to storage/app/branding/signature.png through cPanel File Manager.
    'pdf' => [
        'logo' => public_path('img/crowdmerch-logo.png'),
        'signature' => storage_path('app/branding/signature.png'),
        'signed_by' => env('QUOTE_SIGNED_BY', 'Scott Wilson'),
        'signed_title' => env('QUOTE_SIGNED_TITLE', 'CrowdMerch'),
        'phone' => '1300 727 685',
        'email' => env('QUOTE_EMAIL', 'sales@replicat.com.au'),
        'website' => 'crowdmerch.com.au',
        'legal' => 'A Replicat Pty Ltd business · ABN 32 110 523 950',
    ],

];
