<?php

/*
| Business divisions (Scott, 5 Nov 2026; docs/divisions.md). One SaaS for Replicat, CrowdMerch and
| CrowdLink (REPSTACK: one platform, one legal entity; the business unit is branding, numbering and
| reporting). Each division has its own screens and its own staff/admin access; the shared modules
| (dashboard, management reports, contacts, freight, users) serve all three.
|
| routes: route-name patterns that belong to the division. A route matching none is CrowdMerch's
| (everything built so far), except the shared ones.
*/
return [
    'list' => [
        'replicat' => ['name' => 'Replicat', 'tagline' => 'Vinyl, CD & DVD', 'home' => 'replicat.home', 'routes' => ['replicat.*']],
        'crowdmerch' => ['name' => 'CrowdMerch', 'tagline' => 'Branded merchandise', 'home' => 'desk', 'routes' => []],
        'crowdlink' => ['name' => 'CrowdLink', 'tagline' => 'Client stores & sales', 'home' => 'crowdlink.home', 'routes' => ['crowdlink.*']],
    ],
    'default' => 'crowdmerch',

    // Shared modules: one access level of their own ("shared"); users and audit need shared admin.
    'shared_routes' => ['dashboard', 'reports.*', 'contacts.*', 'freight.*', 'division.switch', 'admin.users.*'],

    // Access levels, lowest first.
    'levels' => ['view' => 'View only', 'staff' => 'Staff', 'admin' => 'Admin'],
];
