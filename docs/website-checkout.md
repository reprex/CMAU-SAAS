# Website checkout and My Orders (CMAUDEV-CHECKOUT)

Built 1 Nov 2026 on `feat/quotes`, from the prototype Scott approved that day (`cmau-dev-checkout.html`: the Carts, Checkout and My Orders sections). Clients can order **one product, plus its related styles**, straight from the product page, without a quote, and order **samples** from suppliers that offer them ([Samples](#samples), added the same day). The Shopify theme (Galleria 4.1.0, built later) calls the SaaS for everything dynamic. There is no online payment: the order is created here and an invoice is emailed.

The Shopify product contract and DecorationJSON in `docs/contracts/` are unchanged. The checkout uses the same decoration options (`DecID`) the theme already reads.

| Part | Where |
| --- | --- |
| Pricing, stock and the direct-order rules | `app/Checkout/CheckoutPricer.php`, `BrandingPricer.php`, `DecorationMethods.php` |
| Samples cart | `app/Checkout/SamplesPricer.php`, `OrderCreator::createSamples`, `resources/views/pdf/website-samples-invoice.blade.php` |
| Our codes (what clients see) | `app/Catalogue/OurCodes.php` (docs/supplier-codes.md) |
| Freight estimate (driver interface) | `app/Freight/*`, `config/freight.php` |
| Business days, delivery estimate, deadline check | `app/Checkout/BusinessDays.php`, `DeliveryEstimator.php`, `public_holidays` table |
| Order creation, CM numbers, private logos, invoice PDF | `app/Checkout/OrderCreator.php`, `InvoiceFiles.php`, `QuotePdf::websiteInvoice`, `resources/views/pdf/website-invoice.blade.php` |
| Emails (drafts or queued) | `app/Checkout/OutboundEmails.php`, `pending_emails`, `SendPendingEmail` job |
| My Orders codes and tokens | `app/Checkout/AccountCodes.php`, `AccountTokens.php`, `app/Checkout/Sms/*` |
| Address lookup (driver interface) | `app/Checkout/Address/*` |
| Public API | `Public/WebsiteCheckoutController`, `Public/WebsiteAccountController`, `Public/AddressLookupController` |
| Staff screens | **Website orders** (`WebsiteOrderController`), Range › product › **Related styles**, Admin › Suppliers › **Website orders** |
| Settings | `config/website.php`, `config/freight.php`, `suppliers.state / stock_lead_days / payment_terms / offers_samples / sample_freight / sample_lead_days` |

## Business rules (Scott, 1 Nov 2026)

1. **One product per order**, plus its **related styles**: other styles of the same product from the same supplier (womens, kids, long sleeve), vetted by staff under Range › product › Related styles (`related_styles`). They share the branding and the print run. Any other product makes the cart fail with issue `unrelated`; those go through the quote flow.
2. **Pricing.** Every style is priced at the unbranded price break for the order's **total** quantity, as if it were one SKU, and keeps its own unbranded price at that break. Unit price = unbranded + each position's decoration per unit. Setup is charged **once per position** for the whole order (plus the main product's own unbranded setup, once, if it has one). Prices always come from the SaaS pricing (`price_options`), recomputed on every request; anything the client sends about prices is ignored. All amounts are ex GST.
   - **Branding by method** (docs/branding-templates.md). The method comes from the decoration option's name (`config/website.php` `methods`, first match wins): embroidery, screen print, pad print, oversize DTF, DTF.
     - Screen print: the product's price line for that number of colours (e.g. "Screen print 3 colours"). Above the largest line, the top line is used and `over_colour_limit` is set.
     - Pad print: the 1-colour line's unit and setup × colours.
     - Embroidery, DTF, oversize DTF: one price whatever the colours.
   - **GST** 10% on goods, setup and freight for Australian delivery. **Exports** (delivery outside Australia) are GST-free: `export: true`, `gst: 0`, and `duty_note` (also an `export` alert, on the invoice and in the email): *"Delivering outside Australia: your invoice is ex GST (GST-free export). You must pay any local taxes and duties directly to the carrier on arrival before delivery can be made."*
3. **Direct order only when** every rule passes. Otherwise `can_order: false` and `issues` lists why; the theme sends the client to the quote flow.

   | Issue code | Rule |
   | --- | --- |
   | `stock` | A SKU line wants more than the supplier has, or the supplier doesn't list that colour and size. Stock is checked **live against Promodata on every request** (`PromodataStock::rows($id, fresh: true)`), never taken from the 5-minute cache or the database |
   | `stock_unavailable` | Live stock couldn't be checked (no stock check for the product, or Promodata failed) |
   | `review` | A position uses a method that needs artwork review: screen print, pad print, embroidery, or a decoration no method matches. DTF and oversize DTF don't |
   | `logo` | A position has no logo of the client's own |
   | `deadline` | The chosen service arrives after the need-by date, but a faster one would make it (`faster_service`) |
   | `late` | No service meets the need-by date (`deadline.earliest` gives the earliest) |
   | `need_by` | No need-by date, or it isn't after today |
   | `unrelated`, `decoration`, `price`, `qty`, `branding`, `freight` | Not this product or its related styles; a decoration that isn't this product's; a style that can't be priced online; no quantities; no positions; no freight for the address |

4. **No online payment.** "Create order" makes the order here and an invoice is emailed (rule 9). Full payment applies. Each supplier has a `payment_terms` setting (default `full`; options in `config/website.php` `payment_terms.options`) so deposit terms can be added for suppliers such as Trends.
5. **Freight** (`App\Freight\FreightDriver`; default driver `estimate`).
   - **Pack data:** unit weight, units per carton, carton size and tare. Read from Promodata's packing details when the supplier gives them (`Carton Quantity`, `Carton Dimensions`, `Carton Weight`, `Item Weight`…), otherwise per product type in `config/freight.php` `pack_defaults`. Cartons are counted per style.
   - **Australia:** zone by the postcode's first digit. TNT Road Express and TNT Overnight Express, each base + per kg × chargeable kg. Chargeable kg = max(dead weight, cubic at 250 kg/m³).
   - **Overseas:** FedEx International Priority and Economy for NZ, US, GB, SG and CA. Volumetric weight = cm³ ÷ 5000.
   - The reply gives cartons, kg, m³, chargeable kg and which basis won.
6. **Delivery estimate.** Business days, one stage after another, from today:
   - order check and invoice: 1;
   - stock from the supplier: the supplier's `stock_lead_days`, default 2 (the slowest supplier in the order);
   - decoration: the slowest method in the order (DTF 5, oversize DTF 6, pad 7, screen 8, embroidery 9);
   - freight: the slowest transit day of the chosen service.

   Business days skip weekends and public holidays in the **supplier's state** (`suppliers.state`, default VIC). The reply has each stage's end date, the delivery date, the holidays skipped, and the deadline check: met or not, business days to spare (or late by), and the faster service that would meet it.
7. **Order creation** recomputes everything and refuses (422 with the issues) if any rule fails. It saves the order (CM-xxxxx), a line per SKU (product, colour, size, supplier SKU, qty, unit ex GST, stock seen), the positions (position, method, colours, width, logo), freight service, ETA and its breakdown, need-by, event, totals and GST. Each logo is stored on the **private `artwork` disk** (`storage/app/private/artwork`, not served, no URL); staff download it from Website orders when signed in. The invoice PDF is made and kept on the private local disk. Pre-payment alerts go on the invoice: `deadline_at_risk` (1 business day or fewer to spare), `stock_low` (a line takes 80% or more of the supplier's stock), `export`.
8. **My Orders.** A 6-digit one-time code by email or SMS, then a signed 90-day token the theme keeps in a cookie. The token only ever shows its own email's orders. Stages: Order received, Invoice sent, Paid, Proof approved, In production, Dispatched, Delivered. Change requests are accepted until Dispatched.
9. **Emails.** See [Emails and REPSTACK principle 6](#emails-and-repstack-principle-6).
10. **Codes (Scott, 1 Nov 2026, reversing the earlier decision).** Every client-facing reply, the invoice PDF, the emails and My Orders show **our code** only: `SupplierID-Code` (e.g. `7-5001`, `3-104743`, as at the end of the product title) and **our SKU**: our code + colour + size (`7-5001-BLAC-M`; colour = the first 4 letters, or the first 2 of each of the first two words: Light Grey Marle = `LIGR`). The supplier's name and its own product code and SKUs are never in them. The supplier code and supplier SKU are still saved on each order line for staff and for ordering from the supplier, and staff screens show them. The delivery estimate's stock stage says "Stock from the supplier", not the supplier's name. Product names are Promodata's and can contain a brand (e.g. "AS Colour Mens Staple Tee"); they are not changed.

## Endpoints

All public endpoints answer only the origins in `WEBSITE_ORDER_ORIGINS` (defaults to `WEBSITE_QUOTE_ORIGINS`), with CORS and an `OPTIONS` preflight, a `website` honeypot field, per-IP rate limits, and the optional Turnstile check on writes (`TURNSTILE_PROTECT_WEBSITE_API`, field `cf_turnstile`). CSRF is not used for them (`bootstrap/app.php`). Errors are `{"success": false, "message": "…"}`, with `errors` for validation (422).

### POST `crowdmerch/checkout/price` (JSON)

Prices the cart. The theme calls it as the client enters sizes, chooses delivery and sets the date. Limit: `WEBSITE_ORDER_PRICES_PER_HOUR` per IP (each call checks stock live).

```json
{
  "product_id": 43446,
  "lines": [
    {"product_id": 43446, "colour": "Black", "size": "M", "qty": 30},
    {"product_id": 43447, "colour": "White", "size": "10", "qty": 50}
  ],
  "positions": [
    {"name": "Front centre", "decID": "43446-dtf", "colours": null, "width_mm": 280, "has_logo": true}
  ],
  "delivery": {"country": "AU", "postcode": "3066"},
  "freight_service": "tnt_road",
  "need_by": "2026-12-04"
}
```

- `lines`: the main product and any related styles, one line per colour and size (repeats are added together; qty 0 lines are dropped).
- `positions[].decID`: a `DecID` from the product's DecorationJSON. `colours` matters for screen and pad print. `has_logo`: the client has uploaded their own logo for that position.
- `delivery.country`: `AU`, `NZ`, `US`, `GB`, `SG` or `CA`. `freight_service`: optional; the first option is used if it's missing.

Reply (abridged; the same cart with `need_by` 2026-11-13, priced on Fri 30 Oct 2026):

```json
{
  "success": true,
  "can_order": false,
  "issues": [{"code": "deadline", "message": "TNT Road Express arrives too late for Fri 13 Nov. Choose TNT Overnight Express.", "faster_service": "tnt_overnight"}],
  "product": {"id": 43446, "code": "7-5001", "name": "AS Colour Mens Staple Tee"},
  "related_styles": [{"product_id": 43447, "code": "7-4001", "name": "AS Colour Womens Maple Tee", "label": "Womens fit"}],
  "units": 110,
  "priced_at_qty": 110,
  "styles": [
    {"product_id": 43446, "code": "7-5001", "name": "AS Colour Mens Staple Tee", "units": 60, "unbranded_unit": 8, "branding_unit": 7.75, "unit": 15.75, "goods": 945},
    {"product_id": 43447, "code": "7-4001", "name": "AS Colour Womens Maple Tee", "units": 50, "unbranded_unit": 7.55, "branding_unit": 7.75, "unit": 15.3, "goods": 765}
  ],
  "lines": [{"product_id": 43446, "colour": "Black", "size": "M", "qty": 30, "product_code": "7-5001", "product_name": "AS Colour Mens Staple Tee",
             "sku": "7-5001-BLAC-M", "stock": 500, "unbranded_unit": 8, "branding_unit": 7.75, "unit": 15.75, "total": 472.5}],
  "positions": [{"name": "Front centre", "width_mm": 280, "has_logo": true, "method": "dtf", "method_name": "DTF", "decoration_option_id": "43446-dtf",
                 "decoration_name": "DTF print up to A3", "colours": null, "unit": 7.75, "setup": 0, "needs_review": false, "over_colour_limit": false}],
  "setup": {"positions": [{"name": "Front centre", "amount": 0}], "product": 0, "total": 0},
  "freight": {
    "ok": true, "message": null, "country": "AU", "export": false, "zone": "VIC",
    "cartons": 2, "kg": 22.5, "m3": 0.189, "chargeable_kg": 47.25, "basis": "cubic weight (250 kg/m³)",
    "options": [
      {"id": "tnt_road", "name": "TNT Road Express", "carrier": "TNT", "days": [1, 2], "price": 28.51, "delivery_date": "2026-11-16"},
      {"id": "tnt_overnight", "name": "TNT Overnight Express", "carrier": "TNT", "days": [1, 1], "price": 58.67, "delivery_date": "2026-11-13"}
    ],
    "chosen": "tnt_road"
  },
  "delivery_estimate": {
    "start": "2026-10-30", "state": "VIC",
    "stages": [
      {"key": "order_check", "name": "Order check and invoice", "days": 1, "ends": "2026-11-02"},
      {"key": "stock", "name": "Stock from the supplier", "days": 2, "ends": "2026-11-05"},
      {"key": "decoration", "name": "DTF decoration", "method": "dtf", "days": 5, "ends": "2026-11-12"},
      {"key": "freight", "name": "TNT Road Express", "service": "tnt_road", "days": 2, "ends": "2026-11-16"}
    ],
    "business_days": 10, "delivery_date": "2026-11-16",
    "holidays_skipped": [{"date": "2026-11-03", "name": "Melbourne Cup"}]
  },
  "deadline": {
    "need_by": "2026-11-13", "met": false, "spare_business_days": 0, "late_business_days": 1,
    "faster_service": {"id": "tnt_overnight", "name": "TNT Overnight Express", "delivery_date": "2026-11-13"},
    "earliest": {"id": "tnt_overnight", "name": "TNT Overnight Express", "delivery_date": "2026-11-13"}
  },
  "totals": {"goods": 1710, "setup": 0, "freight": 28.51, "subtotal": 1738.51, "gst_rate": 0.1, "gst": 173.85, "total": 1912.36, "inc_gst": true, "currency": "AUD"},
  "export": false,
  "duty_note": null,
  "payment_terms": "full",
  "alerts": [],
  "artwork_review_note": null
}
```

- `product.code`, `styles[].code`, `related_styles[].code` and `lines[].product_code` are our codes; `lines[].sku` is our SKU. The supplier's SKU (`supplier_sku`) is kept for the order but not sent.
- `deadline` is null until there is a need-by date and a delivery option. `delivery_estimate` is for the chosen service; every option carries its own `delivery_date`.
- `alerts`: `deadline_at_risk`, `stock_low`, `export`, each `{code, message}`; they go on the invoice.
- `artwork_review_note`: "Prices subject to the CrowdMerch team reviewing your artwork." when a position needs review (those carts go to the quote flow).
- 404 when the product doesn't exist or is discontinued.

### POST `crowdmerch/checkout/order` (multipart/form-data)

Creates the order. Limit: `WEBSITE_ORDERS_PER_HOUR` per IP. Same cart fields as `price` (no `delivery` and no `has_logo`: the address gives the destination and each position carries its file), plus:

```
product_id=43446
lines[0][product_id]=43446  lines[0][colour]=Black  lines[0][size]=M  lines[0][qty]=30
positions[0][name]=Front centre  positions[0][decID]=43446-dtf  positions[0][width_mm]=280  positions[0][logo]=<file>
freight_service=tnt_road
need_by=2026-12-04
contact[name]=Scott Wilson  contact[organisation]=Replicat (optional)  contact[email]=…  contact[phone]=0400 000 000
address[unit]= (optional)  address[street]=12 Smith Street  address[suburb]=Collingwood
address[state]=VIC (required in Australia)  address[postcode]=3066 (4 digits in Australia)  address[country]=AU
event[name]=Summer Fun Run  event[date]=2026-12-06  event[place]=Collingwood (all optional)
proof_ack=1
```

- `positions[n][logo]`: required, one per position; png, jpg, webp, svg, pdf, ai or eps, up to `WEBSITE_LOGO_MAX_KB` (20 MB).
- `proof_ack` must be accepted. It is the client ticking: *"Once you've paid, we will provide a digital proof for your approval before proceeding with production."*
- `need_by` must be after today; `freight_service` must be one of the options for the address.

201 reply:

```json
{
  "success": true,
  "order_number": "CM-10001",
  "email": "client@example.com",
  "total": 1912.36,
  "inc_gst": true,
  "export": false,
  "duty_note": null,
  "eta": "2026-11-16",
  "alerts": [],
  "invoice": "pending_review",
  "account_token": "eyJlIjoiY2xpZW50QGV4YW1wbGUuY29tIiwieCI6MTgwMTI4MzYwMH0.k3…",
  "account_token_expires_at": "2027-01-28T10:00:00+11:00"
}
```

- `invoice`: `pending_review` (staff send it, the default) or `sending` (auto send on). The theme's "We'll email an invoice in a few moments" wording only fits `sending`; for `pending_review` say the team will check the order and email the invoice.
- `account_token`: keep it in a cookie for My Orders, with the email.
- 422 with `{"success": false, "can_order": false, "issues": […], "totals": {…}}` when a rule fails at the moment of ordering (stock can change between price and order). Nothing is saved.

### POST `crowdmerch/account/code`

```json
{"email": "client@example.com", "via": "email"}
```

`via`: `email` (default) or `sms`. A code is sent only if the email has orders; SMS goes to the phone on the client's **most recent** order. The reply is always the same: `{"success": true, "message": "If that email has orders with us, we've sent a 6-digit code. It expires in 10 minutes."}`. Limits: 3 codes per email per hour (silently, with the same reply) and `codes_per_ip_per_hour` per IP (429). Asking again cancels the previous code.

### POST `crowdmerch/account/verify`

```json
{"email": "client@example.com", "code": "246810"}
```

200: `{"success": true, "email": "client@example.com", "token": "…", "expires_at": "2027-01-30T09:12:00+11:00"}`. Wrong, expired (10 minutes), used or locked (5 attempts) codes all get 422 `{"success": false, "message": "That code is wrong or has expired. Ask for a new code."}`.

The token is `base64url({"e": email, "x": expiry}).HMAC-SHA256` with the app key. Changing the app key signs everyone out.

### GET `crowdmerch/account/orders`

Header `Authorization: Bearer <token>`. 401 `{"success": false, "message": "Please sign in again."}` for a missing, forged or expired token.

```json
{
  "success": true,
  "email": "client@example.com",
  "stages": ["Order received", "Invoice sent", "Paid", "Proof approved", "In production", "Dispatched", "Delivered"],
  "orders": [{
    "number": "CM-10001",
    "kind": "order",
    "stages": ["Order received", "Invoice sent", "Paid", "Proof approved", "In production", "Dispatched", "Delivered"],
    "placed_at": "2026-10-30T10:00:00+11:00",
    "total": 1912.36, "inc_gst": true, "export": false, "total_label": "inc GST",
    "items": "AS Colour Mens Staple Tee 7-5001 × 60, AS Colour Womens Maple Tee 7-4001 × 50 · 1 branding position",
    "factories": null,
    "stage": 5, "stage_name": "Dispatched",
    "delivery_date": "2026-11-16", "delivered": false, "need_by": "2026-12-04",
    "carrier": "TNT", "consignment": "GEA412387690",
    "tracking_url": "https://www.tnt.com/express/en_au/site/shipping-tools/tracking.html?searchType=con&cons=GEA412387690",
    "can_request_change": false,
    "change_requests": [{"at": "2026-11-02T09:30:00+11:00", "message": "Add 10 more size L"}]
  }]
}
```

Each order carries its own `stages` list and `stage` is the index **in that list**: a samples order (`kind: "samples"`) has five stages (Order received, Invoice sent, Paid, Dispatched, Delivered), `need_by: null`, items like `"Cotton tote bag 3-4126 · Natural, Aluminium sports bottle 20-M101 · Blue"` and `factories: [{"label": "From factory 1", "samples": 2, "freight": 12.5, "subtotal": 19.7}, …]`. The top-level `stages` is still the order list, for older theme code. `delivery_date` is the actual delivery date once staff set it, otherwise the estimate. Tracking: TNT `https://www.tnt.com/express/en_au/site/shipping-tools/tracking.html?searchType=con&cons={n}`, FedEx `https://www.fedex.com/fedextrack/?trknbr={n}`.

### POST `crowdmerch/account/change`

Header `Authorization: Bearer <token>`.

```json
{"order_number": "CM-10001", "message": "Please add 10 more size L"}
```

201 `{"success": true, "message": "Sent to the CrowdMerch team. …"}`. 404 if the order isn't this email's; 409 once it's Dispatched or Delivered. Staff see it on the order under Website orders (and in the list), and a notice to `WEBSITE_ORDERS_STAFF_EMAIL` is prepared (rule 9).

### GET `crowdmerch/address?q=`

Address suggestions for checkout: `{"success": true, "results": [{"unit": null, "street": "12 Smith Street", "suburb": "Collingwood", "state": "VIC", "postcode": "3066", "country": "AU"}]}`. The default driver is `none`, which always returns `[]`, so the theme offers manual entry. Queries under 3 characters return `[]`.

## Samples

Scott, 1 Nov 2026, from the approved prototype (`// ── Samples`). "Order sample" on the product page of suppliers that offer samples (Trends and Dex to start). Every sample the client adds goes into **one samples cart**, paid on **one invoice**.

- **Who offers them:** Admin › Suppliers › supplier › Website orders › "Offers samples on the website" (`suppliers.offers_samples`, default off). TOD's own flag (`sample_available`) is shown there as a hint but is not used, so a TOD import can't switch samples on.
- **Price:** the product's **unbranded price at its minimum order** (the first break of its unbranded price option), from the SaaS pricing; nothing the client sends is used.
- **Unprinted or print overrun:** the factory decides; the client doesn't choose and nothing is stored. The reply's `note` says so.
- **Factories:** samples are grouped by supplier, labelled "From factory 1", "From factory 2" in order of first appearance. The supplier is never named in the reply, invoice, email or My Orders; staff see it.
- **Freight:** each factory's `suppliers.sample_freight` (ex GST, per consignment; already loaded from TOD's `SupplierSampleFreight`, editable on Admin › Suppliers) once, with a factory subtotal. A supplier with no sample freight makes the cart fail with `sample_freight`. GST 10% on the total of all factories.
- **Australia only** for now: any other country fails with `not_australia`.
- **Delivery estimate:** order check (`LEAD_ORDER_CHECK_DAYS`, 1), then the slowest factory's supply days (`suppliers.sample_lead_days`, else its stock lead time, else 2) in that supplier's state, then the slowest TNT Road Express transit day for the postcode's zone (`config/freight.php`; `website.samples.freight_service`). Holidays as for orders.
- **The order** reuses the order, invoice, pending-email and My Orders machinery: `website_orders.kind = samples`, number CM-xxxxx from the same counter, one line per sample (qty 1, our code, our SKU, the supplier's code for staff, `factory`, `supplier_id`), each factory's freight and supplier in `freight_breakdown.factories`, no need-by, branding, proof or setup. Stages: Order received, Invoice sent, Paid, Dispatched, Delivered (stored as the same stage numbers as orders, so Dispatched and Delivered mean the same). Change requests until Dispatched; tracking as for orders. The invoice (`pdf.website-samples-invoice`) lists each factory's samples, freight and subtotal; the email follows principle 6 like the order invoice.

### POST `crowdmerch/checkout/samples/price` (JSON)

```json
{"items": [{"product": 4126, "colour": "Natural"}, {"product": 9101, "colour": "Blue"}, {"product": 4243, "colour": "Black"}], "postcode": "3066"}
```

`product` is the APPAID. `colour` is optional (the first colour is used) and must be one of the product's colours. Repeats of the same product and colour count once. `country` is optional (`AU`). Up to `website.samples.max_items` (30). Limit: `WEBSITE_ORDER_PRICES_PER_HOUR` per IP.

Reply (priced on Fri 30 Oct 2026):

```json
{
  "success": true, "can_order": true, "issues": [], "samples": 3,
  "factories": [
    {"factory": 1, "label": "From factory 1", "days": 2,
     "items": [{"product_id": 4126, "code": "3-4126", "sku": "3-4126-NATU", "name": "Cotton tote bag", "colour": "Natural", "unit": 4, "moq": 25},
               {"product_id": 4243, "code": "3-4243", "sku": "3-4243-BLAC", "name": "Knitted beanie", "colour": "Black", "unit": 3.2, "moq": 50}],
     "goods": 7.2, "freight": 12.5, "subtotal": 19.7},
    {"factory": 2, "label": "From factory 2", "days": 3,
     "items": [{"product_id": 9101, "code": "20-M101", "sku": "20-M101-BLUE", "name": "Aluminium sports bottle", "colour": "Blue", "unit": 1.8, "moq": 100}],
     "goods": 1.8, "freight": 14, "subtotal": 15.8}
  ],
  "delivery_estimate": {"start": "2026-10-30", "stages": [
      {"key": "order_check", "name": "Order check and invoice", "days": 1, "ends": "2026-11-02"},
      {"key": "supply", "name": "Samples from the factories", "days": 3, "ends": "2026-11-05"},
      {"key": "freight", "name": "Delivery to VIC", "days": 2, "ends": "2026-11-09"}],
    "business_days": 6, "delivery_date": "2026-11-09", "holidays_skipped": []},
  "totals": {"goods": 9, "freight": 26.5, "subtotal": 35.5, "gst_rate": 0.1, "gst": 3.55, "total": 39.05, "inc_gst": true, "currency": "AUD"},
  "note": "Samples may be unprinted or taken from another client's print overrun. The factory decides, and it can vary."
}
```

| Issue code | Rule |
| --- | --- |
| `no_samples` | The product's supplier doesn't offer samples (`product`, `product_code` = our code) |
| `not_australia` | Delivery outside Australia |
| `empty` | No samples in the cart |
| `colour` | The product doesn't come in that colour |
| `postcode` | No 4-digit Australian postcode we deliver to |
| `sample_freight` | A factory's supplier has no sample freight set |
| `product`, `price` | Not found or discontinued; no unbranded price to use |

`delivery_estimate` is null until there is a postcode and a sample.

### POST `crowdmerch/checkout/samples/order` (JSON or form)

The same `items`, plus `contact` and `address` exactly as for `checkout/order` (no need-by, event, branding, logos or proof). Everything is recomputed; any rule failing gives 422 `{"success": false, "can_order": false, "issues": […], "totals": {…}}` and nothing is saved. Limit: `WEBSITE_ORDERS_PER_HOUR` per IP; Turnstile as for orders.

201: `{"success": true, "kind": "samples", "order_number": "CM-10002", "email": "…", "total": 39.05, "inc_gst": true, "eta": "2026-11-09", "invoice": "pending_review", "account_token": "…", "account_token_expires_at": "…"}`.

## Emails and REPSTACK principle 6

Principle 6 says client emails go to drafts and are never sent automatically. Scott wants "we'll email an invoice in a few moments" but has not lifted principle 6 yet, so:

- `WEBSITE_AUTO_SEND_INVOICE=false` (**default**): the invoice email is saved as a pending email (`pending_emails`, status `draft`). Staff check the invoice PDF and press **Send invoice** on the order. Sending moves the order to "Invoice sent".
- `WEBSITE_AUTO_SEND_INVOICE=true`: the email is queued and the minute cron's `queue:work` burst sends it, usually within a minute.
- **Change-request notices** (internal, to `WEBSITE_ORDERS_STAFF_EMAIL`) follow the same switch. With it off they wait on the order like the invoice; the change request itself shows on Website orders straight away.
- **Exception for Scott to confirm:** My Orders one-time codes are security emails and are sent immediately, whatever the switch. A code that waits for staff would make sign-in unusable.

The existing website instant quote email (`SendInstantQuote`, `WEBSITE_EMAIL_INSTANT_QUOTE`) already sends automatically; that also needs Scott's principle 6 decision.

## Staff screens

- **Website orders** (all signed-in staff; changes need the sales or admin role): sample orders carry a **Samples** tag (filter: Orders / Samples); their page shows **Samples by factory** with each factory's supplier name, samples, freight and subtotal, and the stage list has only the five sample stages. The list shows each order's stage and need-by date, flags late estimates, invoices waiting to be sent and open change requests. The order page shows the SKU lines (our SKU, the supplier code and SKU, and the stock seen at order), positions with a private logo download, totals and freight breakdown, the invoice PDF, the pending emails with **Send**, the stage, carrier, consignment and delivered date, the change requests (**Mark handled**), the client, the delivery estimate and the history.
- **Range › product › Related styles**: add another product (by APPAID, same supplier only) with a label such as "Womens fit".
- **Admin › Suppliers › supplier › Website orders**: state for public holidays, stock lead time, payment terms, **offers samples**, **sample freight** per consignment (ex GST) and **sample lead time**. These are per-supplier settings, so they live on the supplier as the freight settings do. The decoration lead times and the order check day are global and stay in `config/website.php` (`LEAD_ORDER_CHECK_DAYS`, `LEAD_STOCK_DAYS` for the default), because there is no global settings screen yet.

## Settings

| Setting | Default | Notes |
| --- | --- | --- |
| `WEBSITE_ORDERS` | true | Turns the checkout and My Orders API on |
| `WEBSITE_ORDER_ORIGINS` | `WEBSITE_QUOTE_ORIGINS` | Comma-separated theme origins, e.g. the CMAU_DEV store |
| `WEBSITE_ORDER_PRICES_PER_HOUR` / `WEBSITE_ORDERS_PER_HOUR` / `WEBSITE_ORDER_CHANGES_PER_HOUR` | 240 / 6 / 10 | Per IP |
| `WEBSITE_ORDER_NUMBER_START` | 10001 | First order is CM-10001 (counter `website_order`) |
| `WEBSITE_AUTO_SEND_INVOICE` | false | Principle 6, above |
| `WEBSITE_ORDERS_STAFF_EMAIL` | `QUOTE_EMAIL` | Change-request notices |
| `WEBSITE_LOGO_MAX_KB` | 20480 | Per logo |
| `website.lead_times` | 1, 2, DTF 5, oversize 6, pad 7, screen 8, embroidery 9 | **Placeholders** until Scott confirms real lead times |
| `website.orders.deadline_risk_spare_days` / `stock_low_share` | 1 / 0.8 | Pre-payment alerts |
| `website.methods` | | Method names, review flag, pricing rule and the pattern that matches decoration names |
| `FREIGHT_DRIVER` | estimate | |
| `config/freight.php` | | **All rates and transit times are placeholders** from the prototype; pack defaults other than tees are guesses |
| `SMS_DRIVER` | log | Writes the text (with the code) to the log. Development only |
| `ADDRESS_LOOKUP_DRIVER` / `ADDRESS_LOOKUP_KEY` | none / – | |
| `website.samples` | TNT Road transit, 30 items | Samples cart: the freight service whose transit days count, the note shown with samples |
| `suppliers.offers_samples` / `sample_freight` / `sample_lead_days` | off / TOD value / stock lead time | Per supplier (Admin › Suppliers) |

## Public holidays

`public_holidays` (date, state, name) is loaded with every state's whole-day public holidays from November 2026 to December 2027 (`PublicHolidaySeeder`, run by a migration because production has no shell; source: Fair Work Ombudsman 2026 and 2027 lists and Business Victoria, checked 1 Nov 2026). Part-day holidays (Christmas Eve and New Year's Eve evenings) and area-only ones (Tasmanian Regatta and Recreation Day, show days) are left out.

- **VIC:** Melbourne Cup Tue 3 Nov 2026; Christmas Fri 25 Dec; Boxing Day Sat 26 Dec with the additional day Mon 28 Dec; New Year's Day Fri 1 Jan 2027; Australia Day Tue 26 Jan; Labour Day Mon 8 Mar; Good Friday 26 Mar, Saturday before Easter 27 Mar, Easter Sunday 28 Mar, Easter Monday 29 Mar; Anzac Day Sun 25 Apr; King's Birthday Mon 14 Jun; Melbourne Cup Tue 2 Nov 2027; Christmas Sat 25 Dec and Boxing Day Sun 26 Dec 2027, with additional days Mon 27 and Tue 28 Dec.
- **Anzac Day 2027 in VIC has no Monday substitute.** The brief said "Anzac Day observed", but Business Victoria and Fair Work both say Victoria keeps it on Sunday 25 April with no replacement day (NSW, WA, ACT, NT and QLD do have Monday 26 April). Only the Sunday is seeded for VIC.
- **TODO:** the Friday before the 2027 AFL Grand Final isn't set yet (normally the last Friday in September). Add it when it's announced.

To add or correct holidays: `php artisan holidays:import holidays.csv` (header `date,state,name`, dates `YYYY-MM-DD`; existing date + state rows are updated; `--dry-run` to check). Without a shell, run it once from a cPanel cron entry, or add the rows to the seeder and deploy.

## Still needed

- **MachShip (TNT) and FedEx API access**, to replace the placeholder rates with live quotes and book consignments. Each becomes a `FreightDriver`; consignment numbers can then be filled in automatically.
- **An SMS provider** for My Orders codes (an `SmsSender` driver). Until then, SMS codes only reach the log, so the theme should offer email first.
- **An address provider** (Australia Post, Addressify, Google Places…) as an `AddressLookup` driver, with its key in `ADDRESS_LOOKUP_KEY`.
- **Real lead times** from Scott: order check, each supplier's stock lead time (Admin › Suppliers) and each decoration method.
- **Scott's principle 6 decision** on auto-sending invoices (and on the instant quote email), and confirming the one-time code exception.
- **Related styles** need vetting product by product (Range › product). There is no automatic suggestion from Promodata yet.
- **Payment terms** other than full payment (e.g. deposits for Trends): add them to `payment_terms.options`; the invoice already prints the order's terms.
- **Samples from Scott:** which suppliers offer samples (switch them on under Admin › Suppliers; Trends and Dex to start), and check each one's sample freight (TOD's values are loaded; a TOD re-import overwrites `sample_freight` but never `offers_samples`) and sample lead time.
- **The quote PDF and the quote desk** still print the supplier's product code ("Code 5001") on client quotes; they weren't part of this change. Move them to our code too if Scott wants the rule everywhere.
- **The theme** (Galleria 4.1.0): the samples cart (one per client, "From factory n"), `checkout/samples/*`, per-order `stages` in My Orders; send `has_logo` on `price`, the files on `order`, keep `account_token` in a cookie, and show `issues`, `alerts`, the estimate and the duty note.
