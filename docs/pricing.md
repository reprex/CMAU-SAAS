# Pricing engine

Code: `app/Pricing`. Command: `php artisan pricing:recalculate [product] [--supplier=]`.

The engine reproduces TOD's CostPriceBreak > CostPriceSummary > web fields > DecorationJSON chain. The rules below come from TOD's queries and VBA. Each one was then checked against TOD's stored results for the whole live range (see [Verification against TOD](#verification-against-tod)).

## Rules

| Step | Rule | TOD source |
| --- | --- | --- |
| Option id | `APPAID-` + the description in lower-case letters and digits | `AlphaNumericOnly`, CostPriceBreak TextPDID |
| Merge | Price lines with the same code merge; the first line's setup and description are kept | CostPriceSummary insert |
| Classification | The code is looked up in `dec_type_codes`. New codes are added unclassified for staff to review | `DecTypeCode`, "CPS - set CPS TypeCode" |
| Unit markup | Set by the DecTypeCode type: 10 uses `markup_decoration`, 12 uses `markup_undecorated`, anything else (including unclassified) uses `markup_accessory` | "update prices to UnDec Dec Acc markups" |
| Unit price | cost × markup, kept to 4 decimal places, then rounded **up** to the cent | `GlblRoundup(x, 2)` |
| Setup price | setup cost × `markup_setup`, not rounded | same query |
| Option type | A staff setting wins (a "default" or "other" decoration flag means type 10), then a supplier rule, then DecTypeCode. Changing the type never changes the markup | LocalPrice DecType, "set LocalPrice DecType for DefaultDec OtherDec flags", supplier macro |
| Placeholders | "Unbranded" `1:0` when there is no type 12 option. "Product is only available unbranded" `1:0` when there is no type 10 option and the decoration product is a Promodata product (id < 999999). Neither is added for AS Colour (88) | "create 1:0 0 unbranded" queries |
| Price at quantity | Uses the largest break at or below the quantity. Below the minimum, the price is (minQty × minPrice) ÷ qty | `GetPrice` |
| Decoration source | MANUAL (staff pick), then EXTERNAL (borrowed from another product, e.g. 10001999), then AUTO | "set DecSource based on Man Ext Auto hierarchy" |
| Default decoration | Staff pick; for decorations borrowed from a price-file product, its first-listed option; else the staff default; else the cheapest non-zero decoration cost at 100. Never the zero-price placeholder | "set product DefaultDecCPSID ..." queries |
| ActualMOQ | max(undecorated MOQ, default decoration MOQ), unless staff set an override | "set product ActualMOQ" |
| CollectionMOQ | Staff/price-file override, else the supplier rule, else ActualMOQ | "set CollectionMOQ" queries |
| Order value | decoration price × qty + undecorated setup + undecorated price × qty, **plus the decoration setup when the product uses its own decorations** (`pricing.order_value_decoration_setup`) | stored TOD values; see below |
| Collection price | order value at CollectionMOQ ÷ CollectionMOQ | "set product CollectionVariantPrice" |
| BrandFlag | "includes branding" whenever any type 10 option exists, **including** the placeholder | "flag branded or unbranded pricing" |
| DecorationJSON order | By display group (`json_sort`, default 2; 1 = list first; price files set their own order), then by price at 50 units + setup, then by descending option id | `DecJSON - ConcatQuery` |
| DecName | Commas removed; other spacing kept as TOD has it; line breaks become one space | `DecJSON - ConcatQuery` |

## Supplier rules

These are in `config/pricing.php` (`supplier_rules`), not hard-coded queries:

