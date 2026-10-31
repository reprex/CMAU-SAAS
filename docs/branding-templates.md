# Supplier branding templates

Suppliers publish a branding template (PDF or line drawing) for most products: every branding position (front centre, left chest, back, sleeve, lid, wrap…), its maximum print size and often the decoration methods. Where one exists it is used for **precise logo placement** and to show **every position available** on the product page.

## Where templates come from

1. **Promodata line art** (`product.line_art`), stored on every sync (kept apart from the product photos).
2. **The supplier's template address**: Admin › Suppliers › supplier › "Branding template address", with `{code}` where the product code goes (also `{CODE}` upper case, `{appaid}`). Web-range products are checked a few a minute; a template that exists is added.
3. **Staff**: Range › product › Branding template › paste a URL › Add.

## Reading a template

`catalogue:read-templates` (every minute, two templates a run, sharing the image check's daily cap) sends Claude the template with our front picture (hero) and back picture (an other style image marked as a back view). Claude returns each position: name, view (front, back, side, top), maximum print size in mm, decoration methods, and where it sits on our picture (percent). Range › product shows the positions; "Read now" / "Read again" / "Remove".

- The first front position becomes the product's **logo area** (source `template`): it is published without vetting and the sample learning never changes it. Staff can still adjust and approve it on Logo areas.
- Positions without a matching picture (e.g. a sleeve with no side photo) keep their name and size, without a box.
- Reading a template marks a live product for publishing.

## What Shopify gets

- `custom.brandingpositions` (json): `[{"name":"Front centre","view":"front","image":"…","left":35,"top":28,"width":30,"max_mm":[280,350],"methods":["Screen print","DTF"]}, …]`
- `custom.brandingtemplate` (url): the template, for a "View the supplier's branding template" link.

## Branding suite and pricing (Scott, 1 Nov)

The product page has one **Branding** section (below the colours, not under the pictures). Every position from the template can be switched on; each has its own decoration method (DTF, oversize DTF, screen print, embroidery, as the product's decoration options and the template allow for that position) and can have its own logo. The pictures and a small preview in the section update as choices are made.

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
