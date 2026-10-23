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

**Stage 1 (in progress): supplier catalogue.** PHP 8.4 + Laravel 13 + MariaDB on the existing cPanel host, deployed from GitHub without shell access (see [docs/stack-and-security.md](docs/stack-and-security.md)).

Built so far:
- A swappable supplier-source layer (`app/Sources`), with Promodata as the first source. It uses the same endpoints TOD did: list pages, changed-since pages and single products.
- Tiered catalogue storage (`app/Catalogue`):
  - Every product keeps a compact summary row.
  - Live and active products also keep their colours, image URLs, price lines and details.
  - Unused active products drop back to the summary tier after 12 months, but their staff overrides are kept.
- Paged syncs that run a few pages per minute from the scheduler, so each cron run stays short.

Next: the pricing engine (to be checked against TOD's prices for the live range), then tagging, the Shopify publisher and quotes.

## Commands

| Command | What it does |
| --- | --- |
| `php artisan promodata:sync` | Start a "changed since last run" sync. With no previous run, it starts a full sync |
| `php artisan promodata:sync --full --now` | Run a full sync immediately (first load) |
| `php artisan promodata:sync --supplier=3` | Sync one supplier |
| `php artisan promodata:sync-step` | Process the next few pages of the open run (the scheduler does this every minute) |
| `php artisan catalogue:hydrate 2568` | Fetch one product in full and keep it in the active tier |
| `php artisan catalogue:demote` | Drop unused active products to the summary tier |
| `php artisan schedule:list` | Show what the one-minute cron runs |

## Development

```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate
php artisan test
```

CI (GitHub Actions) runs the tests and `composer audit` on PHP 8.4 for every push.

## Docs

- [Requirements summary](docs/requirements.md)
- [Shopify product contract](docs/contracts/shopify-product.md)
- [DecorationJSON contract](docs/contracts/decorationjson.md) and its [JSON Schema](docs/contracts/decorationjson.schema.json)
- Full requirements document (with diagrams): https://claude.ai/artifact/VhmB9Q9PXU2CtbBBGodLq9
