# Website step 1 search ("What kind of merch are you after?")

Endpoints (CORS, website origins only):

- `GET  /crowdmerch/search/token` returns a signed page token (and the Turnstile site key). The theme calls it when the page loads.
- `POST /crowdmerch/search` with `q`, `token`, `cf_turnstile` (Turnstile response) and the hidden `website` honeypot field (left empty).

Response: `mode` (`keyword` or `ai`), `understood` (qty, budget, colours, types…), `results` (id, name, type, image, qty, price, moq, lead_days, and `reason` for Claude picks). Prices always come from SaaS pricing (WebsiteLinePricer at the visitor's quantity with the default branding), never from the model.

## Order of checks (cheapest first)

1. Allowed origin (`WEBSITE_SEARCH_ORIGINS`, falls back to `WEBSITE_QUOTE_ORIGINS`).
2. Honeypot: filled means bot; reply looks normal, nothing is done.
3. Per-visitor rate limit (`WEBSITE_SEARCH_PER_MINUTE`, default 10).
4. Text sanity: 3 to 400 characters, no links or markup, no repeated characters, must contain words.
5. Signed page token: must be ours, at least 2 seconds and at most 60 minutes old.
6. Keyword search (free) always runs and is the answer unless all of these hold:
   - the text reads like a brief (event, staff, gift, "not …", a long description) or keyword search found fewer than 3 products;
   - the request is not from a verified bot or signed agent (`X-Verified-Bot: 1`, set by Cloudflare, see below). Those get keyword results, never Claude and never blocked;
   - Cloudflare Turnstile passed, for one of the `TURNSTILE_HOSTNAMES`;
   - the visitor is under `WEBSITE_SEARCH_AI_PER_HOUR` (default 5) and the site under `WEBSITE_SEARCH_AI_PER_DAY` (default 300).
7. A repeated brief (same normalised text) is answered from a 24-hour cache.
8. Claude (`ANTHROPIC_SEARCH_MODEL`, default claude-haiku-5-5) chooses up to 6 products from a SaaS-built shortlist of at most 40. Ids not on the shortlist are dropped. Any error falls back to keyword results.

Every request is logged in `search_logs` (hashed visitor, text, mode, reason, results, tokens, ms). Admin > Search log shows counts by mode and reason and the latest 200 searches.

## Server .env (secrets only here, never in git, chat or REPSTACK)

```
WEBSITE_SEARCH=true
WEBSITE_SEARCH_ORIGINS=https://crowdmerch.com.au,https://www.crowdmerch.com.au,https://<cmau-dev host>
TURNSTILE_SITE_KEY=...
TURNSTILE_SECRET=...
TURNSTILE_HOSTNAMES=crowdmerch.com.au,www.crowdmerch.com.au,<cmau-dev host>
TURNSTILE_PROTECT_WEBSITE_API=false   # true once the theme sends cf_turnstile with quotes and Add to my list
ANTHROPIC_API_KEY=...
```

Then run: `cd ~/saas-app && /usr/local/bin/ea-php84 artisan config:cache`

Until `TURNSTILE_SECRET` and `ANTHROPIC_API_KEY` are set, search works as keyword only.

## Cloudflare setup (replicat.com.au zone, where saas.replicat.com.au lives)

1. Turnstile: add a widget (Managed mode) for crowdmerch.com.au, www.crowdmerch.com.au and the CMAU_DEV host. Put the site key and secret in the server .env.
2. WAF rate-limiting rule: path starts with `/crowdmerch/search`, more than 30 requests in 1 minute per IP, then Block for 10 minutes. This stops floods before they reach cPanel.
3. Request Header Transform Rule: when `cf.bot_management.verified_bot` (or the verified-bot category / signed agent field available on the plan) is true, set request header `X-Verified-Bot: 1`; otherwise remove `X-Verified-Bot`, so nobody can fake it.
4. AI Crawl Control / AI bot policies: block AI training crawlers; allow search engines and user-triggered assistant fetchers (ChatGPT-User, Claude-User, Perplexity-User and similar). Keep robots.txt in step.
5. Do not turn on Bot Fight Mode for the whole zone: it can break Shopify webhooks and the stock relay. Use the rules above instead.

## AI agents

Assistants acting for a person mostly reach product and category pages directly (from search engines, or through Shopify Catalog and agentic storefronts), not through this box. Keep product and collection pages server-rendered with structured data and the sitemap. If an agent does use the search, it gets keyword results at no cost.
