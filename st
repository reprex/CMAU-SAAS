#!/bin/sh
# Phone shortcuts (Scott, 5 Nov 2026), from cPanel's Terminal. All read-only except "retry".
#   st          status: templates, photo checks, last error
#   st fails    the failed templates and why
#   st lead     the Promodata lead-time report
#   st ver      which release is deployed
#   st retry    queue the failed templates to be read again
#   st help     this list
cd "$HOME/saas-app" || exit 1
PHP=/usr/local/bin/ea-php84
case "$1" in
  ""|status) $PHP artisan saas:status ;;
  fails) $PHP artisan tinker --execute="App\Models\BrandingTemplate::query()->where('status','failed')->latest('updated_at')->limit(20)->get(['product_id','message'])->each(fn(\$t)=>print(\$t->product_id.': '.\$t->message.PHP_EOL));" ;;
  lead) $PHP artisan catalogue:lead-time-report ;;
  ver) echo "Deployed: $(cut -c1-7 .deployed-commit 2>/dev/null)"; git --git-dir="$HOME/repositories/CMAU-SAAS/.git" log -1 --format='%s (%cr)' 2>/dev/null ;;
  retry) $PHP artisan catalogue:read-templates --retry-failed ;;
  *) printf '%s\n' "st          status: templates, photo checks, last error" "st fails    the failed templates and why" \
       "st lead     the Promodata lead-time report" "st ver      which release is deployed" \
       "st retry    queue the failed templates to be read again" "st help     this list" ;;
esac
