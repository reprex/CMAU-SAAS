<?php

return [

    /*
    | Promodata API (APPA supplier product data).
    | Endpoints used (from the TOD frmApi/modApi implementation):
    |   GET  /products?page=N[&supplier_id=X]
    |   POST /products/changed?since=YYYY-MM-DD HH:MM&page=N[&supplier_id=X]
    |   GET  /products/{id}
    | Authentication: "x-auth-token" header.
    */

    'base_url' => rtrim(env('PROMODATA_BASE_URL', 'https://api.promodata.com.au'), '/'),

    'token' => env('PROMODATA_TOKEN'),

    'timeout' => (int) env('PROMODATA_TIMEOUT', 60),

    // How many list pages one scheduled step processes before stopping,
    // so each cron run stays short on shared hosting.
    'pages_per_step' => (int) env('PROMODATA_PAGES_PER_STEP', 5),

];
