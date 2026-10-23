<?php

return [

    // Suppliers that never get TOD's zero-price "Unbranded" / "only available
    // unbranded" placeholder options (TOD excluded AS Colour, supplier 88).
    'no_placeholder_suppliers' => [88],

    // Quantity used to rank decorations in DecorationJSON (TOD: GetPrice(..., 50) + setup).
    'decoration_sort_qty' => 50,

    // Whether order values (and so the collection "from" price) include the
    // default decoration's setup. TOD includes it when the product uses its
    // own decorations and leaves it out when decorations are borrowed from
    // another product (e.g. Melbourne Merch 10002037). "own" reproduces that;
    // "always" and "never" are the consistent alternatives.
    'order_value_decoration_setup' => 'own',

    /*
    | Supplier-specific rules, from TOD's "PDS - Supplier-specific updates"
    | macro and its price-break queries. Keyed by supplier id.
    |
    |  force_type                  every non-EXT option gets this type code, and its markup
    |                              (Shiny: blank garments, priced at the undecorated markup)
    |  type_by_description         named description rule ("logoline")
    |  undecorated_cost_add        added to undecorated unit costs before markup
    |  undecorated_price_rounding  "nearest" (Access Round) instead of rounding up
    |  collection_moq              fixed collection (PDP load) quantity
    |  min_collection_moq          collection quantity is at least this
    |
    | Trends' "MOQ 1" products come from Trends' own product file, so they are
    | a per-product override (moq_one) rather than a supplier rule.
    */
    'supplier_rules' => [
        2 => ['type_by_description' => 'logoline'],          // Logo Line
        53 => ['force_type' => 12, 'min_collection_moq' => 20], // Shiny
        68 => [                                               // JB's Wear
            'undecorated_cost_add' => 2.00,
            'undecorated_price_rounding' => 'nearest',
            'collection_moq' => 50,
        ],
        88 => ['collection_moq' => 50],                       // AS Colour
    ],

];
