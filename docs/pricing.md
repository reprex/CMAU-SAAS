# Pricing engine

Code: `app/Pricing`. Command: `php artisan pricing:recalculate [product] [--supplier=]`.

The engine reproduces TOD's CostPriceBreak > CostPriceSummary > web fields > DecorationJSON chain. Every rule below was taken from the TOD queries and VBA named in brackets.

## Rules implemented

| Step | Rule | TOD source |
| --- | --- | --- |
| Option id | `APPAID-` + the description in lower-case letters and digits | `AlphaNumericOnly`, CostPriceBreak TextPDID |
| Merge | Price lines with the same code merge; the first line's setup and description are kept | CostPriceSummary insert |
| Classification | The code is looked up in `dec_type_codes`. New codes are added unclassified for staff to review | `DecTypeCode`, "CPS - set CPS TypeCode" |
| Unit markup | Type 10 uses `markup_decoration` (default 1.50); type 12 uses `markup_undecorated` (default 1.45); anything else, including unclassified, uses `markup_accessory` (default 1.45) | "update prices to UnDec Dec Acc markups" |
| Unit price | cost × markup, kept to 4 decimal places, then rounded **up** to the cent | `GlblRoundup(x, 2)` |
| Setup price | setup cost × `markup_setup`, not rounded | same query |
| Staff type override | Changes the option's type, not its markup | LocalPrice DecType |
| Placeholders | "Unbranded" `1:0` if there is no type 12 option; "Product is only available unbranded" `1:0` if there is no type 10 option. AS Colour (supplier 88) is excluded | "create 1:0 0 unbranded" queries |
| Price at quantity | Uses the largest break at or below the quantity. Below the minimum, the price is (minQty × minPrice) ÷ qty | `GetPrice` |
| Decoration source | MANUAL (staff pick), then EXTERNAL (borrowed from another product, e.g. 10001999), then AUTO | "set DecSource based on Man Ext Auto hierarchy" |
| ActualMOQ | max(undecorated MOQ, default decoration MOQ), unless staff set an override | "set product ActualMOQ" |
| Order value | decoration price × qty (no decoration setup) + undecorated setup + undecorated price × qty | "set product Order values" |
| Collection price | order value at CollectionMOQ ÷ CollectionMOQ | "set product CollectionVariantPrice" |
| BrandFlag | "includes branding" whenever any type 10 option exists, **including** the placeholder (12 live products in Jul 2026) | "flag branded or unbranded pricing" |
| DecorationJSON order | Default decoration first, then by price at 50 units + setup | `DecJSON - ConcatQuery` |

## Deliberate differences from TOD (to confirm in the comparison)

1. **The staff default decoration always wins.** TOD's "any non-0" and "min non-0" steps run after the LocalPrice default and appear to overwrite it whenever another non-zero decoration exists.
2. **DecorationJSON is built with a JSON serialiser** and checked against the contract schema. Decoration names have line breaks and repeated spaces collapsed. This fixes the 9 products whose JSON TOD left invalid.

## Not yet implemented

- Supplier-specific rules, currently hard-coded queries in TOD:
  - JBs Wear (68): undecorated cost + $2
  - Trends (3): first break set to MOQ 1
  - Shiny (53): everything treated as undecorated; CollectionMOQ of at least 20
  - Logoline (2): type mapping by description words
  - Legend Life: Melbourne Merch prices for up to 19 units
  - CollectionMOQ of 50 for AS Colour, JBs Wear and Legend Life
- EXT price files (AS Colour, Trends, CMAU spreadsheets) and their options.
- Quote pricing (markup tiered by value at $300 and $1,000; sample markup and freight).
- Import of TOD's `DecTypeCode` (17,886 rows) and supplier markups.

## Verification plan

Export from TOD/SQL Server: `dbo_PDS_Supplier`, `DecTypeCode`, `dbo_PDS_CostPriceSummary`, `dbo_PDS_CostPriceBreak`, `dbo_PDS_LocalPrice`, and `dbo_PDS_Products` for the live range. Recalculate the live range here and compare it with TOD product by product: option ids and types, cost and price strings, setup prices, MOQs, collection price, and DecorationJSON.
