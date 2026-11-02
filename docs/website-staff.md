# Website staff tools

Staff tools on the CMAU website (Scott, 3 Nov 2026). Stage 1: range curation from the product page. Stage 2 (next): quotes and orders for clients from the product page.

## Signing in

The website's **Staff sign-in** opens `/website-staff/connect?origin=…` in a pop-up. That page needs the normal SaaS sign-in with the two-step code; it then makes a **staff pass** and hands it only to the website window that opened it (`postMessage` to that exact origin, which must be in `WEBSITE_ORDER_ORIGINS`). No password is ever typed into the shop.

- A pass lasts 12 hours (`WEBSITE_STAFF_PASS_HOURS`) or until **Sign out**. Only its SHA-256 hash is stored (`website_staff_passes`).
- Scopes: `curate` and `assist` for admin and sales accounts; read-only accounts get no staff tools.
- Calls: `Authorization: Bearer <pass>` to `crowdmerch/staff/*`, with the same origin allow-list and per-IP limit as the other website calls. An expired or revoked pass answers 401 `{signed_out: true}`.

## Curation from the product page

`GET crowdmerch/staff/products/{id}/curation`: what the pop-up shows (title, description, pictures and their tags, colours, minimums, branding positions, on the website or not, open requests).

`POST crowdmerch/staff/products/{id}/curation` `{changes, note, page}`. Changes (any of): `title`, `description`, `hero_image_url` (one of the product's pictures), `hidden_colours`, `collection_moq` (display minimum), `actual_moq` (production minimum), `positions` (this product's branding positions; empty = back to automatic), `on_website`. `null` puts a field back to automatic.

- **An admin's changes apply straight away** (Scott's choice): the request is approved, a queued job (`ApplyCurationRequest`, minute cron) applies it the way Range › product does and republishes the product to Shopify. When admin screens are limited to the office (`ADMIN_ALLOWED_IPS`), an admin outside the office is treated like other staff.
- **Everyone else's wait** under SaaS › **Curation requests** for an admin to Approve or Reject. Failed ones can be tried again.
- A **note on its own** is a to-do for the team; an admin marks it Done.
- The website's product never changes directly: the SaaS overrides are the source and the publisher pushes them back.
