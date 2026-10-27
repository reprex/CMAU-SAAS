# Quote desk (stage 1)

Built 25 Oct 2026 on branch `feat/quotes`. Replaces TOD's quote screens and, when switched over, the website's quote and wish-list calls to app.crowdmerch.com.au.

## What it does

| Area | Where |
| --- | --- |
| Sign-in with roles (admin, sales, read-only), authenticator-app codes on every account, audit log | `app/Http/Controllers/Auth`, `RequireTwoFactor`, `RequireRole`, `AuditLog` |
| Supplier standard freight, admin only, with history | `Admin/SupplierController`, `suppliers.freight_*`, `supplier_freight_rates` |
| Shopify draft orders in: webhook, 5-minute pull, or a JSON file | `DraftOrderIntake`, `DraftOrderParser`, `shopify:pull-drafts`, `quotes:import-drafts` |
| Split: as received → product + decoration → SKUs (size × qty) | `ItemSplitter`, `quote_items.stage`, `quote_item_skus` |
| Live stock from Promodata, cached 5 minutes | `PromodataStock` |
| Range products, other quantities, custom products, services, information lines | `QuoteItemController`, `QuoteExtraController`, `ItemPricer` |
| Freight lines and totals | `QuoteCalculator` |
| Quote PDF (MR…) and product comparison PDF (PS…) | `QuotePdf`, `resources/views/pdf` |
| Stock relay for the CMAU product page | `/stock.php?id=`, `StockRelayController` |
| Website quote API (Quick Quote, wish lists) | `/crowdmerch/homepage/*`, `WebsiteQuoteController` |

## How a website request becomes a quote

1. The client asks for a quote on the website. A Shopify draft order is created. Each line combines product and decoration, with the split in line properties: `undecunit`, `undecsetup`, `decunit`, `decsetup`, `decdesc`, `decID`.
2. The draft arrives by webhook, or by the 5-minute pull as a backup. It is stored as received in `shopify_draft_orders`.
3. Drafts with an email address become quotes with status NEW and number MR<next>.
   - Drafts without an email are anonymous wish lists. They are counted, and shown under **Wish lists & intake**, but they don't become quotes unless staff open one. Most of them were automated traffic: 1,785 in September 2026, covering 1,595 products.
4. Each line becomes an item at the **combined** stage.
   - Product and decoration prices come from the line properties.
   - Costs come from the catalogue's price options at that quantity.
   - The image comes from the colour, or the hero image if there is no colour match.
   - Supplier and code come from the title, for example `[53-CH35]`. The product id (APPAID) comes from the SKU prefix, for example `25384-navy`.
5. If the client changes the draft in Shopify, the quote's items are refreshed. This stops once staff first change the quote (`edited_at`). After that, a note is added to the quote instead.

The website spreads setup over the units and rounds the line price. The quote prices units and setup separately, so a quote can differ from the website subtotal by cents. For example, the #D4021 sample: the website showed 99 × 8.79 = 870.21; the quote shows 99 × 8.34 + 45.00 = 870.66. The item shows a warning only when the per-unit prices differ.

## Splitting

- **Combined → separated.** "Split into product + decoration" shows the unbranded product, its decoration and each setup as their own lines.
- **Separated → SKUs.** "Size × qty split" works in one of two modes:
  - **Pick from SKU list** calls Promodata `check-stock-levels`. The item's colour is listed first, with stock on hand and supplier SKU.
  - **Enter manually** is for products with no SKU list.
  - In both modes, saving makes the item quantity the total and re-prices the item at that quantity when the catalogue can price it.
  - Sizes with less stock than wanted are flagged.
- **Decoration display on the PDF.** Per quote, decoration can sit in the line ("Decoration in the line") or be shown as its own line.

## Moving and copying lines

Each product line and each extra line (custom product, service, note) has **Move or copy**:

- **Move to a new quote** or **Copy to a new quote.** The new quote gets the next MR number and keeps the client and delivery address.
- **Move to this quote.** This lists only the client's other quotes with the same email address, ignoring capitals.
- **Sizes go with the line.** A product's size × qty rows move or copy with it.
- **Freight stays.** Automatic freight lines don't move. Both quotes work out their freight again afterwards.
- **Logging.** Every move or copy is recorded in the audit log, with the quote it came from and the quote it went to.

## Freight

Admins set freight per supplier under **Admin → Suppliers**. Staff never see that screen.

| Setting | Effect on quotes |
| --- | --- |
| Sell (ex GST) | The price of each freight line |
| Cost | Used for margin only |
| Charged: once per delivery address | One line per supplier per quote |
| Charged: once per product line | One line per item from that supplier |
| Free over | Line is $0 ("free on this order") when that supplier's goods on the quote reach the amount |
| Not set | A $0 line marked "to be confirmed"; the PDF shows TBC and says the total excludes it |
| Decorator | Items whose decoration belongs to another supplier's product (e.g. a decorator's price file) are delivered by that decorator, at its freight |
| Charge when goods go to a decorator first | Also adds the product supplier's freight for the leg to the decorator |

