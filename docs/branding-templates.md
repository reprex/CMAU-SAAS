# Supplier branding templates

Suppliers publish a branding template (PDF or line drawing) for most products: every branding position (front centre, left chest, back, sleeve, lid, wrap…), its maximum print size and often the decoration methods. Where one exists it is used for **precise logo placement** and to show **every position available** on the product page.

**Priority (Scott, 1 Nov 2026):** a staff-approved logo area, then the template, then the print areas in Promodata's own decoration data, then the product type default. Products without a template still get positions automatically from Promodata's decoration options (docs/logo-preview.md), so no product needs manual vetting.

## Where templates come from

1. **Promodata line art** (`product.line_art`), stored on every sync (kept apart from the product photos).
2. **The supplier's template address**: Admin › Suppliers › supplier › "Branding template address", with `{code}` where the product code goes (also `{CODE}` upper case, `{appaid}`). Web-range products are checked a few a minute; a template that exists is added.
3. **Staff**: Range › product › Branding template › paste a URL › Add.

## Reading a template

`catalogue:read-templates` (every minute, two templates a run, sharing the image check's daily cap) sends Claude the template with our front picture (hero) and back picture (an other style image marked as a back view). Claude returns each position: name, view (front, back, side, top), maximum print size in mm, decoration methods, and where it sits on our picture (percent). Range › product shows the positions; "Read now" / "Read again" / "Remove".

- The first front position becomes the product's **logo area** (source `template`): it is published without vetting, comes before Promodata's decoration print areas, and the sample learning never changes it. Staff can still adjust and approve it on Logo areas (that wins).
- Positions without a matching picture (e.g. a sleeve with no side photo) keep their name and size, without a box.
- Reading a template marks a live product for publishing.

## What Shopify gets

- `custom.brandingpositions` (json), from `PositionResolver::forShopify`: the template's positions; else the positions from Promodata's decoration data; else one type-default position. Every product with decoration options has at least one.
  ```json
  [{"name":"Front centre","view":"front","image":"…","left":35,"top":28,"width":30,"max_mm":[280,350],"methods":["Screen print","DTF"],
    "method_areas":[{"method":"screen","name":"Screen print","max_mm":[280,350]},{"method":"dtf","name":"DTF","max_mm":[280,350]}],"source":"template"}]
  ```
  ```json
  [{"name":"Wrap around","view":"front","image":"…","left":32,"top":36,"width":36,"max_mm":[209,108],"methods":["Sublimation"],
    "method_areas":[{"method":"sublimation","name":"Sublimation","max_mm":[209,108],"decIDs":["2119-sub"]}],"source":"decoration"}]
  ```
  `name`, `view`, `image`, `left`, `top`, `width`, `max_mm` and `methods` are as before. New, additive: `method_areas` (each allowed method with its own max size where the data gives one, e.g. DTF 297 × 420 and Oversize DTF 380 × 480 on the same front; `decIDs` = the DecorationJSON options for that method, for decoration and default positions) and `source` (`template`, `decoration` or `default`). `image`/`left`/`top`/`width` are left out when there is no picture for the view.
- `custom.hasbrandingtemplate` (boolean): a template is available. **Never the template itself** (Scott, 3 Nov 2026): no template links on our pages, so a bot can't collect them from our site. The product page says one is available near the mockup and Quick quote buttons, and that it comes with the quote or invoice email. (`custom.brandingtemplate`, the old link, is deleted from the store by `shopify:metafield-definitions`, with every product's value.)

## Template files and the emails (Scott, 3 Nov 2026)

Templates are private files on the `templates` disk (`storage/app/private/branding-templates`, not served), like customer artwork.

- **Reviewed files:** upload the extracted folder with cPanel File Manager (e.g. `~/saas-app/storage/app/template-inbox/Dex`) and run `catalogue:import-templates <folder>` (`--supplier=19` when codes could belong to two suppliers). Each file is named `<supplier code> <anything>` ("AP001 Jackys Cotton Apron.pdf", "100109_Branding Template_1.pdf", "LL0007 Monza Car Charger.pdf") and matched to the product by that code. A file imported again with the same size is skipped. New files are also read for branding positions (as files, by the minute cron, within the daily cap).
- **Range › product › Branding template:** upload a file, download any template (staff only), remove.
- **Addresses** (Promodata line art, the supplier pattern, a URL staff added) are fetched once, the first time an email needs them, and kept as the template's file.
- **Emails** attach the templates of the products on them (`TemplateFiles::attachments`), up to `quotes.templates.max_attach_mb` (15 MB) in all; the rest are named in the email with an offer to send them separately:
  - **Quote email** from Quotes › quote › **Email quote**: a draft with the quote PDF and the templates; staff check it and press **Send** (REPSTACK principle 6). Sending marks the quote sent.
  - **Website invoice** (Website orders): the invoice PDF and the templates of the branded products (not samples).
  - **Instant quote** from the website: the quote PDF and the templates.
- Attachment names are ours ("Branding template 19-AP001 Jackys Cotton Apron.pdf"), never the supplier's name. The Dex, Trends and Logo Line templates are unbranded and go as they are (Scott, 3 Nov 2026); check a new supplier's before importing.

## Branding suite and pricing (Scott, 1 Nov)

The product page has one **Branding** section (below the colours, not under the pictures). Every position (from the template, else from Promodata's decoration data) can be switched on; each has its own decoration method (DTF, oversize DTF, screen print, embroidery, as the product's decoration options and the template allow for that position) and can have its own logo. The pictures and a small preview in the section update as choices are made.

**Price = unbranded price + each position's decoration (its setup and its per-unit price).** Each decoration's prices come from the product's DecorationJSON, priced as one position. There are no combined options such as "Front and back": a front and a back are two positions, each with its own decoration. Combined Promodata lines (e.g. "Front and back print") are not offered as methods.

**Solid-colour processes** need artwork made of flat colours. When one is chosen, the logo is checked in the browser: its colours are counted (shown as swatches, which the shopper can correct with − / +), and artwork with gradients or photo detail is switched to the full colour method (DTF) with a message and an "Upload a solid-colour version" button. Pricing by method (Scott, 1 Nov):

- **Screen print:** a separate price grid for each number of colours (the supplier's 1, 2, 3, 4 colour price lines in Promodata). The SaaS picks the line matching the colour count; above the largest grid the top line is shown and our team prices it.
- **Pad print:** the single colour price (setup and per unit) times the number of colours.
- **Embroidery:** solid thread colours required, but one price whatever the number of colours (priced on stitch count).

Whenever one of these is selected the page, the quote PDF and the quote show: **"Prices subject to the CrowdMerch team reviewing your artwork."** The SaaS repeats the colour check on the uploaded file when the PDF is made, and staff confirm colours (and stitch count for embroidery) before the order.

Follow-up work in the SaaS:
- **Website quote API and intake:** a cart or list line carries several decorations (position, decID, logo per position) instead of one `decID`; the quote desk splits them into the product line plus one decoration line per position (it already splits product and decoration).
- **DecorationJSON:** add each option's methods and which template positions it suits (additive keys the current theme ignores), and leave combined options out of the new templates.
- **Uploaded logos:** sent with the quote request, one per position, stored privately with the quote.
