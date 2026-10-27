# Requirements summary

Source: review of the TOD dev copy (`SOD (TOD to SAAS) dev.mdb`, 23 Oct 2026) and of past Matrixify uploads. The full document with diagrams is at https://claude.ai/artifact/VhmB9Q9PXU2CtbBBGodLq9.

## Decisions

- Full SaaS rebuild rather than extending TOD.
- Shopify is updated through its Admin API, not Matrixify. This is slower but involves far less manual work.
- Formstack is retired.
- The CMAU website (PLP, PDP and wish list Liquid) stays exactly as it is.
- Code lives in `reprex/CMAU-SAAS`. Theme work is tested on the CMAUDEV development store.

## What TOD does today

| Stage | Tables | Job |
| --- | --- | --- |
| PK | `tblPd*` | Raw Promodata import, refilled per supplier or per product |
| PDA | `dbo_PDA_*` | Full versioned copy for every pull, later purged back to the latest version |
| PDS | `dbo_PDS_*` | Curated catalogue: categories, prices, image mapping, staff overrides |
| PDW | `dbo_PDW_*`, `TempTags` | Web prep: tags, collections, Matrixify XLSX |
| Quotes | `dbo_PD_Quote*`, `dbo_PD_StatusLog`, `dbo_PD_Receipt` | Quote desk; the TODROD macro creates ROD orders |

Scale: about 64,000 Promodata products are tracked and about 5,000 are live on the web (4,991 in the 19 Jul 2026 full reload, with 16,871 colour variants).

## Modules

1. Supplier catalogue sync (swappable source: Promodata first, supplier APIs later)
2. Supplier and rules admin (markups, DecTypeCode, subcategories, brand/label, colour families)
3. Range curation (add or remove products and colours, overrides, hero images)
4. Pricing engine
5. Tagging and collections
6. Shopify publisher (Admin API, changed products only, preview, run log)
7. Quotes and orders (draft order intake, statuses, emails, PDFs, receipts, supplier invoices)
8. Contacts and ROD hand-off

Cross-cutting: staff logins and roles (admin, sales, read-only), an audit trail, background jobs with run logs, and per-supplier rules held as data instead of hard-coded queries.

## Pricing rules to reproduce

- Each supplier price line is classified through `DecTypeCode` as one of:

  | Type | Meaning |
  | --- | --- |
  | 10 | Decoration |
  | 11 | Accessory |
  | 12 | Undecorated |
  | 23 | Hidden |

- Unit sell price = cost × the supplier markup for that type. The markups are `SupplierMarkupDec` (default 1.50), `SupplierMarkupAcc` (default 1.45) and `SupplierMarkupUnDec` (default 1.45). Setup price = setup cost × `SupplierMarkupSetup`.
- Price strings use the format `qty:unit` (for example `1:0.67,500:0.66`). A zero `Unbranded 1:0` placeholder is created when a product has no undecorated option.
- Supplier overrides apply on top: JBs Wear +$2, Trends MOQ 1, Shiny, Logoline, the Melbourne Merch decoration (999) and Legend Life. EXT price files (AS Colour, Trends, CMAU) are also applied.
- The live decoration is chosen in this order: MANUAL, then EXTERNAL, then AUTO. AUTO picks the LocalPrice default, or otherwise the cheapest non-zero decoration.
- MOQ and display values:
  - ActualMOQ = max(undecorated MOQ, default decoration MOQ), unless an override is set.
  - Collection display price = order value at the display quantity ÷ CollectionMOQ.
- Quote markups are tiered by line value: up to $300, $301 to $1,000, and over $1,000. Samples use their own markup plus freight.

## Categorisation and tags

- **Subcategory:** taken from the Promodata product type, then the supplier subcategory, then the category plus "Misc", then `unknown`. Staff can override it with MoveSubCat.
- **Brand, label and catalogue:** matched against strings in `APPA Brand Label Match Strings`.
- **Colour family:** matched against 3,995 match strings, giving 44 families.
- **Tags:** cat, subcat, branding (from the DecTypeCode flags), collection, special and colgroup (keyword lists). The tag keyword lists move into an editable rules table.

## Compact storage

| Tier | Holds |
| --- | --- |
| Live | Products on the web range: the full record |
| Active | Products on a quote, order or saved list, or opened in the last 12 months: the full record |
| Summary | One row per product: the identity fields, hero URL, colour count, lowest cost, MOQ, last-changed date and a content hash |
| Not stored | Raw API payloads and old versions |

Staff overrides survive a product dropping to the Summary tier. A nightly changed-since sync keeps the data current; products are fetched on demand when staff open them.

## Quotes

- Shopify draft orders, received by webhook, become quotes using the mapping in the full document. TOD never built this step.
- Statuses: SAVELIST, PHONE, QUOTE, ORDER, PROOF SENT, PROOF OK, followed by shipped, invoiced and paid.
- Website features that send data back:
  - Instant Quote PDF via Email
  - Save List For Later / Your Projects
  - Place Order Request (still in development)

## Open questions

- Host: which database engine, web hosting and public image storage?
- ROD: integration approach.
- Should open draft orders be imported at cut-over?
- A copy of the CMAU theme, to use as the test reference.
- How do the website forms submit, and where are saved lists stored?
- Is the 12-month Active window right?
- Users and roles.
