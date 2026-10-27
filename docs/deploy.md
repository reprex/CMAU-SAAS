# Deploying to saas.replicat.com.au

Set up 27 Oct 2026 for the dedicated cPanel account. Host facts (limits, backups, database) are in REPSTACK ADMIN "SaaS hosting account 2026-10-27.md".

| | |
| --- | --- |
| Address | https://saas.replicat.com.au (Cloudflare proxied, Full (strict)) |
| cPanel account | `saasreplicat584` on lx4.webhostingaustralia.com.au, port 2083 |
| App folder | `/home/saasreplicat584/saas-app` (document root is its `public/`) |
| cPanel Git clone | `/home/saasreplicat584/repositories/CMAU-SAAS`, branch **`deploy`** |
| PHP | `/usr/local/bin/ea-php84` (always this path, never plain `php`) |
| Database | `saasreplicat584_saas` (utf8mb4_unicode_ci), user `saasreplicat584_app` |
| Deploy log | `/home/saasreplicat584/deploy.log` |

## How a release works

1. The `release` branch is moved to the commit to ship (`git push origin <commit>:release`). A `release-*` tag also works.
2. GitHub Actions (`.github/workflows/release.yml`) runs the tests on PHP 8.4, installs production dependencies only, and commits the built app, including `vendor/`, to the **`deploy`** branch. The file `RELEASE` in that branch names the source commit.
3. In cPanel → **Git Version Control** → CMAU-SAAS → **Pull or Deploy**: press **Update from Remote**, then **Deploy HEAD Commit**.
4. `.cpanel.yml` runs `scripts/deploy-server.sh`, which:
   - checks PHP 8.4 and its extensions, and that `.env` exists;
   - puts the app in maintenance mode, copies the release into `saas-app` (never touching `.env` or `storage/`);
   - generates `APP_KEY` on the first deploy only;
   - runs the migrations, rebuilds the caches and brings the app back up.
   If a step fails the app stays in maintenance mode; the reason is in `deploy.log`.

The server only ever pulls from GitHub. No server password or key is stored in GitHub, and no shell is needed. (Jailed SSH is available; if used, log in with a key, not the password, and run `bash ~/repositories/CMAU-SAAS/scripts/deploy-server.sh` to deploy by hand.)

## First-time set-up (Scott, in cPanel and GitHub)

Steps 1–2 give the server read-only access to the private repository.

