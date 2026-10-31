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

## Price stays with the branding option (Scott, 1 Nov)

Positions never change the price. The product page keeps the single branding choice from DecorationJSON, exactly as the current site, because that is the pricing we trust. Each branding option says how many positions it covers and which template positions suit it:

- **How many:** from the option's name ("Front and back", "2 positions", "both sides" = two: one front, one back; otherwise one).
- **Which:** template positions whose methods match the option (embroidery positions for an embroidery option; any position when the template names no methods), filtered by view for two-position options.

The shopper picks where (Front centre, Left chest, Back, Back neck…, with the maximum sizes shown); the price is the option's price. Positions the options don't cover are offered as "Other positions? Mention them in your quote and we'll price them", which staff price on the quote desk. If pricing data for extra positions becomes reliable later, it can be added as additional DecorationJSON options without changing this.
