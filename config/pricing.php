<?php

return [

    // Suppliers that never get TOD's zero-price "Unbranded" / "only available
    // unbranded" placeholder options (TOD excluded AS Colour, supplier 88).
    'no_placeholder_suppliers' => [88],

    // Quantity used to rank decorations in DecorationJSON (TOD: GetPrice(..., 50) + setup).
    'decoration_sort_qty' => 50,

];
