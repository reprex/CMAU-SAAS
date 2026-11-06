# Before launch (CMAU)

- [ ] **External ranges on CMAU** (Scott, 5 Nov 2026; docs/external-ranges.md): card printing (Creative Cards), pins, coins, medals and key rings (SP Pins and Coins), USB, power banks and other electronics (Begif), from a spreadsheet per supplier imported beside Promodata.
- [x] **Lead times per decoration on the PDP, search and checkout** (Scott, 5 Nov 2026; docs/lead-times.md): v1 built 7 Nov (Trends page reading and supplier shutdown dates still to come).
- [ ] **Live freight: MachShip and FedEx** (Scott, 7 Nov 2026; docs/freight.md): built 7 Nov. Needs `MACHSHIP_TOKEN` (the token ROD uses) and FedEx API keys (developer project with the Rate API, account number) in the server .env, then `st freight` shows live prices for VIC and NZ.
- [x] **Delivery on website quotes** (Scott, 7 Nov 2026): yes/no, Australia by postcode or any country; exports GST-free with the duties note; estimated weights flagged; next price breaks on the quote.
- [x] **Supplier purchase orders** (Scott, 7 Nov 2026; docs/purchase-orders.md): built 7 Nov from website orders. Scott fills each supplier's orders contact and email (`st po` lists them).
- [ ] **Switch to the live store** (docs/publisher.md, "Switching to the live store"): variant-match and carry-tags reports from a live Matrixify export, then switch-store, metafield definitions, collections sync, a first batch of 50.
- [ ] **Store menus** (docs/menus.md): add the navigation scopes to the app, then `st menus` and `st menusgo` on CMAU_DEV now, and again on the live store after the switch.

Things needed before crowdmerch.com.au goes live on the new theme. Add to this list; tick off in the commit that does it.

- [ ] **Decoration methods section on CMAU_DEV** (Scott, 3 Nov 2026): a page or section that describes every decoration method we offer, with example images of each: screen print, pad print, embroidery, DTF and oversize DTF, digital / full colour, laser engraving, deboss / emboss, sublimation, labels, doming, foil, heat transfer, and the supplier-specific methods on the range (Legend Life's Supacolour, SupaDTF, Supaetch, Supaflex, Supasub). Link it from the product page's Branding section and from Decoration types in the SaaS.
- [ ] Field-level Shopify updates (reminder set for Thu 5 Nov, docs/publisher.md).
- [x] TOD tagging carried from the live store (`shopify:carry-tags`), client quotes show our code, live stock rows without a size fixed (7 Nov 2026).

# After launch

Planned, not before go-live (Scott decides when). Add to this list.

- [ ] **TidyCal bookings into the SaaS** (Scott, 5 Nov 2026; TidyCal's API and webhooks became free on 3 Nov): a shared module for all three divisions. A webhook endpoint (signed, checked like the Shopify ones) takes each "Book a chat" booking: matches or adds the contact (ROD Contacts as client master once it moves in), opens a quote or project request with the booking notes in the right division, and shows the call on the dashboard and quote desk with the client's lists, quotes and orders. API docs from TidyCal's developer pages.
- [ ] **TidyCal connector in Claude** (Scott's settings: Connectors › add custom connector › `tidycal.com/mcp`, sign in and approve) so Claude can say what calls are booked and when Scott is free, and prepare for a call from the SaaS. Bookings are only made or changed when Scott asks.
