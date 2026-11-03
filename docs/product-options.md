# Product options (not branding)

Built 3 Nov 2026 (Scott): "the price data still comes from Promodata, but the logic comes from the supplier's own product webpage."

Many products have priced lines that aren't branding: a lanyard's clips and holders, gift boxes and pen sleeves, extra embroidery stitches and metallic thread, personalisation (a name per item), data upload on flash drives, a pre-production sample, kitting, flag bases and more. Promodata lists them as flat price lines with no rules. The supplier's own product page says how they combine: what comes as standard, what replaces what, what can be added, and what goes with which branding method.

## Where the logic lives

`database/data/option-templates/<supplier>.json`, one file per supplier:

- `groups`: label, `pick` (`one` = choose one, `many` = add any, `count` = a stepper), `optional`, `with_method` (only shown when a branding position uses one of these methods, e.g. embroidery extras), `note`, `order`.
- `lines`: rules that put each Promodata line into a group by its code (the description in lower-case letters and digits, without the APPAID prefix), first match wins. A rule can say `included` (comes as standard, shown ticked), `fixed` (can't be removed), `per` (`order` or `artwork` instead of per unit), `count` (a quantity, e.g. extra 5,000 stitches × n), `per_stitches` (metallic thread: per 1,000 stitches of the design), `excludes` (ID holder vs Duet ID holder), `requires`.

Branding lines (type 10) stay in DecorationJSON and are never options; hidden lines (23) are left out.

### Trends (supplier 3)

Read from the Trends distributor site (Pricing tab, Additional Costs down to Shipping and Handling) for all 2,443 Trends range products on 3 Nov 2026. 102 kinds of non-branding line, all covered by 61 rules in 9 groups: clip, attachments, embroidery extras, personalisation, packaging, data upload, extras, base (flags), before production (sample). The lanyard set-up comes from Trends' Attachments picture: standard is a single metal clip and a safety clip; every other attachment is optional.

The same read checked Promodata's base prices against Trends' own page: 2,295 of 2,311 matched to the cent (the other 16 are flat-priced items with no quantity breaks to compare), and Trends' price headings ("Unbranded" or "Including full colour printing") were recorded as Price check verdicts (docs/price-check.md).

### Logo Line (supplier 2)

Read from the Logo Line distributor site (Price tab: Options and Add-Ons, where each option carries its group type, maximum, prerequisites and incompatibles) for all 695 Logo Line range products on 3 Nov 2026. Promodata gives Logo Line's branded totals as plain lines (hidden by TOD's rule) and the add-on amounts as "ADD …" lines (type 10). Packaging among those "ADD" lines (velvet pouches, cardboard sleeves, pen boxes, gift boxes, EVA cases, glow pouches, backing cards, toothbrush cases, bamboo lids, combo cables) and printing on that packaging were being offered as branding methods. The Logo Line rules mark them `branding_line`: `ProductOptions::claimed()` takes them out of DecorationJSON, branding positions and checkout branding, and they become options with their price and markup unchanged. Print on optional packaging is shown only once that packaging is chosen (`requires_any` → `needs`). Variable Data (a name per item) is personalisation.

Logo Line's undecorated prices on its own pages matched Promodata for 669 of 681 products; the 12 that differ are recorded as "differs" in Price check.

### Dex Collection (supplier 19)

Read from the Dex distributor site (Price (AUD) tab: Local Pricing with Same Day, 24 Hours, 3, 7 and 12 Working Days tabs, each with its own decoration costs and a min/max quantity per service) for all 838 Dex range products on 3 Nov 2026. Promodata repeats every branding method once per production time ("Same Day Print Per Col/Pos" … "12 Days Print Per Col/Pos"), all type 10, so the product page offered each time as a separate method. The blank price is the same for every local time.

