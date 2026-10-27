#!/bin/bash
# Runs on the server from .cpanel.yml when cPanel → Git Version Control →
# "Deploy HEAD Commit" is pressed (or by hand over SSH). It copies the built
# release from the cPanel clone of the `deploy` branch into the live folder,
# then migrates and rebuilds Laravel's caches. Safe to run again.
#
# Never touches: .env, storage/ (logs, sessions, signature, PDF image cache).
set -euo pipefail

ACCOUNT_HOME="/home/saasreplicat584"
REPO="${REPO:-$ACCOUNT_HOME/repositories/CMAU-SAAS}"
APP="${APP:-$ACCOUNT_HOME/saas-app}"
PHP="${PHP:-/usr/local/bin/ea-php84}"
LOG="$ACCOUNT_HOME/deploy.log"

exec >>"$LOG" 2>&1
echo "=== $(date '+%Y-%m-%d %H:%M:%S') deploy $(git -C "$REPO" rev-parse --short HEAD 2>/dev/null || echo '?')"

if [ ! -f "$REPO/artisan" ] || [ ! -d "$REPO/vendor" ]; then
  echo "Not a built release (no artisan or vendor/). Check the branch is 'deploy'."; exit 1
fi
if [ ! -f "$APP/.env" ]; then
  echo "Missing $APP/.env. Create it first (docs/deploy.md, step 3)."; exit 1
fi

missing=$("$PHP" -r 'foreach (["ctype","dom","fileinfo","filter","hash","iconv","json","libxml","mbstring","openssl","pcre","session","tokenizer","pdo_mysql","curl","gd"] as $e) if (!extension_loaded($e)) echo "$e ";')
if [ -n "$missing" ]; then
  echo "PHP 8.4 is missing extensions: $missing(cPanel → Select PHP Version, or ask the host)."; exit 1
fi
"$PHP" -r 'exit(version_compare(PHP_VERSION, "8.4.0", ">=") ? 0 : 1);' || { echo "Needs PHP 8.4+"; exit 1; }

mkdir -p "$APP"/storage/app/private "$APP"/storage/app/public "$APP"/storage/app/branding \
         "$APP"/storage/framework/cache/data "$APP"/storage/framework/sessions \
         "$APP"/storage/framework/views "$APP"/storage/logs "$APP"/storage/fonts \
         "$APP"/bootstrap/cache

# Maintenance mode only once the app has been installed before.
if [ -f "$APP/artisan" ] && [ -d "$APP/vendor" ]; then
  (cd "$APP" && "$PHP" artisan down --retry=15) || true
fi

rsync -a --delete \
  --exclude '/.git/' --exclude '/.env' --exclude '/storage/' --exclude '/bootstrap/cache/' \
  --exclude '/.cpanel.yml' --exclude '/public/.well-known/' \
  "$REPO"/ "$APP"/

# Cached manifests may name old packages: let artisan rebuild them.
rm -f "$APP"/bootstrap/cache/*.php
chmod -R u+rwX,go-w "$APP"/storage "$APP"/bootstrap/cache
chmod 600 "$APP/.env"

cd "$APP"
if ! grep -q '^APP_KEY=base64:' .env; then
  "$PHP" artisan key:generate --force
fi
"$PHP" artisan migrate --force
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan view:cache
"$PHP" artisan up
echo "=== done"
