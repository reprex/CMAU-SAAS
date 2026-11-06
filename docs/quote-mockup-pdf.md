# Quote and mockup PDF (Quick quote)

Quick quote and "Send me this mockup PDF" are one process and one document (Scott, 1 Nov). There is no separate "free digital proof": the PDF is the mockup.

## What the shopper does

Product page (Quick quote, or the button at the end of the Branding section), a collection page (pick several products) or My list. They give name and email (phone and date optional) and get one email with one PDF: each product's mockup (front and back where used) with their logos at the chosen positions, and the quote (unbranded price, each position's decoration, setups, totals, quantity).

## Built (7 Nov 2026, Scott: everything centres on one product)
- `POST /crowdmerch/homepage/quote-pdf` (same guards as the quote API): `name`, `email`, `phone`, `need_by`, `cart` (JSON, as the checkout: product_id, lines, positions, options), `logos[i]` per position, `mockups[]` (the theme draws each mockup side as a JPEG, as the shopper saw it), `similar[]` (the similar products shown, up to 4), `saved[]` (hearted products). Priced exactly as the checkout prices it (`StaffQuotes`, no staff user); the quote desk gets it as source website, status QUOTE; emailed with the PDF when `WEBSITE_EMAIL_INSTANT_QUOTE` is on.
- **PDF page 1**: only the requested product (with any related styles in the same run): the mockups (else the uploaded artwork as a stand-in) and the product unbranded, size mix, each line, totals. **Page 2**: up to 4 similar products (else the closest on the web range) and the hearted ones, each with code, "from" price and link.
- Not yet: the design link `/d/CODE` to restore the design on the product page.

## What the SaaS does (original plan)

1. `POST /crowdmerch/homepage/quote-pdf` (website API, same guards as the quote API: origin, honeypot, rate limit, Turnstile when on): products, colours, quantities, positions with method, size, up/down and a logo per position (uploaded files).
2. Saves a **design** with a short code (e.g. `SS6QN6`): the selections as JSON, the logos stored privately (not public URLs; customer artwork), the customer's name and email. Creates the quote in the quote desk (source "website", status QUOTE) so staff see it straight away.
3. Renders the PDF on the server from the same pictures and logo areas the website uses (hero image, `custom.printarea` / branding positions, curve shape), priced by the SaaS (never the browser).
4. Email with the PDF attached and the link `https://crowdmerch.com.au/d/SS6QN6`.
5. The link opens the product page with the design restored (colour, quantity, positions, methods, logos, sizes) so they can change anything and send themselves a new PDF, or add it to My list. Designs expire after a set time (e.g. 12 months); the logos are deleted with them.

## Open decision

Principle 6 says emails go to drafts and are never sent. An instant quote PDF to the shopper only works if it is sent automatically. Either Scott approves this as an exception (sent from a no-reply address, copy to the CrowdMerch inbox), or the email is created as a draft for staff to send, which loses the "instant".
