# Freight (MachShip and FedEx)

Scott, 7 Nov 2026: delivery is on website quotes and the checkout as far as practical, priced live by **MachShip** in
Australia (TNT, the same account and services as ROD: REPSTACK "Machship API integration with ROD.mdb") and **FedEx**
overseas (Replicat's account rates). The SaaS does the pricing; the website never talks to the carriers.

## Drivers (`App\Freight\FreightDriver`, `FREIGHT_DRIVER`)
- `carriers` (default): Australia → `MachshipFreightDriver` when `MACHSHIP_TOKEN` is set; overseas → `FedexFreightDriver`
  when `FEDEX_CLIENT_ID`, `FEDEX_CLIENT_SECRET` and `FEDEX_ACCOUNT_NUMBER` are set. Without keys, the placeholder
  `EstimateFreightDriver` (config/freight.php) prices it and the quote says it's an estimate.
- A carrier that fails (down, unknown postcode) gives no price: the quote shows "Delivery … (to be confirmed)" and the
  checkout sends the shopper to a quote. Errors go to the log (`machship …`, `fedex …`).
- `st freight` (or `php artisan freight:test AU 3066`, `freight:test NZ 1010`) prices 100 tees with the live driver.

## MachShip
- `POST routes/returnroutes` with header `token`; `fromLocation` = `FREIGHT_FROM_SUBURB` / `FREIGHT_FROM_POSTCODE`
  (default Derrimut 3026, Scott to confirm), `toLocation` = the postcode's first suburb (`GET locations/returnLocations?s=`,
  cached 30 days), `carrierIds` = `MACHSHIP_CARRIER_IDS` (11 TNT), one item per carton size (cm, kg).
- Services kept: 540 TNT Road Express (`tnt_road`), 538 TNT Overnight Express (`tnt_overnight`) (config/freight.php).
- Price = `totalSellBeforeTax` (else sell − tax) × (1 + `MACHSHIP_MARKUP_PERCENT`); cost = `totalCostBeforeTax`;
  days = `despatchOptions[0].totalBusinessDays`.

## FedEx
- OAuth `POST /oauth/token` (client credentials, cached 50 min), then `POST /rate/v1/rates/quotes`: shipper = the
  from postcode in AU, recipient = postal code + country, `DROPOFF_AT_FEDEX_LOCATION`, `ACCOUNT` and `LIST` rates in AUD,
  one package per carton, customs value = the goods ex GST, duties paid by the recipient.
- Services kept: International Priority (`fedex_ip`), International Economy (`fedex_ie`). Price = the ACCOUNT
  `totalNetCharge` × (1 + `FEDEX_MARKUP_PERCENT`).
- Countries: every country in `App\Freight\Countries` (the theme keeps the same list, `CM.COUNTRIES`).

## Weights and cartons (`PackData`, `Consignment`)
- Promodata's packing details (unit or carton weight, carton quantity, carton size) where given.
- No weight from Promodata: the median unit weight of up to 60 products of the same Promodata type that have one
  ("similar"), else the per-type defaults; the consignment is then **estimated** and the quote says "Delivery is an
  estimate, subject to confirming the exact product mix and weights."

## Quotes
- The quick quote asks: Include delivery? (yes / no); In Australia (postcode) or Outside Australia (country + postal code).
- The cheapest service that arrives by the need-by date (else the fastest; no date: the cheapest) goes on the quote as
  one delivery line with its ETA. The suppliers' standard freight lines are not used (`quotes.freight_source`).
- Outside Australia: no GST (`quotes.gst_free_export`), and the quote and email say local duties and taxes are payable
  to the carrier before delivery.

## Ships from the supplier (Scott, 7 Nov 2026)
- Each supplier has a dispatch suburb and postcode (Admin › Suppliers). When every style in a quote or
  cart comes from one supplier, MachShip and FedEx price from that address; a mix of suppliers, or a
  supplier with no dispatch address, is priced from `FREIGHT_FROM_*` (Derrimut 3026).
- `st suppaddr` reads the suppliers' addresses from Promodata and shows what it holds; `st suppaddrgo`
  fills them in, **only where both suburb and postcode are empty in the SaaS**. Anything staff have
  entered or edited is never overwritten (source shown as "staff" or "Promodata").
- `st freight` now prints the carriers' own replies when a price fails.
