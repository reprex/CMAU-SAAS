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

The product page offers these positions (with their maximum sizes) instead of the generic Front centre / Left chest, and a Front / Back switch when a back position exists.
