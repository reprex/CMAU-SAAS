# Price check: is Promodata's base price branded or unbranded?

Built 31 Oct 2026. Scott: Promodata includes the decoration cost in the base price for some products and not others, even from the same supplier, and suppliers' own APIs present prices the same way. Checking 5,000 products by hand was impossible in TOD. So the SaaS groups products by how their prices are built and learns from screengrabs of the suppliers' distributor prices that Scott provides. Scope: every supplier on the live range (Trends, Dex Group, Logo Line, Shiny, AS Colour, JB's Wear) plus Legend Life once it is added.

No stored supplier logins: suppliers' terms usually forbid automated access, and passwords would sit on a shared host. Screengrabs are taken by Scott in his own browser.

## How it works

1. **Patterns.** Whenever a live or kept product is priced, it is given a price pattern: the supplier plus, for each Promodata base price line, its wording (lower case, numbers replaced by #), whether it is marked undecorated, whether it names a decoration method, whether it has a setup charge and whether it has add-on lines. Add-on wording is ignored (surcharges vary by product). Products in one pattern should be priced the same way.
2. **Flags.** Products that don't fit: a branded base cheaper than the unbranded line; a unit cost that rises with quantity; branded ÷ unbranded more than 1.5 times above or below the pattern's median (patterns of 3+); prices changed in Promodata since the product was checked.
3. **Screengrab list.** Admin → Price check lists the patterns still to check, biggest on-range first, each with two example products (live first, then most recently used): the supplier page link and the file name to save the screengrab as, `<supplier id>-<supplier code>.png`. "Download screengrab list" gives the same as a CSV with empty verdict/notes/evidence columns.
4. **Record what it showed**, per example product:
   - `base_branded`: the base price includes branding;
   - `base_unbranded`: the base price is the blank price, branding is charged on top;
   - `differs`: Promodata's prices don't match the supplier's (say how in the notes).
   Enter it on the screen, or upload the CSV back (columns `appaid, verdict, notes, evidence`; extra columns are ignored). Claude can read a folder of screengrabs and fill the CSV.
5. **Status.** A pattern is verified when its checks agree; checks that disagree mark it "Checks disagree", which usually means the pattern needs splitting or the products are exceptions. The summary shows, per supplier, patterns verified and the share of products covered.

Patterns are recounted nightly at 04:00 (`pricing:patterns`), when examples and outliers are refreshed.

## Not yet

- **Using the verdicts in pricing.** Once enough patterns are verified, the verdicts become pricing rules (per pattern, with per-product exceptions), replacing guesses. Kept as a separate step so the rules are designed from real verdicts.
- Reading screengrabs inside the SaaS (Anthropic API) instead of in a Claude session.
- Multiple decorations per item (quotes first, then optional DecorationJSON entries the current theme ignores, then the PDP). The single decoration drop-down stays on the site for now.

## Commands

```
cd ~/saas-app && /usr/local/bin/ea-php84 artisan pricing:patterns --assign     # once, after the TOD import
cd ~/saas-app && /usr/local/bin/ea-php84 artisan pricing:patterns --assign --supplier=57   # e.g. after adding Legend Life
```
