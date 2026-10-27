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

# Copy with git itself (the jailed shell has no rsync). Only files tracked in the
# release are written, so .env, storage contents and public/.well-known are never
# touched. A separate index keeps this apart from the cPanel clone's own checkout.
GIT_BIN="$(command -v git || echo /usr/local/cpanel/3rdparty/bin/git)"
NEW="$("$GIT_BIN" -C "$REPO" rev-parse HEAD)"
OLD="$(cat "$APP/.deployed-commit" 2>/dev/null || true)"
GIT_INDEX_FILE="$ACCOUNT_HOME/.deploy-index" \
  "$GIT_BIN" --git-dir="$REPO/.git" --work-tree="$APP" checkout -f "$NEW" -- .
# Remove files that the previous release had and this one doesn't.
if [ -n "$OLD" ] && "$GIT_BIN" -C "$REPO" cat-file -e "$OLD^{commit}" 2>/dev/null; then
  "$GIT_BIN" -C "$REPO" diff --name-only --diff-filter=D "$OLD" "$NEW" | while IFS= read -r f; do
    case "$f" in .env|storage/*) ;; *) rm -f "$APP/$f" ;; esac
  done
fi
echo "$NEW" > "$APP/.deployed-commit"

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
