# CMAU-SAAS

Browser-based quote and order SaaS for CrowdMerch (CMAU), replacing the TOD Access database. PHP 8.2+ and MySQL, hosted on cPanel at saas.app.crowdmerch.com.au.

## Modules

### Competitor price comparison (admin only)

Compares CMAU's quantity-break prices, read from the same DecorationJSON the website uses, with competitor websites. Optionally compares both with supplier distributor cost and suggested markup, such as the Trends portal.

- **Who can see it:** admin login only. Staff accounts and logged-out visitors get a 404 at `/competitor-prices.php`, so the page isn't advertised.
- **Compliance built in.** A competitor is collected automatically only if:
  - its robots.txt has been checked and allows product pages, and
  - its terms have been checked and don't prohibit scraping (`competitors` table).

  Every request also obeys robots.txt and waits at least the site's crawl delay (minimum 10 seconds per site). Requests identify themselves honestly (`Agent/CMAU-PriceCheck (+mailto:…)`), and every fetch is logged in `competitor_fetch_log`. There are no logins, no CAPTCHA bypassing and no browser impersonation.
- **Extraction:**
  - Shopify stores: `/products/{handle}.json`, including Promo Punks-style `bulk_qty:%` discount tags.
  - Other stores: schema.org JSON-LD, or an optional per-site regex (`competitors.price_regex`, with named groups `qty` and `price`).
  - Anything else: entered manually on the admin page.
- **Supplier costs** (`supplier_cost_snapshots`) are entered by hand from distributor portals. They are never scraped, never published, and never committed to this repo.
- **Comparison output:** diff $ and % on project total (positive = CMAU dearer), implied markup over supplier cost, competitor price vs supplier suggested sell, and flags for "we are dearer" (above 10%) and "much cheaper" (below -15%).
- Each competitor price row also stores the project total, so a markup-on-total-project-cost model (as Brandconnect uses) can be tested later. Pricing stays quantity-break based for now.

### Files

| Path | Purpose |
|---|---|
| `sql/001_competitor_prices.sql` | Tables: competitors, matches, price snapshots, CMAU price snapshots, supplier costs, fetch log, product pricing, users |
| `sql/002_seed_competitors.sql` | 9 competitors with the robots.txt and terms checks from 24 Oct 2026 |
| `sql/003_seed_first_check.sql` | First manual price check (11 products, 73 comparisons) |
| `src/Competitor/*` | DecorationJSON pricing, robots.txt parser, polite fetcher, extractors, runner, repository |
| `src/Auth/Auth.php` | Session login with roles (admin / staff) |
| `public/login.php`, `public/competitor-prices.php` | Login and the admin-only comparison page |
| `bin/competitor-price-check.php` | `run` collects prices; `export` prints CSV. Can run from cPanel cron. |
| `bin/create-user.php` | Create or reset a user: `php bin/create-user.php scott@replicat.com.au admin` |
| `shopify/robots.txt.liquid`, `shopify/ROBOTS-INSTALL.md` | robots.txt for the CMAU store and install steps |
| `tests/run.php` | `php tests/run.php` runs 35 checks against an in-memory SQLite database, with no network |

### Setup (cPanel, no SSH needed)

1. Create a MySQL database and user in cPanel. Import `sql/001…`, `002…` and `003…` in phpMyAdmin.
2. Upload the repo. Point the subdomain's document root at `public/`.
3. Copy `config/config.example.php` to `config/config.php` and fill in the database details.
4. Create the admin user with `bin/create-user.php` (cPanel Terminal), or by inserting a `password_hash()` value into the `users` table.
5. Optional: add a monthly cron job running `php bin/competitor-price-check.php run`.

## Pricing notes

The CMAU unit price is the undecorated price at the quantity break plus the decoration price at the quantity break. The project total is quantity × unit + CombinedSetup. This matches the live PDP's "Cost per unit including branding" (verified on product 2029).