| Supplier | Rule |
| --- | --- |
| Logo Line (2) | Types from descriptions: "Undecorated" → 12, "ADD…" → 10, "…Variable…" → 11, anything else → 23 (hidden). "ADD" and "Variable" apply to priced options only |
| Shiny (53) | Every option is type 12 **and priced at the undecorated markup** (blank garments; decoration comes from another product); CollectionMOQ at least 20 |
| JB's Wear (68) | Undecorated cost + $2, rounded to the **nearest** cent (Access `Round`), not up; CollectionMOQ 50 |
| AS Colour (88) | CollectionMOQ 50; prices come from its price file (EXT options) |
| Trends (3) | Products on Trends' "MOQ 1" list start every option at quantity 1 (`moq_one` product override). Their collection quantity comes from Trends' file (`collection_moq` override) |

## Verification against TOD

`php artisan tod:compare-pricing <folder>` imports TOD's pricing exports and prices the live range. It then compares every option and product field with TOD's stored values and writes a CSV of each difference. The exports come from the `ExportPricingTables` VBA:

- `dbo_PDS_Supplier`
- `DecTypeCode`
- `dbo_PDS_Products`
- `dbo_PDS_CostPriceBreak`
- `dbo_PDS_CostPriceSummary`
- `dbo_PDS_LocalPrice`

`--tod-defaults` pins each product's default decoration to TOD's pick, so everything downstream can be checked on its own.

Result for the live range on 24 Oct 2026 (4,991 products, 37,026 options), with `--tod-defaults`:

| Field | Same as TOD | Different |
| --- | --- | --- |
| Option types, cost strings, setup prices | 100% | 0 |
| Price strings | 36,821 | 205 (stale in TOD, see below) |
| Undecorated option, decoration product, CollectionMOQ, BrandFlag | 100% | 0 |
| Order values and collection price | 4,990 | 1 |
| ActualMOQ | 4,979 | 12 (unbranded-only products, see below) |
| DecorationJSON | 4,667 | 295 tie order, 20 stale prices; 9 invalid in TOD |
| Accessory list | 4,957 | 34 (33 are order only) |

### Where TOD's stored values are not reproducible

1. **Stale prices (205 options, Trends and Logo Line).** TOD only reprices products whose supplier data changed. Options whose DecTypeCode was later changed to 10 still carry the old 1.45 markup. The engine prices them at today's classification, so they rise by about 3.4%.
2. **Default decoration for own decorations (about 1,460 products).** TOD sets the default in three update queries: staff default, then "any non-0", then "min non-0". Each later query overwrites the one before, and the outcome depends on Access's internal row order and on values left over from earlier runs. The cheapest-decoration rule matches 63% of TOD's picks; simulating the row order matches 77%. Nothing simpler reproduces the rest. See "Decisions" below.
3. **Order-value setup.** The current TOD queries leave the decoration setup out, but the stored values include it whenever the product uses its own decorations (3,904 products) and leave it out when decorations are borrowed (894 products). This holds for every product. The engine reproduces the stored values.
4. **Tie order in DecorationJSON (295 products).** When decorations have the same group and price, Access lists them in no stable order. Descending option id matches 86% of these products.
5. **Unbranded-only products (12).** TOD leaves ActualMOQ empty because its `IIf` compares with Null. The engine uses the undecorated MOQ.

### Decisions for Scott

- **Default decoration at cutover:** either (a) carry TOD's current picks over as staff choices (no visible change; new products use the cheapest rule), or (b) use the cheapest rule now (991 collection prices change, 938 down and 53 up; median −2.7%, range −53% to +53%).
- **Order-value setup:** keep TOD's "own decorations only" behaviour (no visible change), or include the setup for borrowed decorations too.
- **Stale prices:** accept the reprice of the 205 options, or set their DecTypeCode back.

## Deliberate differences from TOD

1. **DecorationJSON is built with a JSON serialiser** and checked against the contract schema. This fixes the 9 products whose JSON TOD left invalid.
2. **Unbranded-only products get an ActualMOQ** (the undecorated MOQ) instead of none.

## Not yet implemented

- Legend Life (57): Melbourne Merch prices for up to 19 units. No live products use this rule.
- Reading EXT price files (AS Colour, Trends, Melbourne Merch). Their options are imported from TOD for now.
- Quote pricing (markup tiered by value at $300 and $1,000; sample markup and freight).
