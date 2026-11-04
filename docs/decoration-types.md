# Decoration types: set automatically

Scott, 5 Nov 2026: "most of the reason I'm giving you access to the distributor pages is so I never have
to manually assign 10 11 12 23 to pricing lines ever again".

Every supplier price-line description has one decoration type (TOD's DecTypeCode, kept in
`dec_type_codes`, shared by every supplier that uses the same wording): 10 decoration, 11 option
(accessory), 12 undecorated, 23 hidden. `catalogue:classify` (`App\Pricing\AutoClassifier`) works
them out for the suppliers whose pages we have read (an option template in
`database/data/option-templates/`), in this order:

1. the supplier's pricing rule (`config/pricing.php` supplier_rules, e.g. Logo Line's TOD rule), which
   already decides the type when the product is priced;
2. the supplier's option template: an option is 11; a branding line the template takes over (Logo
   Line packaging) stays 10;
3. a production time (Dex): a listed time's line is 10; another time (28 days, offshore) is 23;
4. a method the supplier lists as offered for its styles (Legend Life) is 10;
5. Promodata's blank price is 12;
6. wording that names a branding method, unless it is a charge on top (setup, sample, surcharge,
   additional, extra, fee, freight, proof…), is 10.

Rules:

- Only descriptions with no type are filled in. A type already set that disagrees with the supplier is
  listed, never changed: change it under Admin › Decoration types if the supplier is right.
- Wording two suppliers would type differently is left for staff.
- Saving reprices every product that uses the description (queued, worked through by the minute cron).
- Nightly at 03:45: `catalogue:classify --apply --show=0`. New wording from Promodata is typed the
  next morning.
- What is left goes into a new rule from that supplier's pages, never a hand-set type where a rule can
  say it.

Commands (server):

```
cd ~/saas-app && /usr/local/bin/ea-php84 artisan catalogue:classify             # what it would do, and what is left
cd ~/saas-app && /usr/local/bin/ea-php84 artisan catalogue:classify --apply     # save and reprice
cd ~/saas-app && /usr/local/bin/ea-php84 artisan catalogue:classify --supplier=3 --show=100
```
