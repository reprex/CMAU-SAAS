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

There is always exactly one hero style. With no hero from Promodata, the first style image is used, then the first colour image.

## Staff tags

The product screen (Range > product) shows every image with its tag and colour. Change any of them and save. Only differences from the automatic tags are stored (override `image_tags`, keyed by image URL; the hero goes in `hero_image_url`), so they survive Promodata refreshes and new images are still tagged automatically.

After deploying, tag the existing range once: `cd ~/saas-app && /usr/local/bin/ea-php84 artisan catalogue:tag-images`

## What Shopify gets (`ImageTagger::forShopify`)

- Product media, in order: hero style, other style, then each colour's colour image. This is what TOD's Matrixify upload did (Image Src = style images, Variant Image = colour image), so the live theme behaves as today.
- Variant image: the colour's colour image. Collection pages filtered by colour, and the theme's "show colour variants" option, use this image.
- Variant metafield `custom.altimagepublicpath` (url): the first other colour image, as TOD.
- New variant metafield `custom.altimages` (list.url): all other colour images of that colour. Additive: the live theme does not read it. Other colour images are not product media, which keeps the live gallery as it is and stays well under Shopify's media limit.
- Hidden colours send nothing.

## Product page (new `product.hybrid` template on CMAU_DEV)

- On load: `product.featured_media` (hero style) and the other style images, that is product media that is not any variant's `featured_media`.
- When a colour is picked: that variant's `featured_media`, then the URLs in `variant.metafields.custom.altimages`.
