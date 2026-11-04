# Handover: start here in a new session

For Claude, at the start of any new session in the **SaaS Development** project (Scott, 5 Nov 2026).
A new session does not see earlier conversations. What carries over is this repo, the project
instructions and memory, and REPSTACK in OneDrive. Read this file, then `CLAUDE.md`, then the doc for
the area you are working on.

## What this is
One SaaS for Replicat, CrowdMerch and CrowdLink at **saas.replicat.com.au** (Laravel 13, PHP 8.4,
MariaDB 10.11, cPanel account saasreplicat584, no SSH). This project builds every module. CrowdMerch is
built and running; Replicat and CrowdLink are built here from the requirements other projects write in
REPSTACK (ROD To SaaS for Replicat, CrowdLink for CrowdLink). Divisions, access and shared modules:
`docs/divisions.md`.

| Repo | Local folder | Branch | Release |
| --- | --- | --- | --- |
| `reprex/CMAU-SAAS` (this) | `/home/claude/cmau-saas` | `feat/quotes` | `git push origin HEAD:feat/quotes HEAD:release` |
| `reprex/CMAU-THEME` (CMAU website theme) | `/home/claude/cmau-theme` | `main` | `git push origin main` (syncs to CMAU_DEV) |

If a repo isn't in the session, attach it (add_repo, push access) and clone it to the folder above.

## Set up a fresh workspace (SaaS)
1. `composer-local.json` is gitignored. Recreate it: a copy of `composer.json` with
   `config.platform.php` = `8.3.6` and `require.php` = `^8.3` (the sandbox runs PHP 8.3; production and
   CI run 8.4). Then `COMPOSER=composer-local.json composer install`.
2. `cp .env.example .env && php artisan key:generate`. Tests use in-memory SQLite (`phpunit.xml`).
   Never run destructive commands against a local `database/*.sqlite`.
3. Before every commit: `./vendor/bin/pint` and `php artisan test` (all must pass).
4. `composer.json` / `composer.lock` stay on PHP 8.4. To update a package: `composer update <pkg>
   --with-dependencies --no-install --no-scripts` (resolves for 8.4), then the same with
   `COMPOSER=composer-local.json` to install locally. CI's `composer audit` blocks a release with a known
   vulnerability: when release emails say "Run failed", read the log with
   `gh run view <id> -R reprex/CMAU-SAAS --log-failed`.

Theme: `node docs/validate-theme.js` and the smoke tests (`docs/tests/smoke.js`, needs `jsdom`:
`npm install jsdom` in a scratch folder and run with `NODE_PATH=<that folder>/node_modules`).

## Release and deploy
- **More than one session may be building at once** (e.g. CrowdMerch and Replicat). Work on your own
  branch (`feat/quotes` for CrowdMerch, `feat/replicat` for Replicat, …). Before releasing:
  `git fetch origin && git rebase origin/release`, run the tests, then `git push origin HEAD:<branch>
  HEAD:release`. Never force-push `release`: a rejected push means the other session released first, so
  fetch, rebase and test again.
- Commit, push to `release`; GitHub Actions tests on PHP 8.4 and builds the `deploy` branch. Check it went
  green (`gh run list -R reprex/CMAU-SAAS -L 3`) before telling Scott to deploy.
- **Scott deploys** in cPanel › Git Version Control: "Update from Remote", then "Deploy HEAD Commit"
  (runs `scripts/deploy-server.sh`: migrations, caches). Details: `docs/deploy.md`.
- **Scott runs every server command himself.** Always give the full line, e.g.
  `cd ~/saas-app && /usr/local/bin/ea-php84 artisan catalogue:classify --apply`. Don't drive cPanel.
- Background work runs from the one-minute cron (`routes/console.php`): keep jobs short and batched.

Commit messages end with the attribution lines the session's system reminder gives.

## Scott's standing rules
- Email to clients, suppliers or partners is a draft a person sends (REPSTACK principle 6); never send
  automatically.
- Shopify writes go to **CMAU_DEV** only (`SHOPIFY_ALLOW_LIVE` off). Never touch live CMAU without
  Scott's OK. The CMAU product shape and DecorationJSON (`docs/contracts/`) are fixed: only add fields.
- Prices come from SaaS pricing, never from AI. The supplier's name is never shown to clients (our code
  is). Branding templates are never linked on the website; they go with quote and invoice emails.
- No manual vetting: work data out from supplier pages, templates and size charts; what's left goes on
  the one Vet screen (`docs/vet.md`). Decoration types are never assigned by hand where a rule can say
  it (`docs/decoration-types.md`).
- Never commit secrets, `.env`, customer data or TOD exports. Never use or repeat the Promodata token.
  Never edit live ROD.mdb or TOD.mdb. Never enter passwords (Scott signs in himself). Don't bypass
  CAPTCHAs or Cloudflare. Customer artwork stays private.
- Images are public URLs only (product images); private files (artwork, templates) on private disks.
- Dex Group (Promodata supplier 19, merch) is not Dex Audio (ROD).

## REPSTACK (OneDrive › ReplicatData › REPSTACK)
- This project's folder: `SaaS Development` (summary.md first; summary-detail-to-2026-11-02.md for the
  history). Its Open questions list what is waiting on Scott.
- Read `README.md` once a session before a design decision or anything shared. Read another project's
  summary only when that component is involved: **ROD To SaaS** (rebuild and outputs vet lists,
  platform-and-freight-design.md) for Replicat; **CrowdLink** for CrowdLink; **Xero integration** for
  invoices and payments; **ROD booklet routing**, **ROD Preflight**, **ROD FedEx integration**, the
  archived Machship project for those modules.
- At the end of a session that changed a decision, rule or status: update this project's summary.md and
  its README row in one pass. Suggest other projects' changes to Scott (REPSTACK ADMIN makes them).

## Docs in this repo
Start with the one for the area: `divisions.md`, `vet.md`, `decoration-types.md`, `range.md`,
`pricing.md`, `price-check.md`, `product-options.md`, `branding-templates.md`, `logo-preview.md`,
`publisher.md`, `quotes.md`, `website-checkout.md`, `website-search.md`, `images.md`,
`supplier-codes.md`, `deploy.md`, `stack-and-security.md`, `launch-checklist.md`, `contracts/`.
For a new Replicat module, write its requirements in `docs/replicat/<module>.md` first (from the ROD To
SaaS vet lists and Scott's decisions), then build.
