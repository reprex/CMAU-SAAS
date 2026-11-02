# Shopify publisher

`shopify:publish` sends web-range products to the CMAU Shopify store with the GraphQL `productSet` mutation: the product, its colour variants, images and metafields in one call per product (two on a product's first publish). It writes only to the development store (CMAU_DEV) unless `SHOPIFY_ALLOW_LIVE=true`.

## What gets sent

`App\Shopify\ProductPayload` builds the product in the shape the CMAU theme reads (docs/contracts/shopify-product.md):

- handle = APPAID; title "name [SupplierID-Code]" (staff web title first); body: description, sizes, dimensions, accessory options and specifications; type `Product`; tags (`style`, brand, label, product type, Promodata attributes, `external` above 10,000,000, staff `extra_tags`); status Active, or Archived when off the range or discontinued; template suffix `SHOPIFY_PRODUCT_TEMPLATE` (default `colour_as_variant_product`; use `hybrid` for the new templates on CMAU_DEV); SEO title "Custom Embroidered/Printed/Engraved name [code]" (70 characters at most).
- Variants: one per visible colour (Color option; "Custom Print" when there are none), SKU `APPAID-colour`, price = collection price, colour image, `custom.productmoq`, `custom.altimagepublicpath`, and new `custom.altimages`, `custom.colourfamily`.
- Images: hero style, other style, then colour images (docs/images.md); hidden images left out.
- Product metafields: `decorationjson`, `collectiondisplayqty`, `collectiondisplayprice`, `mastersortorder`, the `tag*` fields, the subcategory fields, `stockcolumns`, `nostockmessage`, `my_fields.pdpcollectionname`, and new `suppliercode`, `supplierskus`, `printarea`, `brandingpositions`, `brandingtemplate`, plus (2 Nov 2026) `leadtimedays` (fastest lead time in working days, beside the sizes) and (1 Nov 2026) `ourcode` (our code `SupplierID-Code`, shown under the title) and `supplierproductcode` (the supplier's code alone, for the title chip). The theme must not display `suppliercode` or `supplierskus` (docs/supplier-codes.md).
- `printarea` and `brandingpositions` now come for every product with decoration options, without vetting: staff-approved area, then the supplier's template, then Promodata's decoration print areas, then the type default (docs/logo-preview.md). `brandingpositions` gains the additive keys `method_areas` and `source`.

**Not yet produced** (TOD's tagging tables; the "tagging and collections" module): category codes, special and collection tags (sent as `any,`), essentials/urgent/ecorec subcategories (sent as the product type), the master sort order (99999 unless overridden), the under-MOQ text. Fine for CMAU_DEV testing; needed before live.

## How it behaves

- **Only changes are sent.** A hash of the last payload is kept; unchanged products are skipped (`--force` sends anyway). Products are picked when `range_changed_at` is newer than `published_at` (curation, repricing, image tags, logo areas and SKU changes all set it), or when a published product left the range (archived).
- **Images are merged, never re-uploaded.** Shopify media ids are stored per image URL (`shopify_media`); later publishes reference them by id. A first publish uploads the images, then links each colour variant to its image in a second call. Media already on the product are kept.
- **On the Online Store channel.** `productSet` puts a product on no sales channel, so after each create or update the publisher calls `publishablePublish` for the Online Store publication. The app needs the scopes `read_publications` and `write_publications`; without them the publish still succeeds and the log says the channel step was skipped.
- **Logged.** Every attempt goes in `shopify_publish_log` with Shopify's error text.

## Commands

```
cd ~/saas-app && /usr/local/bin/ea-php84 artisan shopify:metafield-definitions
cd ~/saas-app && /usr/local/bin/ea-php84 artisan shopify:publish --preview --product=43446
cd ~/saas-app && /usr/local/bin/ea-php84 artisan shopify:publish --product=43446
cd ~/saas-app && /usr/local/bin/ea-php84 artisan shopify:publish --limit=50
```

- `shopify:metafield-definitions` once per store: creates definitions for the new fields (needed for the colour family filter). Run it again after this change for `ourcode` and `supplierproductcode`.
- `--preview` writes what would be sent to `storage/app/publish-preview/<id>.json` and sends nothing.
- Run in batches of 50 for the first load (about a minute each); it is not scheduled yet.
