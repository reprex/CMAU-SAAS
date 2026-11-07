<?php

/*
| Website checkout freight (docs/website-checkout.md).
|
| PLACEHOLDER RATES: every price and transit time below is the approved
| prototype's (CMAUDEV-CHECKOUT, 1 Nov 2026), not a carrier quote. The
| "estimate" driver prices from them until the MachShip (TNT) and FedEx APIs
| are connected as drivers of their own (App\Freight\FreightDriver).
| All amounts ex GST, AUD.
*/
return [

    // "carriers": MachShip in Australia and FedEx overseas, each once its keys are set (else the estimate);
    // "estimate": the placeholder rates below only (tests, or if the carriers must be switched off).
    'driver' => env('FREIGHT_DRIVER', 'carriers'),

    // Where CrowdMerch goods ship from (the decorator's dock; Scott to confirm).
    'from' => [
        'suburb' => env('FREIGHT_FROM_SUBURB', 'DERRIMUT'),
        'postcode' => env('FREIGHT_FROM_POSTCODE', '3026'),
        'state' => env('FREIGHT_FROM_STATE', 'VIC'),
    ],

    // MachShip (Australia): the same account and TNT services as ROD (REPSTACK: Machship API integration
    // with ROD.mdb). Price = MachShip's sell price ex GST, plus markup_percent.
    'machship' => [
        'token' => env('MACHSHIP_TOKEN'),
        'base_url' => env('MACHSHIP_BASE_URL', 'https://live.machship.com/apiv2/'),
        'company_id' => env('MACHSHIP_COMPANY_ID'),
        // Empty = every carrier on the account (TNT and Capital Transport); only the services below are offered.
        'carrier_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('MACHSHIP_CARRIER_IDS', ''))))),
        // MachShip carrier service id => our service id (and name shown to clients).
        'services' => [
            540 => ['id' => 'tnt_road', 'name' => 'TNT Road Express'],
            538 => ['id' => 'tnt_overnight', 'name' => 'TNT Overnight Express'],
        ],
        // Melbourne metro couriers (Scott, 7 Nov 2026): offered at checkout when MachShip returns them for
        // the address and size (car for small consignments, station wagon for larger).
        'by_name' => [
            ['carrier' => '/capital/i', 'service' => '/wagon/i', 'id' => 'capital_wagon', 'name' => 'Capital Transport station wagon (Melbourne metro)'],
            ['carrier' => '/capital/i', 'service' => '/\bcar\b/i', 'id' => 'capital_car', 'name' => 'Capital Transport car (Melbourne metro)'],
        ],
        // Quotes always show this service for Australia (Scott, 7 Nov 2026); checkout lists all of them.
        'quote_service' => 'tnt_road',
        'markup_percent' => (float) env('MACHSHIP_MARKUP_PERCENT', 0),
        'timeout' => 12,
    ],

    // FedEx (overseas): REST Rate API with Replicat's account rates. Price = FedEx's account rate (AUD,
    // incl. fuel) plus markup_percent. Duties and taxes are the receiver's.
    'fedex' => [
        'client_id' => env('FEDEX_CLIENT_ID'),
        'client_secret' => env('FEDEX_CLIENT_SECRET'),
        'account_number' => env('FEDEX_ACCOUNT_NUMBER'),
        'base_url' => env('FEDEX_BASE_URL', 'https://apis.fedex.com'),
        'services' => [
            'FEDEX_INTERNATIONAL_PRIORITY' => 'fedex_ip', 'INTERNATIONAL_PRIORITY' => 'fedex_ip',
            'INTERNATIONAL_ECONOMY' => 'fedex_ie', 'FEDEX_INTERNATIONAL_ECONOMY' => 'fedex_ie',
        ],
        'commodity' => env('FEDEX_COMMODITY', 'Promotional merchandise'),
        // Quotes use Economy; checkout lists Priority and Economy only (Scott, 7 Nov 2026).
        'quote_service' => 'fedex_ie',
        'markup_percent' => (float) env('FEDEX_MARKUP_PERCENT', 0),
        'timeout' => 15,
    ],

    // Pack data when Promodata has no packing details for a product: by Promodata
    // product type name (lower case, partial match), then "default".
    // unit_kg, units_per_carton, carton_cm [l, w, h], tare_kg (empty carton).
    'pack_defaults' => [
        't-shirt' => ['unit_kg' => 0.19, 'units_per_carton' => 72, 'carton_cm' => [60, 45, 35], 'tare_kg' => 0.8],
        'tee' => ['unit_kg' => 0.19, 'units_per_carton' => 72, 'carton_cm' => [60, 45, 35], 'tare_kg' => 0.8],
        'hood' => ['unit_kg' => 0.55, 'units_per_carton' => 24, 'carton_cm' => [60, 45, 35], 'tare_kg' => 0.8],
        'cap' => ['unit_kg' => 0.09, 'units_per_carton' => 100, 'carton_cm' => [60, 40, 40], 'tare_kg' => 0.8],
        'tote' => ['unit_kg' => 0.12, 'units_per_carton' => 200, 'carton_cm' => [50, 40, 30], 'tare_kg' => 0.6],
        'default' => ['unit_kg' => 0.19, 'units_per_carton' => 72, 'carton_cm' => [60, 45, 35], 'tare_kg' => 0.8],
    ],

    // TNT cubic conversion (kg per cubic metre) and FedEx volumetric divisor (cm³ per kg).
    'au_cubic_kg_per_m3' => 250,
    'intl_volumetric_divisor' => 5000,

    // Australia: zone by the postcode's first digit. Each service: base + per_kg × chargeable kg.
    // days: business days in transit [fastest, slowest]; the slowest is used for the delivery date.
    'au_services' => [
        'tnt_road' => ['name' => 'TNT Road Express', 'carrier' => 'TNT'],
        'tnt_overnight' => ['name' => 'TNT Overnight Express', 'carrier' => 'TNT'],
    ],
    'au_zones' => [ // PLACEHOLDER (prototype AUZONE; overnight = road base × 1.7, per kg × 2.3)
        '3' => ['name' => 'VIC', 'tnt_road' => ['base' => 11.50, 'per_kg' => 0.36, 'days' => [1, 2]], 'tnt_overnight' => ['base' => 19.55, 'per_kg' => 0.828, 'days' => [1, 1]]],
        '2' => ['name' => 'NSW / ACT', 'tnt_road' => ['base' => 15.90, 'per_kg' => 0.58, 'days' => [2, 3]], 'tnt_overnight' => ['base' => 27.03, 'per_kg' => 1.334, 'days' => [1, 1]]],
        '4' => ['name' => 'QLD', 'tnt_road' => ['base' => 17.80, 'per_kg' => 0.74, 'days' => [3, 4]], 'tnt_overnight' => ['base' => 30.26, 'per_kg' => 1.702, 'days' => [1, 2]]],
        '5' => ['name' => 'SA', 'tnt_road' => ['base' => 16.40, 'per_kg' => 0.66, 'days' => [2, 3]], 'tnt_overnight' => ['base' => 27.88, 'per_kg' => 1.518, 'days' => [1, 2]]],
        '6' => ['name' => 'WA', 'tnt_road' => ['base' => 23.90, 'per_kg' => 1.18, 'days' => [5, 7]], 'tnt_overnight' => ['base' => 40.63, 'per_kg' => 2.714, 'days' => [2, 2]]],
        '7' => ['name' => 'TAS', 'tnt_road' => ['base' => 19.60, 'per_kg' => 0.86, 'days' => [3, 4]], 'tnt_overnight' => ['base' => 33.32, 'per_kg' => 1.978, 'days' => [1, 2]]],
        '0' => ['name' => 'NT', 'tnt_road' => ['base' => 27.50, 'per_kg' => 1.52, 'days' => [6, 8]], 'tnt_overnight' => ['base' => 46.75, 'per_kg' => 3.496, 'days' => [2, 3]]],
    ],

    // Overseas: FedEx by country (ISO 3166 alpha-2). Economy = Priority × 0.72 in the prototype.
    'intl_services' => [
        'fedex_ip' => ['name' => 'FedEx International Priority', 'carrier' => 'FedEx'],
        'fedex_ie' => ['name' => 'FedEx International Economy', 'carrier' => 'FedEx'],
    ],
    'intl_countries' => [ // PLACEHOLDER (prototype INTL)
        'NZ' => ['name' => 'New Zealand', 'fedex_ip' => ['base' => 62.00, 'per_kg' => 6.10, 'days' => [3, 5]], 'fedex_ie' => ['base' => 44.64, 'per_kg' => 4.392, 'days' => [6, 9]]],
        'US' => ['name' => 'United States', 'fedex_ip' => ['base' => 88.00, 'per_kg' => 9.40, 'days' => [3, 5]], 'fedex_ie' => ['base' => 63.36, 'per_kg' => 6.768, 'days' => [6, 10]]],
        'GB' => ['name' => 'United Kingdom', 'fedex_ip' => ['base' => 96.00, 'per_kg' => 10.20, 'days' => [4, 6]], 'fedex_ie' => ['base' => 69.12, 'per_kg' => 7.344, 'days' => [7, 11]]],
        'SG' => ['name' => 'Singapore', 'fedex_ip' => ['base' => 58.00, 'per_kg' => 5.60, 'days' => [2, 4]], 'fedex_ie' => ['base' => 41.76, 'per_kg' => 4.032, 'days' => [5, 8]]],
        'CA' => ['name' => 'Canada', 'fedex_ip' => ['base' => 94.00, 'per_kg' => 9.90, 'days' => [4, 6]], 'fedex_ie' => ['base' => 67.68, 'per_kg' => 7.128, 'days' => [7, 11]]],
    ],
];
