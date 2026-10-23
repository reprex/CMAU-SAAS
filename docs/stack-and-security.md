# Stack, hosting and security baseline

Decided 24 Oct 2026.

## Stack

| Layer | Choice | Why |
| --- | --- | --- |
| Language | **PHP 8.5** (at least 8.5.12); fall back to PHP 8.4 (at least 8.4.27) if 8.5 is not offered | Latest supported branch, patched for the 21 Oct 2026 critical RCE release |
| Framework | **Laravel 13** (released 17 Mar 2026, needs PHP 8.3+, security fixes until 17 Mar 2028) | Current major version; runs well on cPanel |
| Database | **MySQL** on the existing cPanel account, in its own database `cmausaas` (kept separate from CLAU) | Already paid for, familiar through phpMyAdmin, included in cPanel backups |
| Background work | One cPanel cron job every minute running `php artisan schedule:run`; database-backed queue processed in short batches | No long-running processes for the host to kill |
| Hosting | cPanel account `appcrowdmerch746` at Web Hosting Australia (server lx4); app on app.crowdmerch.com.au or a subdomain (to confirm) | Existing account with 4 GB RAM, 1,500 processes, unlimited disk |

## Deployment without shell access

The host prefers that we don't use SSH shell access, so nothing depends on running commands on the server by hand.

1. GitHub Actions builds each release: `composer install --no-dev --optimize-autoloader`, front-end assets, `composer audit`, and the tests.
2. The built release is pushed to a `deploy` branch.
3. On the server, the release arrives one of two ways:
   - **Preferred:** cPanel **Git Version Control** pulls the `deploy` branch. Its `.cpanel.yml` copies the release into place and runs `php artisan migrate --force` and the cache commands. Note: cPanel needs a read-only deploy key to clone a private GitHub repository. This is an outbound connection to GitHub, not shell access to our server; confirm with the host that it's acceptable.
   - **Fallback:** GitHub Actions uploads the release over FTPS (cPanel FTP account, limited to the app folder). The release then finishes itself on the next cron run.
4. Settings and secrets go in a `.env` file outside the web root. You create it once through cPanel File Manager; it is never in Git.

## Security baseline

**PHP and framework**
- Before go-live, confirm with the host that the PHP build serving the app is 8.5.12+ or 8.4.27+. This fixes CVE-2026-109618 and CVE-2026-109619, both CVSS 9.2 and under active exploitation since 21 Oct 2026.
- The app refuses to start on an older PHP version: a version check runs at boot and in CI.
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
- Which PHP versions MultiPHP Manager offers, and that the host's builds include the 21 Oct 2026 fixes.
- Whether the app goes on app.crowdmerch.com.au or a subdomain.
- Whether the host is OK with a cPanel Git deploy key (if not, use the FTPS fallback).
