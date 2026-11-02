<?php

/*
| Website checkout and My Orders (CMAUDEV-CHECKOUT, docs/website-checkout.md).
| Clients order ONE product and its related styles straight from the product
| page when stock, artwork, logos and the delivery date all allow it; otherwise
| the theme sends them to the quote flow. No online payment: an invoice is emailed.
*/
return [

    'orders' => [
        'enabled' => (bool) env('WEBSITE_ORDERS', true),
        // Defaults to the website quote API's origins; empty means nobody can call it.
        'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('WEBSITE_ORDER_ORIGINS', env('WEBSITE_QUOTE_ORIGINS', '')))))),
        'price_per_hour' => (int) env('WEBSITE_ORDER_PRICES_PER_HOUR', 240), // per IP (each live stock check)
        'orders_per_hour' => (int) env('WEBSITE_ORDERS_PER_HOUR', 6),         // per IP
        'changes_per_hour' => (int) env('WEBSITE_ORDER_CHANGES_PER_HOUR', 10),

        'number_prefix' => 'CM-',
        'number_start' => (int) env('WEBSITE_ORDER_NUMBER_START', 10001), // CM-10001, five digits

        // REPSTACK principle 6: client emails go to drafts and are never sent automatically.
        // false: the invoice email waits under Website orders for staff to send (one click).
        // true:  a queued job sends it within the minute cron. Scott to decide.
        'auto_send_invoice' => (bool) env('WEBSITE_AUTO_SEND_INVOICE', false),
        // Internal change-request notifications follow the same switch.
        'staff_email' => env('WEBSITE_ORDERS_STAFF_EMAIL', env('QUOTE_EMAIL', 'sales@replicat.com.au')),

        // Logo files, one per branding position. Stored on the private "artwork" disk.
        'logo_max_kb' => (int) env('WEBSITE_LOGO_MAX_KB', 20480),
        'logo_mimes' => ['png', 'jpg', 'jpeg', 'webp', 'svg', 'pdf', 'ai', 'eps'],
        'artwork_disk' => 'artwork',
        'invoice_disk' => 'local',

        'proof_ack_text' => "Once you've paid, we will provide a digital proof for your approval before proceeding with production.",
        'duty_note' => 'Delivering outside Australia: your invoice is ex GST (GST-free export). You must pay any local taxes and duties directly to the carrier on arrival before delivery can be made.',

        // Pre-payment alerts on the invoice.
        'deadline_risk_spare_days' => 1, // "deadline at risk" when this many business days to spare or fewer
        'stock_low_share' => 0.8,        // "stock low" when a line takes this share of the supplier's stock or more

        'max_units' => 100000,
        'max_lines' => 120,
        'max_positions' => 8,
    ],

    // Website project requests (the "Design something" survey; docs/website-project-requests.md).
    // Same guard as website orders: allowed origins, honeypot, per-IP limit and Turnstile.
    'projects' => [
        'per_hour' => (int) env('WEBSITE_PROJECTS_PER_HOUR', 6),       // per IP
        'number_prefix' => 'PR-',
        'number_start' => 10001,
        // Scott, 3 Nov 2026: the client's confirmation is sent straight away (an acknowledgement
        // with their own request in it, like the instant quote email), with the reply-by date.
        'send_confirmation' => (bool) env('WEBSITE_PROJECT_CONFIRMATION', true),
        'reply_working_days' => 1,
        'reply_state' => 'VIC',                                         // public holidays skipped
        'staff_email' => env('WEBSITE_PROJECTS_STAFF_EMAIL', env('WEBSITE_ORDERS_STAFF_EMAIL', env('QUOTE_EMAIL', 'sales@replicat.com.au'))),
        'max_files' => 6,
        'file_max_kb' => (int) env('WEBSITE_PROJECT_FILE_MAX_KB', 20480),
        'file_mimes' => ['png', 'jpg', 'jpeg', 'webp', 'gif', 'heic', 'svg', 'pdf', 'ai', 'eps', 'psd', 'doc', 'docx', 'xls', 'xlsx', 'zip'],
        'disk' => 'artwork',
        'kinds' => [
            'garments' => 'Garments and uniforms',
            'headwear' => 'Headwear',
            'bags' => 'Bags',
            'drinkware' => 'Drinkware',
            'giftware' => 'Gifts and giftware',
            'events' => 'Event merch (lanyards, wristbands, cards)',
            'custom' => 'Something made from scratch',
            'unsure' => 'Not sure yet',
        ],
    ],

    // Default payment terms; each supplier has its own (Admin › Suppliers), default "full".
    'payment_terms' => [
        'default' => 'full',
        'options' => [
            'full' => 'Full payment before production',
            // e.g. 'deposit50' => '50% deposit, balance before dispatch' (to add for suppliers such as Trends)
        ],
    ],

    // Lead times in business days (placeholders until Scott confirms real ones).
    'lead_times' => [
        'order_check' => (int) env('LEAD_ORDER_CHECK_DAYS', 1),   // order check and invoice
        'stock_default' => (int) env('LEAD_STOCK_DAYS', 2),       // stock from the supplier (per supplier in Admin › Suppliers)
        'decoration' => [                                         // the slowest method in the order counts
            'dtf' => 5,
            'dtf_oversize' => 6,
            'pad' => 7,
            'screen' => 8,
            'embroidery' => 9,
        ],
        'default_state' => 'VIC',
    ],

    /*
    | Decoration methods. Each decoration option of the product (from the SaaS
    | pricing, DecorationJSON's DecID) is matched to a method by its name.
    |  - review: needs the CrowdMerch team to check the artwork (no direct order)
    |  - pricing: flat | grid (a price line per colour count) | per_colour (1-colour price × colours)
    | Checked in this order; the first match wins. A name no method matches cannot
    | be ordered directly (the team quotes it).
    */
    /*
    | Artwork each decoration method wants (Scott vetted, 2 Nov 2026). Methods listed
    | here want solid colour vector artwork (AI, EPS, SVG, vector PDF); every other
    | method is full colour CMYK (300 dpi at print size, transparent base).
    | The theme keeps the same list in assets/cm-app.js (CM.VECTOR_METHODS).
    */
    'artwork_solid_methods' => ['embroidery', 'screen', 'pad', 'laser', 'deboss', 'foil', 'transfer_screen', 'enamel', 'woven'],

    'methods' => [
        'embroidery' => ['name' => 'Embroidery', 'review' => true, 'pricing' => 'flat', 'match' => '/embroider/i', 'max_colours' => 12],
        'screen' => ['name' => 'Screen print', 'review' => true, 'pricing' => 'grid', 'match' => '/screen/i', 'max_colours' => 4],
        'pad' => ['name' => 'Pad print', 'review' => true, 'pricing' => 'per_colour', 'match' => '/\bpad\b/i', 'max_colours' => 4],
        'dtf_oversize' => ['name' => 'Oversize DTF', 'review' => false, 'pricing' => 'flat', 'match' => '/(over\s*-?\s*size|oversized|jumbo).*(dtf|transfer)|(dtf|transfer).*(over\s*-?\s*size|oversized|jumbo)/i'],
        'dtf' => ['name' => 'DTF', 'review' => false, 'pricing' => 'flat', 'match' => '/\bdtf\b|direct to film|digital transfer|full colou?r transfer/i'],
    ],

    /*
    | Samples (Scott, 1 Nov 2026): "Order sample" for suppliers with
    | suppliers.offers_samples. Price = the unbranded price at the MOQ; freight =
    | the supplier's sample_freight once per factory (Admin › Suppliers). One
    | invoice for the whole samples cart. Australia only for now.
    */
    'samples' => [
        'freight_service' => 'tnt_road',  // transit days for the delivery estimate (config/freight.php au_zones)
        'transit_days_default' => 3,
        'max_items' => 30,
        'note' => "Samples may be unprinted or taken from another client's print overrun. The factory decides, and it can vary.",
    ],

    'account' => [
        'code_minutes' => 10,
        'max_attempts' => 5,
        'codes_per_email_per_hour' => 3,
        'codes_per_ip_per_hour' => 10,
        'verifies_per_ip_per_hour' => 30,
        'token_days' => 90,
    ],

    // SMS for My Orders codes. "log" writes the message to the log; the provider is chosen later.
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'from' => env('SMS_FROM', 'CrowdMerch'),
        'key' => env('SMS_API_KEY'),
    ],

    // Address autocomplete for checkout. "none" returns no suggestions (manual entry).
    'address' => [
        'driver' => env('ADDRESS_LOOKUP_DRIVER', 'none'),
        'key' => env('ADDRESS_LOOKUP_KEY'),
        'per_hour' => (int) env('ADDRESS_LOOKUPS_PER_HOUR', 300),
    ],

    'tracking' => [
        'TNT' => 'https://www.tnt.com/express/en_au/site/shipping-tools/tracking.html?searchType=con&cons={n}',
        'FedEx' => 'https://www.fedex.com/fedextrack/?trknbr={n}',
    ],
];