`dex.json` has a `speed` block instead: the customer picks a **production time** and each position is priced from that time's line. `ProductOptions::speedFor()` sends `custom.options.speed`: per time its name, working days, the line ids and Dex's min/max quantity per line (`database/data/supplier-pages/dex-ranges.json`, from Dex's pages). The theme merges the per-time copies of a method into one, starts on the time of the default decoration (so the page agrees with the card price; TOD's defaults are kept), disables a time outside Dex's range for the chosen branding, and keeps the time in the cart. Checkout refuses a time outside the range or two times on one order, and the delivery estimate uses the time's working days for stock and branding together. The 28-day and longer offshore lines (hidden, with their own CE blank price) aren't offered. Dex's same-day dispatch charge ($50 + GST per order) isn't in Promodata: the Same day option says it will be confirmed. Dex's add-on items (bottle tubes, cup boxes, NFC, gloss) aren't in Promodata either, so they aren't offered.

### Legend Life (supplier 57)

Read from the Legend Life distributor site for all 936 Legend Life products on 3 Nov 2026: the Decoration tab (methods and areas) and the Quoting Tool's price list for each style (every method, size or stitch tier, quantity breaks, setup). Promodata's lines match those prices, breaks and $40 setups wherever both have one, and every Promodata line was already typed (10 or 12), so nothing needed classifying. Decoration is from 20 units; Promodata marks that with a leading zero break, which `PriceString` now reads as "from the next break".

- Methods: Supacolour (full colour transfer), SupaDTF (DTF), Embroidery, Supaetch, Supaflex, Supasub, Sublimation; the SaaS and theme know them (`DecorationAreas`, `website.methods`, theme `METHODS`).
- Size tiers: each method's lines are one method on the product page, priced from the smallest tier the logo fits either way round (`CM.tierFor`); embroidery starts at the lowest stitch tier.
- `legendlife.json` has `offered`: Legend Life's own method list per style (`supplier-pages/legendlife-offered.json`). A Promodata method not on that list (54 products, e.g. Supacolour on ST9010) is taken out of branding by `ProductOptions::claimed()`.
- Not covered: the 12 umbrellas (2005–2140) have no decoration lines in Promodata (Legend Life prices them per panel); 50 products' unbranded prices start at 10 in Promodata but at 1 on Legend Life. Legend Life has no branding templates (its downloads are an info sheet, size chart and image pack). The Melbourne Merch rule for up to 19 units is not used (Scott, 3 Nov 2026).

## What the SaaS sends

`custom.options` (JSON, product metafield): `{v, source, groups: [{key, label, pick, optional, with_method, note, max, unit_label, base_stitches, options: [{id, name, included, fixed, breaks, setup, per, count, per_stitches, excludes, note}]}], notes}`. `breaks` is the line's sell price per unit by quantity and `setup` its sell setup, both SaaS pricing. Products with no matching lines get no metafield and the product page is unchanged.

## Product page, cart and orders

The product page shows an Options section under Branding. Included parts cost nothing; chosen options add to the price each, setups and per-artwork charges add to the setup, and the branding line names them ("…, with J hook clip and ID holder"). The choices go with the cart and quick quote.

At checkout the SaaS prices the chosen options itself from the product's lines (`CheckoutPricer::options`), never from the page's numbers. An option the product no longer has stops the order with an issue. Website orders keep the priced options (`website_orders.options`); staff quotes get a line per charged option.

## Commands

```
cd ~/saas-app && /usr/local/bin/ea-php84 artisan catalogue:options-report --supplier=3        # coverage and lines no rule covers
cd ~/saas-app && /usr/local/bin/ea-php84 artisan catalogue:options-report --product=2084      # one product's custom.options
cd ~/saas-app && /usr/local/bin/ea-php84 artisan shopify:metafield-definitions                # once: adds custom.options
```

After adding or changing a template, mark the products that get options to publish again:

```
cd ~/saas-app && /usr/local/bin/ea-php84 artisan catalogue:options-report --supplier=3 --mark
cd ~/saas-app && /usr/local/bin/ea-php84 artisan catalogue:options-report --supplier=2 --reprice --mark   # Logo Line: reprice first (packaging leaves DecorationJSON)
```
