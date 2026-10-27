# CMAU-SAAS: notes for Claude

- Requirements and contracts live in `docs/`. The Shopify product shape and DecorationJSON in `docs/contracts/` are fixed: the CMAU theme is frozen, so never change what it reads.
- Production runs on PHP 8.4 (ea-php84) and MariaDB 10.11 on cPanel. There is no SSH, and all background work runs from a one-minute cron (`routes/console.php`), so keep jobs short and batched.
- Images are stored as public URLs only. Never commit secrets, `.env`, customer data or TOD exports.
- Sandbox PHP is 8.3 and the production lock needs 8.4. For local work, use `COMPOSER=composer-local.json composer install` (that file is gitignored; it pins `config.platform.php` to 8.3.6 and `require.php` to `^8.3`). `composer.json` and `composer.lock` stay on 8.4, and CI tests on 8.4.
- Run `./vendor/bin/pint` and `php artisan test` before committing.
