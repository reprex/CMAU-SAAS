# Stack, hosting and security baseline

Decided 24 Oct 2026.

## Stack

| Layer | Choice | Why |
| --- | --- | --- |
| Language | **PHP 8.4**, the newest version the host offers. The host (Melbourne Online) confirmed on 24 Oct 2026 that its 8.2, 8.3 and 8.4 builds are patched for the 21 Oct 2026 critical RCE release; 8.5 is not offered | Gets security fixes the longest of the versions available; Laravel 13 needs 8.3+. Move to 8.5 when the host offers it |
| Framework | **Laravel 13** (released 17 Mar 2026, needs PHP 8.3+, security fixes until 17 Mar 2028) | Current major version; runs well on cPanel |
| Database | **MySQL** on the existing cPanel account, in its own database `cmausaas` (kept separate from CLAU) | Already paid for, familiar through phpMyAdmin, included in cPanel backups |
| Background work | One cPanel cron job every minute running `php artisan schedule:run`; database-backed queue processed in short batches | No long-running processes for the host to kill |
| Hosting | cPanel account `appcrowdmerch746` (Melbourne Online, server lx4.webhostingaustralia.com.au). The app lives at **saas.app.crowdmerch.com.au**, created 24 Oct 2026. Its document root is `/home/appcrowdmerch746/public_html/saas.app.crowdmerch.com.au`, it runs PHP 8.4 (ea-php84) with PHP-FPM, and its DNS is a Cloudflare A record pointing to 54.79.92.35. cPanel would not add saas.crowdmerch.com.au because crowdmerch.com.au uses Cloudflare nameservers. app.crowdmerch.com.au already hosts an existing app on PHP 8.1 | Isolated from the existing app; 4 GB RAM, 1,500 processes, unlimited disk |

## Deployment without shell access

The host prefers that we don't use SSH shell access, so nothing depends on running commands on the server by hand.

1. GitHub Actions builds each release: `composer install --no-dev --optimize-autoloader`, front-end assets, `composer audit`, and the tests.
2. The built release is pushed to a `deploy` branch.
3. On the server, the release arrives one of two ways:
   - **Preferred:** cPanel **Git Version Control** pulls the `deploy` branch. Its `.cpanel.yml` copies the release into place and runs `php artisan migrate --force` and the cache commands. Note: cPanel needs a read-only deploy key to clone a private GitHub repository. This is an outbound connection to GitHub, not shell access to our server; confirm with the host that it's acceptable.
   - **Fallback:** GitHub Actions uploads the release over FTPS (cPanel FTP account, limited to the app folder). The release then finishes itself on the next cron run.
4. Laravel's code sits outside the web root, in `/home/appcrowdmerch746/cmau-saas`. Only the `public/` files go into the document root, with `index.php` pointed back at the app. Settings and secrets go in a `.env` file outside the web root. You create it once through cPanel File Manager; it is never in Git.

## Security baseline

**PHP and framework**
- The PHP builds must include the fixes for CVE-2026-109618 and CVE-2026-109619 (both CVSS 9.2, actively exploited since 21 Oct 2026). Upstream that means 8.4.27+; the host has applied its own patches, so record the exact build string `php -v` reports in the app's health page.
- The app refuses to start below PHP 8.4: a version check runs at boot and in CI (CI tests on 8.4).
- Use only the PHP extensions Laravel needs. Turn on `expose_php=Off` and `display_errors=Off` in production.
- Dependabot and `composer audit` run on every push. A build with a known-vulnerable dependency fails.

**Access**
- Staff log in from anywhere (that is the reason for moving off Access), with two-factor authentication required for every account.
- Admin-only screens (markups, rules, users, publishing to the live store) can additionally be limited to the office static IP.
- Remote MySQL access (cPanel **Remote Database Access**) is open only to the office static IP, and only if TOD needs to read the new database during the changeover.
- Roles: admin, sales and read-only. Every change is written to an audit log.

**Integrations**
- Shopify webhooks are rejected unless their HMAC signature verifies.
- Shopify and Promodata tokens live only in `.env`, with the narrowest Shopify scopes needed.
- Test against the CMAUDEV development store first. The live store token is added only at go-live.

**Web**
- HTTPS only (AutoSSL), with HSTS, secure and HttpOnly cookies, CSRF protection, rate-limited login, and security headers (CSP, X-Frame-Options, Referrer-Policy).
- Public image files are served from a separate public folder; uploaded files are never executed.

**Data**
- Nightly cPanel backups of the database, plus an app-level export of quotes and orders before each migration.
- No customer data or connection strings in Git (`.gitignore` blocks `.env`, exports, `.mdb` and `.xlsx`).

## Still to confirm

- The phpMyAdmin "Database server" version (MySQL 8.x or MariaDB 10.6+ needed).
- The AutoSSL certificate for saas.app.crowdmerch.com.au. It was self-signed when the subdomain was created, and AutoSSL replaces it on its next run.
- Whether the host is OK with a cPanel Git deploy key (if not, use the FTPS fallback).

## Other sites on this account (flagged by the host, 24 Oct 2026)

- **app.crowdmerch.com.au** runs on PHP 8.1, which no longer gets PHP security fixes. The host recommends moving it to 8.3.
- **dotcode.me** runs Drupal 7 on PHP 7.2. Both are past end of life; moving to PHP 8.2+ needs a Drupal core update and testing.

These are separate from this project, but they share the same hosting account.