Further rules:
- Freight line text names the delivery suburb and the items, never the supplier.
- Staff can change a freight line's price. It then stops following the supplier setting until they choose "Use the supplier's freight again".
- Every save is kept in `supplier_freight_rates`.
- TOD never stored freight. The figures seen on TOD quotes (Trends 21.75 and 22.50, Logo Line 29.00) still have to be entered.

On the comparison PDF, each quantity option is priced as an order on its own: product, branding, setup and freight, divided by the quantity. The each price rounds half up, matching TOD (230.50 / 100 = 2.31).

## Website quote API (switched on, CMAUDEV only)

These paths are the same as the theme's current calls, now on saas.replicat.com.au:

- `POST /crowdmerch/homepage/createQuoteDraftOrder` takes `email, variantId, quantity, decID, ActiveWL, urgency, comments`. It:
  1. prices the line from the catalogue, the same way as the product page;
  2. creates the draft order through GraphQL `draftOrderCreate`, using `priceOverride` and the same line properties;
  3. turns it into a quote straight away;
  4. queues the instant quote email with the PDF.
- `POST addToWishlist`, `getDraftOrderItems` and `removeDiscontinuedItem` keep wish lists **in the SaaS**, keyed by an `ActiveWL` token, not as Shopify drafts.

Safeguards:
- **Store lock.** The app refuses to write to any Shopify store unless `SHOPIFY_SHOP_DOMAIN` equals `SHOPIFY_DEV_STORE`, or `SHOPIFY_ALLOW_LIVE=true`. A live token pasted by mistake can't create anything on the live store.
- **Request checks.** Calls must come from an origin in `WEBSITE_QUOTE_ORIGINS`. There is a per-IP hourly limit, and a `website` honeypot field.
- **Provisional replies.** Reply shapes are provisional. Capture the current app's replies from the live product page and match them before changing the theme on CMAUDEV.

## Setting up on the server (no shell)

1. Deploy as in `docs/deploy.md`. Migrations run from `.cpanel.yml`.
2. In `.env`, set `SETUP_TOKEN` to a long random value. Open `/setup` and create the first admin, then remove `SETUP_TOKEN`.
3. Sign in and scan the QR code with an authenticator app.
4. Optional: upload a signature image to `storage/app/branding/signature.png` for the quote PDF. It is not in Git.
5. CMAUDEV settings:
   - `SHOPIFY_SHOP_DOMAIN` and `SHOPIFY_DEV_STORE` (the dev store's myshopify domain);
   - `SHOPIFY_CLIENT_ID` and `SHOPIFY_CLIENT_SECRET` of a Dev Dashboard app installed on CMAU_DEV, with scopes `read_draft_orders`, `write_draft_orders`, `read_products` (the app fetches and renews its own 24-hour token; see `docs/deploy.md`);
   - `SHOPIFY_WEBHOOK_SECRET`;
   - `WEBSITE_QUOTE_ORIGINS`.
6. Register webhooks `draft_orders/create` and `draft_orders/update` to `https://saas.replicat.com.au/webhooks/shopify/draft-orders`.
7. Mail: set the `MAIL_*` values (cPanel SMTP) so instant quotes are sent. Until then they go to the log.

## Not in stage 1

- ROD hand-off tables and the SAASROD macro (stage 2).
- Shopify invoice from a won quote.
- Client lookup in ROD Contacts.
- The supplier-website pricing investigation.

## Client PDF layout (agreed and built 25 Oct 2026)

The design canvas has the agreed layouts: the Lookbook comparison (PS) and quote "2b" (MR). They are built in `resources/views/pdf` with the CrowdMerch logo (`public/img/crowdmerch-logo.png`, taken from the website at 446 px wide: replace it with a larger original for sharper print) and Oswald and Mulish fonts (`resources/fonts`, SIL Open Font License).

- **Branding.** Both PDFs carry the CrowdMerch logo, shown large.
- **Comparison (PS).**
  - Two products per A4 page, with up to three quantity breaks each.
  - Each break shows its own sum: quantity × price each, then + setup, then + delivery, then the total ex GST with the inc GST total beside it. The price each is shown ex GST with inc GST underneath. The lowest price each is marked best value.
  - "All in" appears only when the product has a delivery line in the SaaS. Without one, the heading is "Price each", the sum leaves out delivery, and a note says delivery will be confirmed on the quote.
- **Quote (MR).**
  - Items with pictures, each marked ex GST.
  - "Also included" (setups and delivery) sits right-aligned directly above the totals block.
  - Timing, how to go ahead and the signature sit on the left.
  - The timing line ends with "but please let us know if you have a more urgent deadline."
