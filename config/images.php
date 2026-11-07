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
        // Checks before this asked for a colour only on "other colour" images; products whose colours still lack
        // pictures are looked at again (catalogue:check-images, first each minute).
        'colour_question_since' => env('IMAGE_COLOUR_QUESTION_SINCE', '2026-11-07 21:00:00'),
        'colour_rechecks_per_run' => (int) env('IMAGE_COLOUR_RECHECKS_PER_RUN', 2),
        'colour_scan_per_run' => 60,
    ],

    /*
    | Reading supplier branding templates for positions (docs/branding-templates.md). A stronger model
    | than the image check: it places each print area on our photo, and that box is the mockup.
    */
    'templates' => [
        'model' => env('ANTHROPIC_TEMPLATE_MODEL', 'claude-sonnet-5-5'),
        'timeout' => 90,
        'per_day' => (int) env('TEMPLATE_READS_PER_DAY', 5000),
        'max_tokens' => (int) env('TEMPLATE_MAX_TOKENS', 16000), // up to 12 positions, each with methods, a box and four corners; Sonnet can write a long reply (5 Nov 2026: 4,000 cut 6 in 45 off)
    ],

    /*
    | Example branding on the colour photos a mockup is drawn on (docs/branding-templates.md, "Photos with
    | example branding"). Each photo once; its own daily limit, separate from the image check.
    */
    'branding' => [
        'enabled' => (bool) env('PHOTO_BRANDING_CHECK', env('IMAGE_CHECK', false)),
        'model' => env('ANTHROPIC_IMAGE_MODEL', 'claude-haiku-5-5'),
        'timeout' => 60,
        'images_per_request' => 16,
        'per_day' => (int) env('PHOTO_BRANDING_PER_DAY', 20000),
    ],
];
