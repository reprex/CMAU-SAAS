# Logo preview on the website

Every product's hero image shows the **CrowdMerch logo** where the branding goes. When a shopper taps "Try your logo" and picks their own logo or design, it **replaces the CrowdMerch logo in the same place and size** on collection pages, the product page and My list. The shopper's file stays in their browser; it is only sent to us if they include it with a quote request or an order.

## Branding positions and logo areas come automatically (Scott, 1 Nov 2026)

Every product gets its branding positions (`custom.brandingpositions`) and its logo area (`custom.printarea`) **without manual vetting**. This replaces the earlier plan to vet about 100 logo areas by hand and learn the rest. `App\Catalogue\PositionResolver` works them out, in this order:

1. **A staff-approved logo area** (Range › Logo areas, still there and optional). It always wins, and moves the first front position's box.
2. **The supplier's branding template**, read by Claude (docs/branding-templates.md).
3. **The print areas in Promodata's own decoration data** (`App\Catalogue\DecorationAreas`), below.
4. **The product type default** (`PrintAreas::DEFAULTS`), for a product with decoration options but no stated print area.

A product with no decoration options gets no positions and no logo area. For the logo area only, an area learned from vetted samples (if staff vet any) comes after the decoration data, and Claude's image suggestion (`catalogue:check-images`) replaces the type default.

## Print areas from Promodata's decoration data

Promodata keeps each product's decoration options as price lines (description and `promodata_decoration`; stored in `price_lines`), the SaaS decoration options built from them (`price_options`, whose `option_id` is DecorationJSON's `DecID`), and often a detail such as "Print Area" or "Decoration Areas" (`product_details.details`). Their wording usually carries a size, e.g. the Trends Neoprene Stubby Holder (104743): "Full colour printing (sublimation print area: 209mm x 108mm)".

`DecorationAreas::parse()` reads each text into areas: **position** if stated, **method**, **max width × height in mm**.

| Handled | Example |
| --- | --- |
| Width × height with mm, cm or inches, the unit on each number or once at the end | "209mm x 108mm", "Wrap: 209 x 108 mm", "6cm x 3cm", "4,5 x 2cm", `2" x 1"` |
| "x", "×", "*" or "by" | "100mm by 50mm", "50 × 20 mm" |
| W and H marks, in either order | "40mm (w) x 15mm (h)", "70mm W x 25mm H", "H 40mm x W 30mm", "300w x 400h mm" |
| No unit, when an area word is next to it | "Print area: 60 x 40" (mm) |
| Diameters | "50mm diameter", "Ø 45mm", "50mm dia" (width = height, `shape: circle`) |
| Paper sizes | "DTF print up to A3" (297 × 420) |
| Several positions in one text, the method carried forward | "DTF: Front 280 x 350mm, Back 280 x 350mm, Sleeve 80 x 80mm" |
| A method per position, or after the size | "Screen print front 280x350mm; embroidery left chest 90 x 90mm", "Front 250 x 300mm (screen print)" |
| Position names | Wrap around, Front, Back, Left / Right chest, Back neck, Upper back, Left / Right sleeve, Sleeve, Pocket, Lid, Peak, Barrel, Clip, Handle, Strap, Flap, Cover, Base, Top, Side |
| Methods (existing keys where they exist) | Embroidery, Oversize DTF, DTF, Sublimation, Screen print, Pad print, Laser engraving, Deboss / emboss, Foil stamp, Heat transfer, Full colour digital (full colour, digital, UV, CMYK, DTG) |
| The price line's `promodata_decoration` | used as the method when the text names none |
| HTML and entities | stripped |

Not handled (gives nothing rather than a guess): text with no size ("Screen Print 1 Colour 1 Position", "Embroidery up to 8000 stitches"); three-number sizes ("40 x 30 x 20 cm": a product or carton); unitless pairs with no area word ("2 x 3"); sizes in words; metres; a position named only after the size in a later clause; a single number that isn't a diameter ("up to 100mm wide"); "Front and back: 280 x 350" is read as Back only.

**Building positions** (`PositionResolver::fromDecorations`):

- Areas with no position go on the product type's default position: **Wrap around** for stubby holders and can coolers, **Barrel** for pens, else **Front**.
- An area with no method (e.g. a "Print Area" detail) applies to every method the product offers.
- Each position keeps **each method's own max size** (`method_areas`): DTF and Oversize DTF have different print areas on the same front (Scott). The position's `max_mm` is its largest. Methods named with no size (e.g. embroidery) are still listed on the default position, without a size. Each method lists the product's matching `decIDs`.
- **Box on our picture:** the type default box (`PrintAreas::DEFAULTS`; stubby holders 32/36/36). When Promodata gives the product's dimensions (`products.dim_1`, largest first, from ProductFacts), the box width = print width ÷ largest dimension × 80% (the product's largest side fills about 80% of a square photo), kept on the default's centre; a wrap is seen across the front only, so its width is divided by π. Without dimensions the type default is used as is. Front positions use the hero image, back positions the back picture (as for templates); other views have no box. Left chest, right chest and pocket sit off-centre.

