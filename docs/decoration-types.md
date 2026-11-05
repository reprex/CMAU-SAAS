# Decoration types: set automatically

Scott, 5 Nov 2026: "most of the reason I'm giving you access to the distributor pages is so I never have
to manually assign 10 11 12 23 to pricing lines ever again".

Every supplier price-line description has a decoration type: 10 decoration, 11 option (accessory),
12 undecorated, 23 hidden. TOD kept one type per wording for every supplier (`dec_type_codes`), which
forced the same wording to mean the same thing for all of them. Scott, 5 Nov 2026: "this was always a
weakness in the TOD system and was only done out of a need to keep the manual maintenance of prices to
some reasonable limit. now that you can handle this via supplier product webpages, there is no need to
handle pricing via the old DecTypeCode method."

So types are now **per supplier** (`supplier_dec_types`: supplier, wording code, type, why).
`catalogue:classify` (`App\Pricing\AutoClassifier`) works them out for the suppliers whose pages we
have read (an option template in `database/data/option-templates/`), in this order:

1. the supplier's pricing rule (`config/pricing.php` supplier_rules, e.g. Logo Line's TOD rule);
2. a production time the supplier doesn't offer (Dex 28 days, offshore) is 23, add-on lines included;
3. the supplier's option template: an option is 11; a branding line the template takes over (Logo
   Line packaging) stays 10;
4. a listed production time (Dex) is 10 (12 for its blank price);
5. a method the supplier lists as offered for its styles (Legend Life) is 10;
6. Promodata's blank price is 12;
7. wording that names a branding method, or a transfer or print under a supplier's own name
   (Colourflex, DigiFlex), unless it is a charge on top (setup, sample, surcharge, additional, extra, fee,
   freight, proof…), is 10.

Rules:

- Pricing uses the supplier's own type first, then TOD's shared table (`PriceOptionBuilder`), so
  suppliers whose pages aren't read yet (Shiny, AS Colour, JB's Wear) work as before.
- The classifier never writes TOD's shared table; one supplier's reading never changes another's.
- A type staff set for a supplier (`staff` = true) is left alone.
- The output lists what changes what pricing uses now, grouped by supplier, old → new type and reason,
  and what no reading covers. `--apply` saves and reprices only the products whose type changed (queued,
  worked through by the minute cron). Nightly at 03:45: `catalogue:classify --apply --show=0`.
- What no reading covers is on the Vet screen; it gets a new rule from that supplier's pages, never a
  hand-set type where a rule can say it.

Commands (server):

```
cd ~/saas-app && /usr/local/bin/ea-php84 artisan catalogue:classify             # what it would do, and what is left
cd ~/saas-app && /usr/local/bin/ea-php84 artisan catalogue:classify --apply     # save and reprice
cd ~/saas-app && /usr/local/bin/ea-php84 artisan catalogue:classify --supplier=3 --show=100   # one supplier, longer lists
```
