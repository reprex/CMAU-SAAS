# Setting up the new website on CMAU_DEV with test data

Goal: the hybrid design (prototype https://claude.ai/artifact/FgD2tiQuR5T6gTXqerD5d6) running on CMAU_DEV against real SaaS data for about 250 test products, without touching live CMAU.

Who does what: **J** = Judd (server), **S** = Scott, **C** = Claude (code, in this repo and the theme repo).

## 0. Unblock the server (J, then S)

1. **J:** fix the ea-php84 command line (it runs PHP 7.4.33); set `session.gc_divisor` above 0 for ea-php84 CLI.
2. **S:** in cPanel Git: Update from Remote, then Deploy HEAD Commit (latest `deploy` branch).
3. **S:** Terminal:
   ```
   cd ~/saas-app && /usr/local/bin/ea-php84 artisan migrate --force
   cd ~/saas-app && /usr/local/bin/ea-php84 artisan config:cache
   ```
4. **S:** check Admin > Promodata sync shows the scheduler heartbeat (cron running).

## 1. Settings in the server .env (S)

Secrets go in the server `.env` only. Then `config:cache` again.

- Already set: Promodata token, `SHOPIFY_DEV_STORE` / `SHOPIFY_SHOP_DOMAIN` (CMAU_DEV), Shopify client id and secret. Keep `SHOPIFY_ALLOW_LIVE=false`.
- `ANTHROPIC_API_KEY` (image check, logo areas, search). Start with `IMAGE_CHECK=true`, `IMAGE_CHECK_PER_DAY=1500`.
- `WEBSITE_QUOTE_ORIGINS` and `WEBSITE_SEARCH_ORIGINS`: the CMAU_DEV storefront address (https://<store>.myshopify.com and its preview domain).
- Turnstile can wait: search runs keyword-only until `TURNSTILE_*` are set.

## 2. Load the catalogue (S)

```
cd ~/saas-app && /usr/local/bin/ea-php84 artisan tod:import
cd ~/saas-app && /usr/local/bin/ea-php84 artisan pricing:patterns --assign
```
Then Admin > Promodata sync > start a full sync (runs in the background over a few hours).

## 3. Pick about 250 test products (S, in Range)

Use Range filters (supplier, product type) and bulk Add. A spread that exercises everything:

| Group | About | Make sure it includes |
| --- | --- | --- |
| T-shirts and polos | 60 | AS Colour 5001 Staple Tee (45+ colours, marles), a JB's polo, a hoodie; one with "front and back" branding |
| Headwear | 40 | trucker and baseball caps, a bucket hat, beanies |
| Bags | 40 | cotton, jute, non-woven totes, drawstring, cooler, backpack |
| Drinkware | 40 | steel bottles, mugs, stubby holders, keep cups |
| Pens and office | 30 | pens, notebooks |
| Odd cases | 10 | a product with no colours (Custom Print), one with a single colour, a discontinued one, one from Legend Life and one from JB's Wear with poor image file names |

Or use **Add a whole supplier** for JB's Wear and Legend Life, as already planned.

## 4. Prepare images, codes and logo areas (S, mostly waiting)

```
cd ~/saas-app && /usr/local/bin/ea-php84 artisan catalogue:tag-images
cd ~/saas-app && /usr/local/bin/ea-php84 artisan catalogue:check-images --product=35681
cd ~/saas-app && /usr/local/bin/ea-php84 artisan catalogue:logo-areas --predict
```
- Look at product 35681's images in Range first; if the tags look right, leave the scheduler to check the rest (250 products take about 1–2 hours).
- Range > Images to check: fix any flagged images.
- Range > Logo areas: Pick 100 samples (25 is plenty for 250 products: use "Add 25 more samples" once), vet them, Apply to the range, check "What was learned".
- Supplier SKUs fill in as stock is checked (Range > product > Live stock > Check now on a few).

## 5. Theme on CMAU_DEV (S, then C)

1. **S:** in CMAU_DEV's Online Store > Themes, add **Galleria 4.1.0** from the Theme Store (unpublished on the dev store is fine).
2. **S:** create the GitHub repo `reprex/CMAU-THEME`, connect it to that theme (Shopify GitHub integration, branch `main`), and add it to this Claude session.
3. **C:** port the current custom templates from the live theme export (colour_as_variant_product, custom collection grid), then build the new templates from the prototype: `page.finder` (step 1), `collection.finder` (step 2 with colour filter and Saved hearts), `product.hybrid` (colours panel, stock box, logo preview, free mockup, front/back), `page.my-list` (saved lists). Assign them to the test products and collections only.

## 6. Publish the test products to CMAU_DEV (S)

Publisher built (docs/publisher.md). In `.env` set `SHOPIFY_PRODUCT_TEMPLATE=hybrid` once the new product template exists (until then leave the default), then `config:cache`.

```
cd ~/saas-app && /usr/local/bin/ea-php84 artisan shopify:metafield-definitions
cd ~/saas-app && /usr/local/bin/ea-php84 artisan shopify:publish --preview --product=43446
cd ~/saas-app && /usr/local/bin/ea-php84 artisan shopify:publish --product=43446
```
Check that product in CMAU_DEV's admin (variants, images, metafields), then publish the rest in batches:
```
cd ~/saas-app && /usr/local/bin/ea-php84 artisan shopify:publish --limit=50
```
Repeat until it says "Nothing to publish."

## 7. Collections and search (S, C)

- Smart collections on CMAU_DEV for the five test groups (by product type tag), with the Search & Discovery filters: colour family (`custom.colourfamily` variant metafield), price, branding.
- Step 1 search box points at `https://saas.replicat.com.au/crowdmerch/search`.

## 8. Test (S)

On phone and desktop: step 1 search and categories, step 2 with colour filter, Saved hearts, product page (all colours panel, live stock, supplier codes, logo preview with your own logo, front and back, curved products), My list (saved lists, exact quantities, clear and undo), free mockup and quote request arriving in the SaaS quote desk (as a draft email, never sent).