1. **Server key.** cPanel → Security → **SSH Access** → Manage SSH Keys → **Generate a New Key**:
   - Key name `github_deploy`, type RSA 4096 (or Ed25519 if offered). **Leave the passphrase empty** (cPanel Git can't use one).
   - Don't **Authorize** it: this key is for GitHub, not for logging in to the server.
   - **View/Download** the *public* key and copy it.
   - cPanel Git looks for `~/.ssh/id_rsa` (or `id_ed25519`) by default. If you named it `github_deploy`, also create `~/.ssh/config` in File Manager (show hidden files) with:
     ```
     Host github.com
       IdentityFile ~/.ssh/github_deploy
       IdentitiesOnly yes
     ```
2. **GitHub deploy key.** github.com/reprex/CMAU-SAAS → Settings → **Deploy keys** → Add deploy key. Title `saasreplicat584 cPanel`, paste the public key, **leave "Allow write access" off**.
3. **`.env`.** cPanel → File Manager → `/home/saasreplicat584/saas-app` → + File → `.env`, then Edit and paste the template below. Fill the blanks from your password manager. Leave `APP_KEY=` empty: the first deploy fills it.
4. **Clone.** cPanel → **Git Version Control** → Create:
   - Clone URL `git@github.com:reprex/CMAU-SAAS.git`;
   - Repository Path `repositories/CMAU-SAAS`;
   - Repository Name `CMAU-SAAS`.
   After it clones, open **Manage**, set **Checked-Out Branch** to `deploy`, Update.
   If the clone fails with a connection or "Permission denied (publickey)" error, check the key from steps 1–2; if it can't reach github.com on port 22 at all, ask Judd to allow outbound SSH to GitHub.
5. **Deploy.** Manage → **Pull or Deploy** → **Deploy HEAD Commit**. Check `deploy.log` ends with `=== done`, and that https://saas.replicat.com.au/up shows the green "Application up" page.
6. **Cron.** cPanel → **Cron Jobs** → Once Per Minute (`* * * * *`):
   `cd /home/saasreplicat584/saas-app && /usr/local/bin/ea-php84 artisan schedule:run >> /dev/null 2>&1`
7. **First admin.** Put a long random value in `SETUP_TOKEN` in `.env` and press **Deploy HEAD Commit** again (settings are cached, so every `.env` change needs a redeploy). Open https://saas.replicat.com.au/setup, enter the token with your name, email and password, then scan the two-factor QR code with your authenticator app. `/setup` closes itself once a user exists; clear `SETUP_TOKEN` and redeploy anyway.
8. **Check.** Sign in, open Quotes, Admin → Suppliers and Admin → Users. Then work through "After the first deploy" below.

## Production `.env` template

No secrets here: values in `<…>` come from the password manager and are typed only into the server's `.env`.

```
APP_NAME="CMAU SaaS"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://saas.replicat.com.au
APP_TIMEZONE=Australia/Melbourne
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_AU
APP_MAINTENANCE_DRIVER=file
BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=warning
LOG_DAILY_DAYS=30

DB_CONNECTION=mariadb
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=saasreplicat584_saas
DB_USERNAME=saasreplicat584_app
DB_PASSWORD=<database password>
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_DOMAIN=null
CACHE_STORE=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local
BROADCAST_CONNECTION=log

# Emails go to the log until sending is set up (see "Still open").
MAIL_MAILER=log
MAIL_FROM_ADDRESS="sales@crowdmerch.com.au"
MAIL_FROM_NAME="CrowdMerch"

PROMODATA_BASE_URL=https://api.promodata.com.au
PROMODATA_TOKEN=<new Promodata token>
PROMODATA_TIMEOUT=60
PROMODATA_PAGES_PER_STEP=5
CATALOGUE_ACTIVE_WINDOW_MONTHS=12

# CMAU_DEV only. Never set SHOPIFY_ALLOW_LIVE without Scott's go-ahead.
SHOPIFY_SHOP_DOMAIN=<cmau-dev store>.myshopify.com
SHOPIFY_DEV_STORE=<cmau-dev store>.myshopify.com
SHOPIFY_ALLOW_LIVE=false
SHOPIFY_ADMIN_TOKEN=<CMAU_DEV Admin API token>
SHOPIFY_API_VERSION=2026-07
SHOPIFY_WEBHOOK_SECRET=<CMAU_DEV app client secret>
SHOPIFY_PULL_DRAFTS=true

WEBSITE_QUOTE_API=true
WEBSITE_QUOTE_ORIGINS=https://<cmau-dev store>.myshopify.com
WEBSITE_QUOTES_PER_HOUR=10
WEBSITE_WISHLIST_PER_HOUR=60
WEBSITE_EMAIL_INSTANT_QUOTE=true

STOCK_RELAY_ORIGINS=https://crowdmerch.com.au,https://www.crowdmerch.com.au
QUOTE_NUMBER_START=11775

SETUP_TOKEN=
ADMIN_ALLOWED_IPS=202.169.99.148,2401:d002:3803:1a00::/56
```

`ADMIN_ALLOWED_IPS` limits the admin screens to the office: its static IPv4 address and its IPv6 range (the office reaches the site over IPv6, and the end of that address changes). Addresses and ranges (CIDR) are both accepted. Clear it if you need the admin screens from elsewhere.

## After the first deploy

- **Shopify (CMAU_DEV):** register webhooks `draft_orders/create` and `draft_orders/update` to `https://saas.replicat.com.au/webhooks/shopify/draft-orders`. The 5-minute pull covers any missed.
- **Promodata:** the first full sync starts at 01:30 and works through a few pages a minute. Watch Admin → Suppliers fill.
- **Branding:** upload `signature.png` to `saas-app/storage/app/branding/` (optional). A larger logo replaces `public/img/crowdmerch-logo.png` in Git.
- **Speed baseline (stack principle 1):** time the quote list, a quote, product search and a PDF against the same TOD tasks.
- **Disk:** the clone plus the app take roughly 250 MB of the account's 1 GB. Check cPanel → Disk Usage after the first sync.

## Behind Cloudflare

- The app trusts `X-Forwarded-For` only from Cloudflare's published ranges (`app/Http/CloudflareIps.php`), so the client IP used by the admin IP check, rate limits and audit log can't be forged by calling the server directly.
- Never add a Cloudflare rule that blocks `/.well-known/acme-challenge/` (AutoSSL renewals).
- Keep the cache-bypass rule: every page is per user.

## Still open

- **Email sending.** Instant quotes and quote emails need an SMTP account. Sending as `@crowdmerch.com.au` from this server needs crowdmerch.com.au's SPF/DKIM (Vodien DNS) to allow it; Microsoft 365 SMTP is the other option. Until then emails are written to the log.
- **Off-site database dump** in addition to the host's backups (REPSTACK ADMIN backup plan).
- **Account limits:** Judd to confirm the I/O limit (2 MB/s vs 256 MB/s).
