# Shopify publisher

`shopify:publish` sends web-range products to the CMAU Shopify store with the GraphQL `productSet` mutation: the product, its colour variants, images and metafields in one call per product (two on a product's first publish). It writes only to the development store (CMAU_DEV) unless `SHOPIFY_ALLOW_LIVE=true`.

## What gets sent

`App\Shopify\ProductPayload` builds the product in the shape the CMAU theme reads (docs/contracts/shopify-product.md):

- handle = APPAID; title "name [SupplierID-Code]" (staff web title first); body: description, sizes, dimensions, accessory options and specifications; type `Product`; tags (`style`, brand, label, product type, Promodata attributes, `external` above 10,000,000, staff `extra_tags`); status Active, or Archived when off the range or discontinued; template suffix `SHOPIFY_PRODUCT_TEMPLATE` (default `colour_as_variant_product`; use `hybrid` for the new templates on CMAU_DEV); SEO title "Custom Embroidered/Printed/Engraved name [code]" (70 characters at most).
- Variants: one per visible colour (Color option; "Custom Print" when there are none), SKU `APPAID-colour`, price = collection price, colour image, `custom.productmoq`, `custom.altimagepublicpath`, and new `custom.altimages`, `custom.colourfamily`.
- Images: hero style, other style, then colour images (docs/images.md); hidden images left out.
- Product metafields: `decorationjson`, `collectiondisplayqty`, `collectiondisplayprice`, `mastersortorder`, the `tag*` fields, the subcategory fields, `stockcolumns`, `nostockmessage`, `my_fields.pdpcollectionname`, and new `suppliercode`, `supplierskus`, `printarea`, `brandingpositions`, `brandingtemplate`, plus (2 Nov 2026) `leadtimedays` (fastest lead time in working days, beside the sizes) and (1 Nov 2026) `ourcode` (our code `SupplierID-Code`, shown under the title) and `supplierproductcode` (the supplier's code alone, for the title chip). The theme must not display `suppliercode` or `supplierskus` (docs/supplier-codes.md).
- `printarea` and `brandingpositions` now come for every product with decoration options, without vetting: staff-approved area, then the supplier's template, then Promodata's decoration print areas, then the type default (docs/logo-preview.md). `brandingpositions` gains the additive keys `method_areas` and `source`.

**TOD's tagging** (category, special and collection tags, the essentials/urgent/ecorec subcategories, the under-MOQ text, the PDP collection name, product tags such as `urgent` and brand ranges, and the master sort order) is carried over from the live store by `shopify:carry-tags` (7 Nov 2026, below). Products without it get values worked out from the Promodata type: category = the part before ">", subcategories = the part after it, PDP collection name = the subcategory tag; `any,` where there is nothing to work out.

## How it behaves

- **Only changes are sent.** A hash of the last payload is kept; unchanged products are skipped (`--force` sends anyway). Products are picked when `range_changed_at` is newer than `published_at` (curation, repricing, image tags, logo areas and SKU changes all set it), or when a published product left the range (archived).
- **Images are merged, never re-uploaded.** Shopify media ids are stored per image URL (`shopify_media`); later publishes reference them by id. A first publish uploads the images, then links each colour variant to its image in a second call. Media already on the product are kept.
- **Plain updates go five at a time.** An update that uploads no new picture is sent with the other plain updates in the run, `--parallel` (5) at once, and without asking for the picture list back: `media(first: 250)` makes Shopify reserve ~260 points per call, and the Basic plan allows 100 points a second. A plain update costs ~10–20 points. Calls Shopify throttles are sent again after a pause. New products and new pictures still go one by one (upload, then link). An unchanged product flagged as changed (e.g. after a TOD import) is marked in sync without a call.
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

## Publishing the whole range (3 Nov 2026)

- `shopify:range-report`: the web range, how much is priced and fully detailed, on the store, waiting, failed in the last day, photos still to check for white backgrounds, and the variants the range needs (one per colour) against Shopify's limit: stores over 50,000 variants can only create 1,000 new variants a day (except Shopify Plus).
- `shopify:auto-publish on|off`: when on, the minute cron runs `shopify:publish --limit=250 --seconds=45 --retry-after=60 --parallel=5`: new or changed products for 45 seconds a minute, failed ones retried after an hour (they don't block the queue). Shopify's daily new-variant limit pauses it for 6 hours. Once the range is up it keeps the store in step with range changes (curation, new heroes, price changes).

## Range collections (3 Nov 2026)

- Every product carries its **subcategory tag** in the TOD style: the part after ">" in the Promodata type, "Other " dropped, letters and digits only ("Bags > Duffle/Sports Bags" -> `dufflesportsbags`, "Other Food & Drink" -> `fooddrink`). The `tagsubcategory` metafield carries the same tag.
- `shopify:collections`: lists the store's collections and the range's product types. `--sync` matches each type to the store's smart collection "TAG EQUALS <tag>" (by tag, then by title) and shows the missing ones; `--sync --create` creates them like the existing ones (handle `custom-printed-<tag>`, manual sort, template `col_as_variant`, on the Online Store). Matches are kept in `shopify_collections`, and a matched collection's own tag wins over the computed one.
- `--sort` (every 30 minutes while automatic publishing is on): sets each collection's manual order: staff sort order (`master_sort_order`), then the cheapest at the display quantity, then products with colour photos, then name.

- **Order (3 Nov 2026):** products never published go first, so the range finishes before republishes of products already on the store.

## Health check (Admin › Shopify publishing)

Built 3 Nov 2026 so nobody needs the terminal to see how the upload is going (`PublishHealth`, from `shopify_publish_log`): status and headline, products on the store, new to upload, changes to send, to archive, the rate over the last 15 minutes and a finish estimate, a per-minute chart for the last hour, an hourly table for the last day, and failures not since fixed with Shopify's message. The page refreshes every minute and has the automatic publishing on/off switch (same as `shopify:auto-publish`).

Problems it flags: the minute cron not running (no scheduler heartbeat for 3 minutes); publishing on, products waiting, and nothing uploaded for 10 minutes (stalled); most uploads in the last 15 minutes failing; paused by Shopify's daily new-variant limit. When on and there is a problem, admins see a red banner at the top of every SaaS screen (cached a minute).

## Switching to the live store (7 Nov 2026)

The SaaS has only ever published to CMAU_DEV, so every stored Shopify id (products, pictures, collections) is a CMAU_DEV id. They are **bound to their store** (`app_settings` `shopify.ids_store`, recorded as the dev store): while `.env` points at another store, every Shopify write is refused, the cron publishing pauses and Admin › Shopify publishing shows a red headline. Nothing here calls Shopify unless stated.

Runbook (in this order):
0. Deploy the release **before** changing `.env`. Turn automatic publishing off (`shopify:auto-publish off`) and keep it off. Stop TOD's Matrixify pushes to live from the export on (later changes would be overwritten).
1. **Export the live store with Matrixify** (CSV is lighter than .xlsx): Products with Basic columns incl. Status, Variants (Option1 Name, Option1 Value, Variant SKU, Variant ID), Tags and Metafields (custom.tag*, custom.*subcat, custom.undermoqtext, custom.mastersortorder, my_fields.pdpcollectionname). Upload it to `~/saas-app/storage/app/private/`.
2. **Reports (read-only)**:
   - `shopify:variant-match <file>`: products on both / new / live only; archived on live but on the range (they'd be made Active); options not named Color (every variant would be replaced); the same `[SupplierID-Code]` under another handle (a duplicate would be created); per colour: kept, renamed, added, removed; SKUs that differ. CSV of every row in `storage/app/private/variant-match/`. Live SKUs already follow the SaaS rule (all 16,869 in the 19 Jul reload match).
   - `shopify:carry-tags <file>` (report), then `--apply`: keeps the live tagging (above, including `custom.tagsubcategory`). A staff sort order wins. A later TOD import keeps these. Carried tags are added to the SaaS's own, so a product keeps every collection tag it has on live.
3. **Switch**: `SHOPIFY_SHOP_DOMAIN` (live), the live app's client ID and secret, `SHOPIFY_ALLOW_LIVE=true`, `SHOPIFY_PRODUCT_TEMPLATE` = the template the theme going live uses (not `hybrid` unless that is it), `config:cache`. Wait 2 minutes, then `shopify:switch-store` (report) and `--apply` (refused while automatic publishing is on). CMAU_DEV's ids are kept aside in `shopify_store_archive` (switching back restores them) and cleared.
4. `shopify:metafield-definitions` (definitions the SaaS no longer uses are left alone unless `--remove-old`), `shopify:collections --sync` (`--create` only for missing ones).
5. `shopify:publish --limit=50`; check those products on the store, and compare their variant ids with the export's Variant ID column (proves colours keep their variants). Then `shopify:auto-publish on`.

**Taking over live products (adopt).** After a switch to a store with no kept ids, each product is looked up on the store **by its handle (APPAID)** before its first publish (one read query): its Shopify id is taken over (product id and URL kept) and its pictures are matched to the SaaS's by file name (Shopify keeps the uploaded name, adding a suffix on repeats) and kept by id, so they aren't uploaded twice; pictures only the store has are replaced. **The SaaS's data wins over the live store's** (Scott, 7 Nov 2026): Promodata's colour names and pictures, even where a variant is replaced; a variant used in a past sale can be re-quoted. Kept from live: the product id and URL, TOD's tagging (collections and filters depend on it), and, only for a product Promodata lists no colours for, its live colours. Logged as `adopt` in `shopify_publish_log`. A product not found is created. A colour keeps its variant when its name is unchanged.

Not done automatically (Scott decides): products on live that aren't on the SaaS range stay as they are (variant-match lists them), and products the SaaS has dropped are only archived once they have a Shopify id (taken over or published).