The stubby holder gives one position: **Wrap around**, sublimation, 209 × 108 mm.

## Logo areas screen (Range › Logo areas), optional

Each hero image can have a stored area (`print_areas`, keyed by image URL so Promodata refreshes keep it): left, top and width in percent, with its source (`default`, `ai`, `decoration`, `template`, `learned`, `staff`).

- **Approve any product** to place its logo by hand: drag the CrowdMerch logo into place, size it, Approve ("White logo" helps on dark products). That always wins.
- **Optional sample learning:** "Pick 100 samples (optional)" spreads samples across suppliers and product types; staff vet them; the median correction of the closest group (same supplier and type with 2+ vetted, else same type, else all 10+) is applied to products that only have a prediction (`default` or `ai`). Template and decoration areas are exact and are never changed by learning. "What was learned" and "Add 25 more samples" work as before.
- `catalogue:logo-areas --predict` stores a prediction for every web-range product (its decoration area if it has one, else the type default). The scheduler runs `catalogue:logo-areas` every 10 minutes, which only re-learns after an approval; the `--sample` step is not scheduled and coverage doesn't depend on it.

## What Shopify gets

- `custom.printarea` (json): `{"image": "<hero url>", "left": 37, "top": 30, "width": 26}` (percent of the image), by the priority above (`PrintAreas::forShopify`). The theme places the logo over the hero image in that box only while the hero image shown is that `image`; product page controls start from it.
- `custom.brandingpositions` (json): every position, see docs/branding-templates.md.

## Curated positions (Range › Positions, 2 Nov 2026)

Staff can set the positions for a product type, or for one product (its Range page). A curated rule beats everything automatic, the supplier template included: product rule, then type rule, then template, Promodata print areas, garment extras (back, sleeves, back neck) and the type default. Each curated position keeps the decoration methods the product offers, capped at the size set, and the box of the automatic position of the same name (else the type default box). Saving marks the covered live products to publish again; clearing every name removes the rule.

`catalogue:positions-report` (nightly 04:30, read-only) counts where the live range's positions come from and lists the product types relying on the type-default guess; Range › Positions shows those counts.

## Garments: mockups to scale (Scott, 3 Nov 2026)

Garment mockups draw each logo to scale against one stated size: **L** (womens **14**, kids **10**).

- **Chest width:** the supplier's size chart wherever the product data has one (`App\Catalogue\GarmentSizes` reads the Promodata details and description: "Chest (1/2): S 52, M 55, L 58", "Half chest: S-52cm…", a "Size | S | M | L" table, inches, mm, or full chest figures, which are halved). It is published as `custom.mockupgarment`, e.g. `L:58`. Without a chart the theme uses a typical width for the garment type (`CM.GARMENT_CM`: tees 56, polos 56, hoodies 60, jackets 62, singlets 52; womens and kids their own).
- **The photo:** the theme measures the garment body in the colour photo just under the arms (`CM.garmentSpan`); a pale colour on white is measured from a darker colour of the same product.
- **The logo:** its width in mm ÷ the chest width × the body's width in the photo, on the position's centre.
- **The note** under the mockup images: "Mockup to scale for size L, 56 cm wide across the chest (supplier size chart). Your print will look larger on smaller sizes."
- **Check coverage:** `catalogue:garment-sizes` (read-only) counts garments with and without a chart and shows size text it couldn't read; `--product=ID` shows one product.

## Size guide on the product page (Scott, 3 Nov 2026)

`App\Catalogue\SizeGuide` reads the fit ("Fit" detail, a "Fit: …" attribute, or "regular fit" in the description), the measurements (chest, ½ chest, bust, length, sleeve, shoulder, waist, hip, inseam, neck; per size, in cm, from the details or a table in the description) and the supplier's size chart (a product image named size chart / size guide, or a link in a size detail). It is published as `custom.sizeguide` (json: fit, sizes, rows, highlight, image, link, notes). The theme shows it as a "Size guide" concertina above Product specifications (`snippets/cm-size-guide.liquid`), with the mockup size highlighted. The lines it uses are left out of Product specifications and the description.
