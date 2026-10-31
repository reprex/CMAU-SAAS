# Logo preview on the website

Every product's hero image shows the **CrowdMerch logo** where the branding goes. When a shopper taps "Try your logo" and picks their own logo or design, it **replaces the CrowdMerch logo in the same place and size** on collection pages, the product page and My list. The shopper's file stays in their browser; it is only sent to us if they include it with a quote request.

## Logo areas (SaaS: Range > Logo areas)

Each hero image has one branding area, stored as a share of the picture: left, top and width in percent (`print_areas`, keyed by image URL so Promodata refreshes keep it). Staff vet about 100, not the whole range:

1. **Predict every product.** `catalogue:logo-areas --predict` gives every web-range product the normal area for its product type (tees and polos: chest; caps: front panel; bags: front centre; drinkware: centre; pens: barrel). The image check (`catalogue:check-images`) then asks Claude once per hero image for the normal branding position and size for that product, telling it the product's size and branding options; that replaces the type default as the prediction. Claude calls share the image check's daily cap.
2. **Vet a sample.** "Pick 100 samples" spreads 100 products across suppliers and product types (one per group, more for big groups). Staff drag the CrowdMerch logo into place on each, size it, and Approve ("White logo" helps on dark products).
3. **Learn and apply.** For every vetted sample the SaaS records how far staff moved the prediction (across and down in percentage points, size as a ratio). Every unvetted product gets its prediction corrected by the median correction of the closest group: same supplier and product type (2+ vetted), else same product type, else all vetted samples (10+). This runs every 10 minutes after an approval, or straight away with "Apply to the range".
4. **Check.** "What was learned" lists each group's correction and whether its samples agree. Where they disagree (spread over 6 points), "Add 25 more samples" picks 3 more from each such group first. The Learned tab lets staff spot-check and approve any product; approving it also teaches the group.

Vetted and learned areas go to the website; a product with only a prediction (no learning yet) shows no logo. If a hero image changes, its new image is predicted again and picks up the learned correction.

## What Shopify gets (`PrintAreas::forShopify`)

Product metafield `custom.printarea` (json), once vetted or learned: `{"image": "<hero url>", "left": 37, "top": 30, "width": 26}` (percent of the image). The theme places the logo over the hero image in that box only while the hero image shown is that `image`; product page controls (front centre / left chest, size) start from it.
