# External ranges: products outside Promodata (Scott, 5 Nov 2026)

Must be on CMAU before the site goes live. All five are important.

| Range | Supplier | Notes |
| --- | --- | --- |
| Card printing (conference cards to go with lanyards, printed cards generally) | Creative Cards | Lanyards stay with the Promodata suppliers. Scott can get Creative Cards' templates. |
| Pins, coins, medals, custom key rings | SP Pins and Coins (China) | Scott has our previous data and images. |
| USB flash drives (printing and data load) | Begif (China), 10–15 years | Also used every week for Replicat USB albums. |
| Power banks | Begif | A range Begif updates often: their data and images in a standard form. |
| Other electronics (branded cables, small gadgets, luggage and pet trackers) | Begif | As power banks. |

## Approach
- **One spreadsheet per supplier**, kept up to date (by us, or from the supplier's own file in a standard
  form), imported into the SaaS as a **spreadsheet source** beside Promodata (the source layer,
  `App\Sources\SupplierSource`, was built swappable for this). From there the products go through the same
  pricing, positions, mockups, lead times, Vet screen and Shopify publishing as the Promodata range.
- Sheets: products (code, name, description, category, dimensions, materials); colours and their images;
  prices (quantity breaks, setup) for blank and each branding option; options (capacity, plating, sizes…);
  branding positions and sizes; lead time (production days, ships from, transit); template file names.
- **Images:** public URLs only. Images supplied as files are uploaded to our public image store first.
- **Suppliers** added in the SaaS with their codes; the supplier's name is never shown to clients.
- **Made-to-order ranges** (pins, coins, medals, USB, cards) are priced by a grid (size, plating or capacity,
  quantity) rather than a fixed product list: each becomes a small set of products with options.
- **Lead times:** made in China: production + air freight + Chinese New Year shutdown (docs/lead-times.md).

## Needed from Scott
- Creative Cards: price list and templates.
- SP Pins and Coins: our previous data and images.
- Begif: USB pricing, and their power bank and electronics data and images in whatever form they send.
