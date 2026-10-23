# Shopify product contract

This is what the CMAU theme reads today. The publisher must produce exactly this shape. It was taken from the 19 Jul 2026 Matrixify full reload (4,991 products, 16,871 variants) and from the TOD export queries `PDW - Matrixify - Product Styles - Add Mod Disc` and `PDW - Matrixify - Product Colours (colours as variants)`.

Before go-live, check this contract against a downloaded copy of the CMAU theme.

## Product

| Field | Rule |
| --- | --- |
| Handle | APPAID, for example `2568` |
| Title | `AlphaNumericSU(WebShortDesc, else ShortDescription) + " [" + SupplierID + "-" + SupplierProductID + "]"` |
| Body HTML | Long description (WebTopLong, else LongDescription, with `<p>` markers turned into `<br>`). Then, in order: the why-we-love-it paragraph; "Available sizes: …"; "Dimensions: …"; a `<details>` block "Additional options available for custom quote" (accessory list); a `<details>` block "Product Specifications" (HTMLDetail) |
| Type | `Product` |
| Tags | `style`, then brand, label and catalogue matches, then the no-BLC supplier match, then the subcategory, then `external` if APPAID > 10,000,000, then the Promodata attribute CSV, then the extra tag CSV |
| Status | `Active`; `Archived` if the product is discontinued or removed from the range |
| Published / scope | `TRUE` / `web` (`FALSE` when archived) |
| Template suffix | `colour_as_variant_product` |
| Images | Public URLs (Promodata Cloudinary or our own bucket). Merged, never replaced |

## Product metafields

| Metafield | Type | Rule |
| --- | --- | --- |
| `custom.decorationjson` | multi_line_text_field | See [decorationjson.md](decorationjson.md) |
| `custom.collectiondisplayqty` | number_integer | CollectionMOQ |
| `custom.collectiondisplayprice` | number_decimal | CollectionVariantPrice |
| `custom.mastersortorder` | number_integer | MasterSortOrder; 99999 if empty or below 1 |
| `custom.tagcategory` | single_line_text_field | `any,` + category codes |
| `custom.tagsubcategory` | single_line_text_field | `any,` + subcategory codes |
| `custom.tagcolourgroup` | single_line_text_field | `any,` + colour groups |
| `custom.tagbrandingoption` | single_line_text_field | `any,` + branding options (embroider, engrave, spotcolour, fullcolour, anyprint, foil, embossdeboss, sublimation) |
| `custom.tagspecial` | single_line_text_field | `any,` + special tags (sleeve type, gender/age, 3xl+) |
| `custom.tagcollection` | single_line_text_field | `any,` + collections (essentials, urgent, impactaware, brand ranges) |
| `custom.essentialssubcat` | single_line_text_field | SubCatEssentials, else Category |
| `custom.urgentsubcat` | single_line_text_field | SubCatUrgent, else SubCategory |
| `custom.ecorecsubcat` | single_line_text_field | SubCatEcoRec, else SubCategory |
| `custom.stockcolumns` | single_line_text_field | The supplier's SupplierStockColumnCSV |
| `custom.nostockmessage` | single_line_text_field | Fixed text: "This product is routinely kept in stock in large quantities in all colours. We will confirm again at the time of order placement." |
| `custom.undermoqtext` | single_line_text_field | UnderMOQText (small-run surcharge note) |
| `my_fields.pdpcollectionname` | single_line_text_field | VetSubCatMatch |
| `title_tag` | string | `"Custom " + SEODecorationText + " " + name + " [" + SupplierProductID + "]"`; if that is over 70 characters, use just the name and the code |

## Variants (colours as variants)

| Field | Rule |
| --- | --- |
| Option1 name / value | `Color` / colour name (with `.png` removed) |
| SKU | `APPAColStringID`, for example `1452-yellow` |
| Price | CollectionVariantPrice (the same for every colour) |
| Position | Colour-family display order (9999 if unset) |
| Variant image | Public URL of the mapped colour image |
| `custom.productmoq` (variant metafield) | CollectionMOQ |
| `custom.altimagepublicpath` (variant metafield, url) | Alternate colour image URL |
| `custom.altimage` (variant metafield, file_reference) | Not set today |

A variant is deleted when its product is discontinued.

## Publishing rules

- Use the GraphQL Admin API, one product per call, setting the product, its variants, metafields and media together. Full-range reloads run as a bulk operation.
- Store the Shopify product, variant and media IDs, and only send products whose output has changed.
- Never use REPLACE semantics on images.
- Log every run: what was sent, and what succeeded or failed (with Shopify's error text).
