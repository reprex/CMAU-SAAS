# Website project requests

The website's "Design something" project survey (Scott, 3 Nov 2026), rebuilt from forms.app. Theme section `cm-project-request` (CMAU-THEME) posts here.

## Endpoint

`POST /crowdmerch/projects` (multipart). Same guard as website orders (`GuardsWebsiteOrders`): allowed origins (`WEBSITE_ORDER_ORIGINS`), honeypot field `website`, a per-IP limit (`website.projects.per_hour`, 6) and Cloudflare Turnstile (`cf_turnstile`) when `search.turnstile.protect_website_api` is on.

| Field | |
| --- | --- |
| `brief` | required, up to 4,000 characters |
| `kinds[]` | optional: garments, headwear, bags, drinkware, giftware, events, custom, unsure |
| `quantity` | required: a number or a description |
| `budget`, `need_by` (Y-m-d, future), `event` | optional |
| `first_name`, `email`, `phone` | required; `last_name`, `organisation`, `contact_by` (email/phone) optional |
| `files[]` | up to 6, 20 MB each: images, PDF, AI/EPS/PSD, Office, zip. Private `artwork` disk under `project-requests/PR-xxxxx/` |

Reply: `{success, number, reply_by, reply_by_text, email, confirmation}`.

## Reply-by date and emails

`reply_by` is one working day out (`website.projects.reply_working_days`), skipping weekends and VIC public holidays (`BusinessDays`). A queued job (`SendProjectRequestEmails`, run by the minute cron) sends:

- the client's confirmation: their request in full and "We'll be back in touch by the end of <day>". Scott asked for this to be sent straight away (an acknowledgement, like the instant quote email), so it is not held as a draft under REPSTACK principle 6. Switch: `WEBSITE_PROJECT_CONFIRMATION=false`.
- a staff notice to `WEBSITE_PROJECTS_STAFF_EMAIL` (default: the website orders staff email), with a link to the request.

## Staff

SaaS › Project requests: new requests first, soonest reply-by at the top, overdue flagged. Each request shows the brief, details, client, files (downloads are logged), and a status (New, Replied, Closed) with notes.
