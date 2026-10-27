<?php

return [

    /*
    | Tiered storage (see docs/requirements.md, "Compact storage").
    |   live    - on the CrowdMerch web range: full record kept
    |   active  - used recently (quoted, ordered, opened by staff): full record kept
    |   summary - everything else: one summary row, full data fetched on demand
    */

    'active_window_months' => (int) env('CATALOGUE_ACTIVE_WINDOW_MONTHS', 12),

    // The app refuses to boot in production below this PHP version
    // (CVE-2026-109618 / CVE-2026-109619 were fixed in 8.4.27).
    'minimum_php_version' => '8.4.0',

];
