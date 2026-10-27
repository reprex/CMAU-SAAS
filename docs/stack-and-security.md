# Stack, hosting and security baseline

Decided 24 Oct 2026. Hosting moved to its own cPanel account on 27 Oct 2026.

## Stack

| Layer | Choice | Why |
| --- | --- | --- |
| Language | **PHP 8.4**, the newest version the host offers. The host (Melbourne Online) confirmed on 24 Oct 2026 that its 8.2, 8.3 and 8.4 builds are patched for the 21 Oct 2026 critical RCE release; 8.5 is not offered | Gets security fixes the longest of the versions available; Laravel 13 needs 8.3+. Move to 8.5 when the host offers it |
| Framework | **Laravel 13** (released 17 Mar 2026, needs PHP 8.3+, security fixes until 17 Mar 2028) | Current major version; runs well on cPanel |
| Database | **MariaDB 10.11.19** (MySQL-compatible; long-term-support release), database `saasreplicat584_saas` (utf8mb4_unicode_ci), user `saasreplicat584_app` | Familiar through phpMyAdmin, included in the host's backups |
| Background work | One cPanel cron job every minute running `php artisan schedule:run`; database-backed queue processed in short batches | No long-running processes for the host to kill |
| Hosting | Dedicated cPanel account **`saasreplicat584`** (Melbourne Online, server lx4.webhostingaustralia.com.au, 54.79.92.35), set up 27 Oct 2026. The app is at **saas.replicat.com.au**, proxied by Cloudflare (Full (strict), cache bypass) with AutoSSL. Document root `/home/saasreplicat584/saas-app/public`; PHP 8.4 (ea-php84) with PHP-FPM. It replaces the earlier saas.app.crowdmerch.com.au plan on appcrowdmerch746, which has been removed | Its own account: eDreamz and the end-of-life sites have no access. It will also host the Replicat and CrowdLink modules (single SaaS) |

## Deployment

Full steps: `docs/deploy.md`.

1. A `release-*` tag (or a manual run) starts GitHub Actions. It runs the tests on PHP 8.4, installs production dependencies and commits the built app, with `vendor/`, to the `deploy` branch.
2. cPanel **Git Version Control** clones that branch with a read-only GitHub deploy key. "Deploy HEAD Commit" runs `.cpanel.yml`, which copies the release into `saas-app`, then migrates and rebuilds the caches.
3. The server only pulls. No server credentials are stored in GitHub, and no shell is needed. The host has since enabled a jailed SSH shell; if it's used, log in with a key, not the password.
4. Settings and secrets live in `saas-app/.env`, created once in File Manager and never in Git. The document root is `saas-app/public`, so the rest of the app is outside the web root.

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

## Behind Cloudflare

- Only Cloudflare's published ranges are trusted for `X-Forwarded-For` (`app/Http/CloudflareIps.php`). Trusting every proxy would let someone calling the server directly forge their IP past the admin IP check and the rate limits.
- Never block `/.well-known/acme-challenge/` in Cloudflare (AutoSSL renewals).

## Still to confirm

- Account limits: the cPanel screen and the host's reply differ, especially disk I/O (256 MB/s vs 2 MB/s). Heavy jobs run in short batches outside staff hours either way.

## Server facts (checked in phpMyAdmin, 24 Oct 2026)

- The database server is MariaDB 10.11.19 (`10.11.19-MariaDB-cll-lve`).
- The server's default charset is **latin1**. The `saasreplicat584_saas` database and every table must be created as `utf8mb4` / `utf8mb4_unicode_ci`: Laravel's connection config sets this, and the first migration checks it.
- phpMyAdmin itself runs on PHP 8.4.27, the upstream release that fixes CVE-2026-109618 and CVE-2026-109619.
