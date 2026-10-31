# Promodata sync and range curation

Built 31 Oct 2026. Requirements: docs/requirements.md (modules 1–3) and REPSTACK "ROD To SaaS / matrixify-export-assessment.md" (PC20: shared curation ideas where the shapes match; CMAU keeps its fixed DecorationJSON contract).

## Screens

| Screen | Who | What |
| --- | --- | --- |
| **Promodata sync** (Admin) | admin | Connection test; scheduler heartbeat; product counts by tier; the running sync with progress and Stop; start a "changes since" sync, a one-supplier sync or a full sync; fetch one product by APPAID now. |
| **Range** | everyone (changes: admin, sales) | Search the whole catalogue by name, supplier code or APPAID; filter by supplier and Promodata product type. Views: on the range, changed but not published, needs attention, recently used, whole catalogue. Tick products to add, remove, refresh from Promodata or reprice. |
| **Range → product** | everyone (changes: admin, sales) | Web price summary, price options (cost and sell breaks), per-product type and DecorationJSON group settings, DecorationJSON preview, curation overrides, history. |
| **Decoration types** (Admin) | admin | TOD's DecTypeCode: classify each supplier price-line description as 10 decoration, 11 accessory, 12 undecorated or 23 hidden, with optional web wording. Saving reprices every product that uses it. |

## How the data flows

1. **Sync.** Every Promodata product gets a one-row summary. Products on the range (live) or used in the last 12 months (active) keep full detail: colours, images, price lines. The scheduler processes `PROMODATA_PAGES_PER_STEP` pages a minute; the nightly 1:30 run fetches changes since the last completed run.
2. **Reprice on change.** When a sync changes a live or active product, a background job rebuilds its price options and web price (DecorationJSON). If the web-facing values change on a live product, it is marked "to publish".
3. **Add to range.** Fetches the product from Promodata if only the summary is stored (one API call), sets it live and prices it. One product runs immediately; ticked batches go to the queue, about 25 a minute.
4. **Remove from range.** Sets it back to active. Details and overrides are kept; it drops to summary after 12 months unused.
5. **New decoration descriptions** arrive unclassified. They are priced with the accessory markup and left out of DecorationJSON until classified (the product page shows a warning and a link).

## Curation overrides (`product_overrides.data`)

| Key | Effect |
| --- | --- |
| `title`, `description` | Web title and description instead of Promodata's (used by the publisher) |
| `subcategory` | Web subcategory instead of the Promodata product type |
| `hero_image_url` | Main image; must be one of the product's own image URLs |
| `hidden_colours` | Colour names not to publish |
| `notes` | Staff notes, never published |
| `decoration_option_id` | Default decoration (TOD "MANUAL") |
| `decoration_product_id` | Use another product's decorations (TOD WebDecLiveAPPAID, e.g. a decorator's price file) |
| `actual_moq`, `collection_moq` | Minimum order and the quantity the collection price is shown at |
| `moq_one` | First break quantity set to 1 (TOD's MOQ-1 list) |
| `carried_default_decoration_option_id` | Set by the TOD import only; keeps TOD's default pick so prices don't change at cut-over |

Per-option settings (`price_option_settings`): a type override for this product only, and the DecorationJSON group (1 first, 2 normal).

Every change is in the audit log, and stamps `range_changed_at` on live products so the Shopify publisher (next module) can send changed products only. `published_at` is set by the publisher.

## One-off: bring TOD's range across

Stack principle 2: only what the module needs. The import takes suppliers and their markups, decoration type classifications, the live web range (and the decoration products it borrows from), staff price settings and product overrides. No customer data.

1. On the office PC, run TOD's ExportPricingTables VBA. It writes six files: `dbo_PDS_Supplier.tsv`, `DecTypeCode.tsv`, `dbo_PDS_Products.tsv`, `dbo_PDS_CostPriceBreak.tsv`, `dbo_PDS_CostPriceSummary.tsv`, `dbo_PDS_LocalPrice.tsv`.
2. cPanel → File Manager: make the folder `/home/saasreplicat584/tod-import` (outside `saas-app`, so never web-visible) and upload the six files.
3. Terminal:
   ```
   cd ~/saas-app && /usr/local/bin/ea-php84 artisan tod:import /home/saasreplicat584/tod-import --refresh
   ```
   It prints the counts, prices every product and, with `--refresh`, queues a Promodata refresh of every live product (current supplier costs replace TOD's snapshot, about 25 products a minute).
4. Delete the six files from `tod-import` afterwards.

Then start a **full sync** on the Promodata sync screen to load the rest of the catalogue as summaries (a few hours in the background).

## Commands

```
cd ~/saas-app && /usr/local/bin/ea-php84 artisan promodata:check            # test the token, read-only
cd ~/saas-app && /usr/local/bin/ea-php84 artisan promodata:check --product=25384
cd ~/saas-app && /usr/local/bin/ea-php84 artisan tod:import /home/saasreplicat584/tod-import --refresh
cd ~/saas-app && /usr/local/bin/ea-php84 artisan pricing:recalculate        # reprice all live/active products
```

## Not yet

- Shopify publisher (module 6): preview and diff, publish changed products only, store Shopify IDs, history and rollback (matrixify-export-assessment PC09–PC14).
- Tagging and collections (module 5), colour families, brand/label matching.
- Supplier markups and rules screen (module 2); markups come from the TOD import until then.
