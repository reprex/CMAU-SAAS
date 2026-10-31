# Product images: tags and where they show

Every product image has one of four tags, as TOD's PDS_LocalImages flags did (HeroFlag, StyleFlag, AltColourFlag and the colour map):

| Tag | What it is | Where it shows |
| --- | --- | --- |
| Hero style | The one main picture of the product | Collection pages (PLP), first image on the product page |
| Other style | Other views of the product, not a particular colour (back, side, detail) | Product page on load, after the hero |
| Colour | The main picture of one colour | Collection pages when that colour is filtered; product page once the colour is picked |
| Other colour | More pictures of one colour (back, side of that colour) | Product page once that colour is picked |

## Automatic tags

Worked out on every Promodata refresh (`App\Catalogue\ImageTagger`), in this order:

1. A colour's own Promodata image (`colours.list[].image`) is that colour's colour image.
2. The hero (the staff choice, else Promodata's `hero_image`) is the hero style. If it is also a colour's image it stays that colour's variant image too.
3. Any other image whose file name contains a colour's name is an other colour image of that colour, for example `4001_maple_tee_pistacio_back` is Pistachio. The longest colour name wins ("Navy/White" before "Navy"), long words may be one letter out, and a colour word that is also in the product name ("Natural Jute Bag") is ignored.
4. Everything else is other style.

There is always exactly one hero style.

## Claude image check

File names only help when the supplier names its files well (AS Colour: `4001_maple_tee_pistacio_back`; JB's Wear: `210_1_01` says nothing). So Claude also looks at the pictures (`App\Catalogue\ImageVision`, `catalogue:check-images`, every scheduler minute):

- Only products on the web range, a few a minute, and each image URL only once (results in `image_checks`). Daily cap `IMAGE_CHECK_PER_DAY` (default 3,000 images). Off until `IMAGE_CHECK=true` and `ANTHROPIC_API_KEY` are set.
- One request per product (up to 16 images): the hero and colour images first as references, then the rest, each with its file name and what the file name suggests.
- The rule Claude applies: the product in the hero's colour (another angle, a close-up of a feature, in use) is other style; the product clearly in another colour is other colour, with that colour from the product's colour list.
- How the answers are combined (confidence below 0.7 is shown but not used):

| Automatic tag | Claude says | Result |
| --- | --- | --- |
| Hero style (Promodata) | Not a whole-product picture | Stays hero, flagged |
| Colour (Promodata) | A different colour | Stays, flagged |
| Other colour X (file name) | Colour X | Other colour X, confirmed |
| Other colour X (file name) | Another colour, or the hero's colour | Stays X, flagged |
| Other style (nothing in the file name) | Colour Y | Other colour Y |
| Other style (nothing in the file name) | Hero's colour | Other style, confirmed |
| Any | Unsure, or couldn't load the image | Unchanged, flagged |

Flagged images show a warning on the product screen, and the product appears under Range > "Images to check". Saving the product keeps what staff left in place as their tag, which clears the flags. Staff tags are never changed by Claude. With no hero from Promodata, the first style image is used, then the first colour image.

## Staff tags

The product screen (Range > product) shows every image with its tag, colour, what Claude saw and any warning. Change any of them and save. Only differences from the automatic tags are stored (override `image_tags`, keyed by image URL; the hero goes in `hero_image_url`), so they survive Promodata refreshes and new images are still tagged automatically.

After deploying, tag the existing range once: `cd ~/saas-app && /usr/local/bin/ea-php84 artisan catalogue:tag-images`

To try Claude on one product first: `cd ~/saas-app && /usr/local/bin/ea-php84 artisan catalogue:check-images --product=43331`

## What Shopify gets (`ImageTagger::forShopify`)

- Product media, in order: hero style, other style, then each colour's colour image. This is what TOD's Matrixify upload did (Image Src = style images, Variant Image = colour image), so the live theme behaves as today.
- Variant image: the colour's colour image. Collection pages filtered by colour, and the theme's "show colour variants" option, use this image.
- Variant metafield `custom.altimagepublicpath` (url): the first other colour image, as TOD.
- New variant metafield `custom.altimages` (list.url): all other colour images of that colour. Additive: the live theme does not read it. Other colour images are not product media, which keeps the live gallery as it is and stays well under Shopify's media limit.
- Hidden colours send nothing.
- No colours (or every colour hidden): one variant, Option1 `Color` = `Custom Print`, SKU `APPAID-customprint`, colour family `cmyk`, variant image = hero style. This is what TOD does today (10 such products in the July reload). Every image is then hero or other style; Claude can't tag other colour because there is no colour to choose.
- A colour with no picture of its own: variant image = hero style. TOD dropped these colours (its query needed a mapped image); showing the hero keeps the colour on sale and in colour filters.

## Product page (new `product.hybrid` template on CMAU_DEV)

- On load: `product.featured_media` (hero style) and the other style images, that is product media that is not any variant's `featured_media`.
- When a colour is picked: that variant's `featured_media`, then the URLs in `variant.metafields.custom.altimages`.
- One variant only (a Custom Print product, or a single colour): no colour step; the product page shows the style images and the variant is already chosen. A single real colour shows its name as a label instead of a picker.
