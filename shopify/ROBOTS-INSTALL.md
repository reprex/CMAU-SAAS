# Adding the CrowdMerch robots.txt to the live store

## Checks done on 24 Oct 2026

- **Nothing else generates robots.txt.** The live theme export (`crowdmerch-2025-refactor`, exported 24 Oct 2026, 06:09) has no `templates/robots.txt.liquid`, and no other theme file mentions robots. Shopify generates the current robots.txt itself.
- **Apps can't overwrite it.** Shopify serves robots.txt from one place only: the `robots.txt.liquid` template in the published theme, or Shopify's default if that template doesn't exist. Apps can't write to it.
- **It can't break the site.** robots.txt is a plain text file that bots read. It plays no part in how pages render, check out, or how the PDP, PLP and wish list work. If the template has a Liquid error, Shopify falls back to showing the error text in robots.txt, and the storefront is unaffected.
- **The template keeps Shopify's own rules.** It renders `robots.default_groups`, so Shopify's default rules and sitemap stay in place and keep updating automatically.

## What changes

- Asks crawlers to stay out of the bulk catalogue feeds: `/products.json`, `/collections/*/products.json`, and the per-product `.json`, `.js` and `.oembed` files. These feeds are how other sites copy a whole store in seconds.
- Blocks known bulk scrapers and dataset crawlers entirely: Scrapy, img2dataset, Bytespider, CCBot, Diffbot and similar.
- **AI assistants are explicitly welcome.** The header invites AI assistants to search and read product, collection and information pages, and to link people to the product page or Quick Quote. They also get their own group ending `Allow: /`. That group covers OAI-SearchBot, ChatGPT-User, Claude-SearchBot, Claude-User, PerplexityBot, Perplexity-User, DuckAssistBot, MistralAI-User and Applebot. It repeats Shopify's default exclusions (admin, cart, checkout) and the bulk-feed rules, so it adds a welcome without opening anything else.
- Search engines (Googlebot, Bingbot) follow the general rules and can read every product page, as before.
- Your theme's JavaScript (wish list, quick quote) is unaffected, because browsers ignore robots.txt.

robots.txt only stops bots that choose to obey it. A determined scraper can still read `/products.json`. Removing supplier codes from titles and image names before the SaaS goes live remains the real protection.

## How to add it (about 5 minutes)

**Option A: test on CMAU_DEV first (recommended)**

1. In the CMAU_DEV admin, go to **Online Store → Themes → … → Edit code**.
2. Under **Templates**, click **Add a new template** and choose **robots.txt**. Shopify creates `templates/robots.txt.liquid`.
3. Replace its contents with `shopify/robots.txt.liquid` from this repo, then **Save**.
4. Open `https://<cmau-dev-domain>/robots.txt` and check that:
   - Shopify's normal rules are still there (Disallow: /admin, /cart, /checkout and so on).
   - The new "Bulk catalogue feeds" lines appear under `User-agent: *`.
   - The `Sitemap:` line is still there.
   - The "AI search and shopping assistants" group ends with `Allow: /`.
   - The scraper block is at the end.

**Option B: live CMAU (after A looks right)**

1. **Back up first:** in the live admin, go to **Online Store → Themes**, then on the live theme choose **… → Duplicate**.
2. Repeat steps 1–3 above on the **live** theme. This adds one new file only, and no existing file is edited.
3. Open `https://crowdmerch.com.au/robots.txt` and run the same checks.
4. Note that Shopify's "Agent instructions / UCP" comment lines at the top of the current file may no longer appear. Those are comments only. AI agents still discover Shopify's agent endpoints through Shopify itself.

**To undo:** delete `templates/robots.txt.liquid` (Edit code → right-click the file → Delete). Shopify immediately goes back to its default file.

## When CMAU_DEV is deployed as live

If a CMAU_DEV theme is ever published to the live store, make sure it contains this same `templates/robots.txt.liquid`. Otherwise publishing it silently removes these rules.

## Replicat (replicat.com.au)

`shopify/replicat-robots.txt.liquid` is the same file worded for Replicat. Install it the same way on the Replicat store's live theme: back up, add the robots.txt template, paste, save.

- It keeps the agent links that Shopify's newer default robots.txt puts in its header (`agents.md`, UCP discovery, UCP/MCP endpoint). A custom template replaces those comment lines, so they are repeated in our header.
- Replicat's terms of trade do not mention scraping, so the header asks politely and does not cite a clause.
- Check `https://replicat.com.au/robots.txt` afterwards. The header should be there, Shopify's rules should be unchanged under `User-agent: *` followed by the "Bulk catalogue feeds" lines, and the AI assistants group should end with `Allow: /`.
