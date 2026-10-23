# CMAU-SAAS

Browser-based replacement for TOD (the CrowdMerch Operations Database, an Access .mdb). It covers two jobs:

1. **Product curation:** pull supplier product data (Promodata first, with direct supplier APIs to follow), then price, categorise, tag and publish products to the CrowdMerch Shopify store (crowdmerch.com.au, `merchrep.myshopify.com`).
2. **Quotes and orders:** turn Shopify draft orders into quotes, manage them through to order, and hand confirmed orders to ROD.

## Ground rules

- **The CMAU website is frozen.** The custom Liquid on the PLP, PDP and wish list page does not change. This system only changes how data gets to and from Shopify, and must publish exactly the shape the theme already reads (see `docs/contracts/`).
- **Shopify is updated through the Admin API**, not Matrixify.
- **Images are stored as public URLs only**, never as files.
- **Hosted data stays compact.** Live and recently used products keep full detail; everything else is a summary row that is fetched from the supplier source on demand.
- **No secrets in this repository.** API tokens and passwords live in the host's secret store.
- **Test against CMAUDEV**, the development store, before anything touches the live store.

## Status

Planning. Stack decided: PHP 8.5 + Laravel 13 + MySQL on the existing cPanel host, deployed from GitHub without shell access (see [docs/stack-and-security.md](docs/stack-and-security.md)).

## Docs

- [Requirements summary](docs/requirements.md)
- [Shopify product contract](docs/contracts/shopify-product.md)
- [DecorationJSON contract](docs/contracts/decorationjson.md) and its [JSON Schema](docs/contracts/decorationjson.schema.json)
- Full requirements document (with diagrams): https://claude.ai/artifact/VhmB9Q9PXU2CtbBBGodLq9
