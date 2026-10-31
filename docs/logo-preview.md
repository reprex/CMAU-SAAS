# Logo preview on the website

Every product's hero image shows the **CrowdMerch logo** where the branding goes. When a shopper taps "Try your logo" and picks their own logo or design, it **replaces the CrowdMerch logo in the same place and size** on collection pages, the product page and My list. The shopper's file stays in their browser; it is only sent to us if they include it with a quote request.

## Logo areas (SaaS: Range > Logo areas)

Each hero image has one branding area, stored as a share of the picture: left, top and width in percent (`print_areas`, keyed by image URL so Promodata refreshes keep it).

1. **Starting point:** the area for the product type (tees and polos: left chest/front; caps: front panel; bags: front centre; drinkware: centre; pens: barrel), or a **Claude suggestion**: the image check (`catalogue:check-images`) asks Claude once per hero image where the branding goes. Suggestions share the image check's daily cap and never overwrite a vetted area.
2. **Vetting:** the Logo areas screen shows web-range products with the CrowdMerch logo placed. Staff drag the logo into place and drag its corner to size it (or use arrow keys, + and -), then Approve. "To vet" and "Approved" tabs; any staff member who can edit the range can approve. Approving marks a live product for publishing.
3. **Only approved areas go to the website.** Until a product is vetted, the site shows no logo on it.

If the hero image changes (staff pick another, or Promodata replaces it), the new image needs vetting again.

## What Shopify gets (`PrintAreas::forShopify`)

Product metafield `custom.printarea` (json), once approved: `{"image": "<hero url>", "left": 37, "top": 30, "width": 26}` (percent of the image). The theme places the logo over the hero image in that box only while the hero image shown is that `image`; product page controls (front centre / left chest, size) start from it.
