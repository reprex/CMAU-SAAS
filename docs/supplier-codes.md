# Supplier codes on the website

Clients search for supplier codes (AS Colour 5001, JB's 1HT) and quote them to us, and we are usually cheaper than other distributors, so supplier codes are shown, not hidden.

## Where they show

- Product title: unchanged, still ends in `[SupplierID-Code]` (docs/contracts/shopify-product.md).
- Product page (new `product.hybrid` template): the supplier's code next to the name, a line "AS Colour code 5001 · our code 43446" with a Copy button, a "Supplier codes" switch on the stock table, and a list of every colour and size SKU in the page text so search engines index them.
- Collection cards: the supplier code after the name.
- Search box: "Search products or supplier codes".
- Google: the product's structured data (JSON-LD) gives `mpn` = supplier code and, per colour, the supplier SKUs, so a search for a code can find our page.

## Live stock, and where the SKUs come from

Stock levels are always live from Promodata (`check-stock-levels`: colour, size, quantity and supplier SKU for every SKU) and are never stored or summarised:

- CMAU product page: Check stock calls the SaaS relay (`/stock.php?id=APPAID`), which keeps the Promodata token on the server and caches each answer for 5 minutes.
- SaaS: Range > product > Live stock > Check now shows every colour and size with the supplier SKU; the quote desk checks stock per line.

Each live check also keeps the SKU codes it returned (codes only, no quantities) in `product_skus`, so the product page text can list every supplier SKU for search engines. No extra calls are made to collect them; a product gets its list the first time anyone checks its stock. A changed SKU list marks the product for publishing.

Products whose supplier doesn't support stock checks have only the product code.

## What Shopify gets (`SupplierCodes::forShopify`)

- `custom.suppliercode`: supplier name and code, e.g. `AS Colour 5001`.
- `custom.supplierskus` (json): `[{"colour":"Black","size":"M","sku":"5001-BLK-M"}, ...]`, hidden colours left out. No quantities: those change daily and come live from the stock check.
