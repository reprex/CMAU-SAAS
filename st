#!/bin/sh
# Phone shortcuts (Scott, 5 Nov 2026), from cPanel's Terminal. All read-only except "retry".
#   st          status: templates, photo checks, last error
#   st fails    the failed templates and why
#   st lead     the Promodata lead-time report
#   st ver      which release is deployed
#   st retry    queue the failed templates to be read again
#   st help     this list
# Go-live (docs/publisher.md "Switching to the live store"):
#   st vm       variant-match report on storage/app/private/live-export.csv (read-only)
#   st cp       products the SaaS sends as "Custom Print" where live has real colours (read-only)
#   st apoff / st apon   automatic publishing off / on
#   st sw       switch-store report (read-only);  st swgo  switch-store --apply
#   st mfd      metafield definitions;  st cols  collections --sync;  st pub50  publish 50 and stop
#   st menus    store menus (report);  st menusgo  create or replace the menus
#   st suppcont supplier orders contacts from Promodata (report);  st suppcontgo  fill the empty ones
#   st suppaddr supplier dispatch addresses from Promodata (report);  st suppaddrgo  fill the empty ones
#   st health   publishing status
cd "$HOME/saas-app" || exit 1
PHP=/usr/local/bin/ea-php84
case "$1" in
  ""|status) $PHP artisan saas:status ;;
  fails) $PHP artisan tinker --execute="App\Models\BrandingTemplate::query()->where('status','failed')->latest('updated_at')->limit(20)->get(['product_id','message'])->each(fn(\$t)=>print(\$t->product_id.': '.\$t->message.PHP_EOL));" ;;
  lead) $PHP artisan catalogue:lead-time-report ;;
  ver) echo "Deployed: $(cut -c1-7 .deployed-commit 2>/dev/null)"; git --git-dir="$HOME/repositories/CMAU-SAAS/.git" log -1 --format='%s (%cr)' 2>/dev/null ;;
  retry) $PHP artisan catalogue:read-templates --retry-failed ;;
  haiku50) $PHP artisan catalogue:read-templates --compare=claude-haiku-5-5 --limit=50 --parallel=5 ;;
  vm) $PHP artisan shopify:variant-match storage/app/private/live-export.csv ;;
  cp) f=$(ls -t storage/app/private/variant-match/*.csv 2>/dev/null | head -1); [ -z "$f" ] && { echo "Run st vm first."; exit 1; }
      echo "Products going to Custom Print where live has colours: $(grep -E ',new,,,"?Custom Print"?,' "$f" | cut -d, -f1 | sort -u | wc -l)"
      grep -E ',new,,,"?Custom Print"?,' "$f" | cut -d, -f1,2 | sort -u | head -40 ;;
  cpwhy) $PHP artisan catalogue:colour-check ;;
  mail) [ -z "$2" ] && { echo "Use: st mail you@example.com"; exit 1; }; $PHP artisan config:cache >/dev/null && $PHP artisan mail:test "$2" ;;
  freight) $PHP artisan config:cache >/dev/null && $PHP artisan freight:test AU "${2:-3066}"; $PHP artisan freight:test NZ "${3:-1010}" ;;
  po) $PHP artisan suppliers:po-check ;;
  apoff) $PHP artisan shopify:auto-publish off ;;
  apon) $PHP artisan shopify:auto-publish on ;;
  sw) $PHP artisan shopify:switch-store ;;
  swgo) $PHP artisan shopify:switch-store --apply ;;
  mfd) $PHP artisan shopify:metafield-definitions ;;
  cols) $PHP artisan shopify:collections --sync ;;
  suppcont) $PHP artisan promodata:supplier-contacts ;;
  suppcontgo) $PHP artisan promodata:supplier-contacts --apply ;;
  suppaddr) $PHP artisan promodata:supplier-addresses ;;
  suppaddrgo) $PHP artisan promodata:supplier-addresses --apply ;;
  menus) $PHP artisan shopify:menus ;;
  menusgo) $PHP artisan shopify:menus --apply ;;
  pub50) $PHP artisan shopify:publish --limit=50 ;;
  health) $PHP artisan saas:status; $PHP artisan shopify:range-report 2>/dev/null | head -30 ;;
  *) printf '%s\n' "st          status: templates, photo checks, last error" "st fails    the failed templates and why" \
       "st lead     the Promodata lead-time report" "st ver      which release is deployed" \
       "st retry    queue the failed templates to be read again" \
       "st haiku50  read 50 templates again with Haiku, to compare with Sonnet" \
       "st vm       variant-match report (live-export.csv)" "st cp       Custom Print where live has colours" "st cpwhy    why they have no colours" \
       "st apoff    automatic publishing off" "st apon     automatic publishing on" \
       "st sw       switch-store report" "st swgo     switch-store --apply" \
       "st mfd      metafield definitions" "st cols     collections --sync" "st menus    store menus (report)" "st menusgo  write the store menus" "st pub50    publish 50 products" \
       "st mail ADDRESS  send a test email (after .env changes)" "st freight  price a test delivery (MachShip VIC, FedEx NZ)" "st po       suppliers missing purchase order details" "st health   status and range report" "st help     this list" ;;
esac
