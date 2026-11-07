# Supplier purchase orders

Scott, 7 Nov 2026: as simple as possible. From a website order (Website orders › order › **Create purchase order**):

- **Costs**: supplier cost ex GST from the catalogue's cost prices at the order's total quantity: each style's
  unbranded cost, each branding position (per colour where the method is priced per colour), setups, and the
  options the client chose. A line with no cost shows "–" and a warning.
- **Freight is left out** ("to be advised"): the supplier replies with the actual cost, unless it's in their
  pricing, and staff add it on the PO page (Freight and notes); the total updates.
- **Delivery**: the client's address and phone. **Blind ship, always**: no supplier name, logo, invoice, packing
  slip or prices on or in the cartons; the sender shows as CrowdMerch.
- **Deadline**: despatch by (the end of production in the delivery estimate), the client's need-by date, and the event.
- **Artwork**: a link (`/po-artwork/<token>`, no login, 120 days) listing each position with its file to download.
  Every download is logged on the order.
- **Sending**: Send to supplier emails the PO PDF to the supplier's **orders email** (staff click; never automatic),
  from the mailbox, replies to `QUOTE_EMAIL`. Sent POs show on the order.
- **Supplier details** (Admin › Suppliers): orders contact, orders email, our account number with them.
  `st po` (`php artisan suppliers:po-check`) lists suppliers with live products and what's missing.

Not yet: POs from quote desk orders, decorators separate from the supplier, PO numbers in Xero.

## Purchase orders through another supplier (Scott, 7 Nov 2026)
- Admin › Suppliers › "Purchase orders go to": e.g. AS Colour (88) and JB's Wear (68) go to Melbourne
  Merch (999, a decorator). The PO, its email and the dispatch address used for delivery prices are
  Melbourne Merch's. `st po` shows the "PO to" supplier and its details.
