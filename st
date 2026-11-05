#!/bin/sh
# Phone shortcut (Scott, 5 Nov 2026): in cPanel Terminal type  sh saas-app/st
cd "$HOME/saas-app" && /usr/local/bin/ea-php84 artisan saas:status
