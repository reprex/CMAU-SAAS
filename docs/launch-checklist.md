# Before launch (CMAU)

Things needed before crowdmerch.com.au goes live on the new theme. Add to this list; tick off in the commit that does it.

- [ ] **Decoration methods section on CMAU_DEV** (Scott, 3 Nov 2026): a page or section that describes every decoration method we offer, with example images of each: screen print, pad print, embroidery, DTF and oversize DTF, digital / full colour, laser engraving, deboss / emboss, sublimation, labels, doming, foil, heat transfer, and the supplier-specific methods on the range (Legend Life's Supacolour, SupaDTF, Supaetch, Supaflex, Supasub). Link it from the product page's Branding section and from Decoration types in the SaaS.
- [ ] Field-level Shopify updates (reminder set for Thu 5 Nov, docs/publisher.md).

# After launch

Planned, not before go-live (Scott decides when). Add to this list.

- [ ] **TidyCal bookings into the SaaS** (Scott, 5 Nov 2026; TidyCal's API and webhooks became free on 3 Nov): a shared module for all three divisions. A webhook endpoint (signed, checked like the Shopify ones) takes each "Book a chat" booking: matches or adds the contact (ROD Contacts as client master once it moves in), opens a quote or project request with the booking notes in the right division, and shows the call on the dashboard and quote desk with the client's lists, quotes and orders. API docs from TidyCal's developer pages.
- [ ] **TidyCal connector in Claude** (Scott's settings: Connectors › add custom connector › `tidycal.com/mcp`, sign in and approve) so Claude can say what calls are booked and when Scott is free, and prepare for a call from the SaaS. Bookings are only made or changed when Scott asks.
