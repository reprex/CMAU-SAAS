# Lead times (Scott, 5 Nov 2026)

"The lead time is as important for a lot of clients as the product selection and pricing." Getting it right
should lift quote-to-order and general conversion on crowdmerch.com.au. To be refined together, step by step.

## Rules
- **Per decoration, never one figure per product.** The lead time depends on the branding chosen, and it
  updates as the client picks or changes branding options.
- **More than one decoration is not cumulative.** The first decoration sets the time (e.g. 7–8 working days);
  each extra decoration adds a little (e.g. 1–2 days), not its own full time. Exact figures to be worked out.
- **Seasonal.** Every supplier's lead times stretch as Christmas gets closer and everyone is at capacity.
- **Transit on top.** Some suppliers ship from overseas straight to the client (Trends from New Zealand).
- **Within a couple of days is fine**, except where the speed is what the client is buying: Dex's same day,
  3 day, 7 day production options are separate choices on the same product (already product options; the
  chosen speed replaces stock and branding time in the estimate).
- **Promodata first.** Its lead times are now better, often per decoration line. Supplier websites (some
  need Scott's login) only where Promodata is missing or too general. AS Colour and JB's: use the lead
  times of the decoration they are branded with (Melbourne Merch's methods).

## Figures (Scott, 5 Nov 2026)
- **Trends:** ships from New Zealand straight to the client: **4 working days** transit, on top of production.
  Trends publishes every decoration's lead time on its site and keeps them accurate: poll those pages for the
  Trends range (rather than Promodata). The page is trends.nz/lead-times; it can't be read from the server's
  side without a browser (probably the distributor login), so it is read in Scott's Chrome, signed in.
- **Each extra decoration: +1 working day** (normally none, but one as standard to be safe).
- **Christmas stretch: +2 working days from the end of October** for most items. Not added where the supplier
  sells a stated speed (Dex same day, 3 day, 7 day…).

## Where it shows
1. **Product page (PDP):** prominent, and recalculated as branding options are chosen.
2. **Search:** find products that can be delivered by a given date.
3. **Order screen (checkout):** the most accurate figure we can give someone ready to order now, with the
   delivery date (the existing delivery estimate: order check, stock, production, freight).

## The calculation (planned)
production = first decoration's days (Promodata line, else the supplier's rule for that method and season,
else the method default) + extra days for each further decoration; + stock days where the supplier says so;
+ transit (overseas to Australia, e.g. NZ for Trends) or the domestic freight the checkout already works out.
Dex: the chosen production speed instead.

## Steps
1. `catalogue:lead-time-report` (read-only): what Promodata has, per supplier, on the live range.
2. Supplier rules from their websites where Promodata falls short: days per method, seasonal changes
   (dated), ships from, transit days; re-read on a schedule, changes of more than 2 days to the Vet screen.
3. A new additive Shopify field with the days per method plus extra-decoration and transit days; the theme
   shows it on the PDP, search and checkout; `custom.leadtimedays` stays as is.

## Open questions for Scott
- When the Christmas stretch ends (assumed: through the Christmas shutdown, until production restarts in January).
