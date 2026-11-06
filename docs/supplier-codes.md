# Codes on the website

## What clients see (Scott, 1 Nov 2026; this reverses the earlier "show supplier codes" decision)

- **Our code** is `SupplierID-Code`, e.g. `3-104743`, as already at the end of every product title (`[SupplierID-Code]`, docs/contracts/shopify-product.md). **Our SKU** is our code + colour + size, e.g. `7-5001-BLAC-M` (`App\Catalogue\OurCodes`; colour = its first 4 letters, or the first 2 letters of each of its first two words).
- **The supplier's name is never shown to clients**, anywhere.
- **Product page:**
  - the **title chip** beside the product name may show the **supplier's own product code** (e.g. `5001`, `104743`), from `custom.supplierproductcode`;
  - the **line under the title** shows **only our code**: "Our code 3-104743" (with Copy), from `custom.ourcode`. No supplier name or supplier code in that line.
- **Everywhere else client-facing** shows our code only, never the supplier's code or name: collection cards, the stock table, the SaaS checkout replies (`crowdmerch/checkout/*`), the invoice PDF, the emails, My Orders and samples (docs/website-checkout.md, rule 10).
- **Quotes** (6 Nov 2026): the quote PDF and the product comparison PDF print our code ("Code 3-104743", `QuoteItem::ourCode()`), never the supplier's code or name. A line with no supplier prints no code (the bare supplier code is never shown on its own). The comparison's intro, taken from the supplier's description, leaves out any sentence that names the supplier. The quote emails carry no codes. Tests: `QuoteDeskHttpTest::test_client_quote_outputs_show_our_code_never_the_suppliers_name_or_code`, `WebsiteSamplesTest` (invoices).
- **Staff** see everything: supplier name, supplier code and supplier SKUs, on Range, the quote desk and Website orders. Each quote desk line also shows "our code 3-104743": use that, not the supplier's code, when writing to a client. Order lines keep the supplier code and SKU for ordering from the supplier.

## Live stock, and where the SKUs come from

Stock levels are always live from Promodata (`check-stock-levels`: colour, size, quantity and supplier SKU for every SKU) and are never stored or summarised:

- CMAU product page: Check stock calls the SaaS relay (`/stock.php?id=APPAID`), which keeps the Promodata token on the server and caches each answer for 5 minutes.
- SaaS: Range > product > Live stock > Check now shows every colour and size with the supplier SKU; the quote desk checks stock per line.

Each live check also keeps the SKU codes it returned (codes only, no quantities) in `product_skus`. No extra calls are made to collect them; a product gets its list the first time anyone checks its stock. A changed SKU list marks the product for publishing.

Products whose supplier doesn't support stock checks have only the product code.

## What Shopify gets

| Metafield | Value | The theme |
| --- | --- | --- |
| `custom.ourcode` | Our code, e.g. `3-104743` (new, additive) | **Shows it** under the title ("Our code 3-104743") |
| `custom.supplierproductcode` | The supplier's product code alone, e.g. `104743` (new, additive) | May show it as the title chip |
| `custom.suppliercode` | Supplier name and code, e.g. `AS Colour 5001` (`SupplierCodes::forShopify`, unchanged) | **Must not display it** (it contains the supplier name). Kept for Google `mpn` and search |
| `custom.supplierskus` | `[{"colour":"Black","size":"M","sku":"5001-BLK-M"}, ...]`, hidden colours left out, no quantities (unchanged) | **Must not display it.** Kept for Google (the JSON-LD per-colour SKUs) and search |

- Google: the product's structured data (JSON-LD) may still give `mpn` = the supplier code and the supplier SKUs, so a search for a supplier code can find our page. That is metadata for search engines, not shown on the page.
- Search box: clients can still search by supplier code ("Search products or codes"); results show our code.
- `custom.suppliercode` and `custom.supplierskus` were left unchanged because docs/contracts/shopify-product.md fixes existing fields; the theme simply stops displaying them, and uses the two new fields instead.
