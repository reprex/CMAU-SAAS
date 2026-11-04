# Vet: one screen for what still needs a person

Scott, 5 Nov 2026: "The intention is to make this fully automated through the techniques we've introduced
(scanned supplier website product pages, garment widths, templates). One off checks and small exception
lists are ok, but I want those presented in SaaS on a single vet screen."

The menu's **Vet** (`/vet`, `App\Catalogue\VetQueue`) replaces Changes to vet, Positions, Logo areas,
Decoration types and Price check in the menu; those screens stay as drill-downs from Vet. The badge counts
the sections that need a decision.

Needs a decision:

| Section | Comes from | Automatic part |
| --- | --- | --- |
| New products from suppliers | Range › Changes (`range:changes`) | fetched, priced and checked against similar products; "Approve all ready" |
| Discontinued with open quotes | Range › Changes | closest substitutes found |
| Decoration types | `catalogue:classify` nightly (docs/decoration-types.md) | types filled from supplier pages and rules; listed: a set type the pages disagree with, wording no rule covers |
| Branding templates not read | `catalogue:read-templates` | read 20 a minute; listed: failed reads (Read again), files too large or not PDFs |
| Shopify uploads that failed | publish log | retried automatically |
| Price check | price patterns; `pricing:verify-pages` nightly | Dex and Legend Life verified from their pages' blank prices; listed: patterns still unconfirmed |

For information (nothing to decide): add-on lines no supplier rule groups yet (Claude adds rules), and
mockups: how many range products get a mockup (template, staff, garment size chart) or a guide card.
