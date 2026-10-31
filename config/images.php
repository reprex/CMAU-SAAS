<?php

/*
| Claude image check (docs/images.md). Looks at the images the Promodata data
| and file names leave unsure, for products on the web range, once per image
| URL. Off until ANTHROPIC_API_KEY is set and IMAGE_CHECK=true.
*/
return [
    'check' => [
        'enabled' => (bool) env('IMAGE_CHECK', false),
        'model' => env('ANTHROPIC_IMAGE_MODEL', 'claude-haiku-5-5'),
        'timeout' => 45,
        'products_per_run' => (int) env('IMAGE_CHECK_PRODUCTS_PER_RUN', 3),  // each scheduler minute
        'images_per_request' => 16,                                           // under the 20-image request limit with the hero
        'images_per_day' => (int) env('IMAGE_CHECK_PER_DAY', 3000),
        'colour_references' => 12,     // send colour images as references when a product has this many colours or fewer
        'min_confidence' => 0.7,       // below this Claude's view is shown but not used
    ],
];
