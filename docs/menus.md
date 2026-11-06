# Store menus (Scott, 7 Nov 2026)

The CMAU theme's header drawer, Popular quick links and footer read these Shopify menus by handle.
They are defined in `config/shopify-menus.php` and written by `shopify:menus` (`st menus` shows
the plan, `st menusgo` writes it). Only these handles are ever written; the store's other menus
(`main-menu`, `cmautopnew`, `foot-*-new`) are left alone.

| Handle | Used for |
| --- | --- |
| `cm-main` | Header menu: Find products (10 categories), Get started, Case studies, Services, Help |
| `cm-popular` | Popular quick links at the top of the phone menu (shown open) |
| `cm-foot-clothing`, `cm-foot-merch`, `cm-foot-crowdmerch`, `cm-foot-help` | Footer columns |

- Pages and collections are linked by id, so a changed handle doesn't break a link. A target that
  isn't on the store is left out and listed in the report.
- Popular items (`pick:`) use the range collection for the subcategory tag (`shopify_collections`,
  from `st cols`), then a collection with that title, then the category hub.
- SEO: real links to collection and page URLs only (no `?cat=` filter links), one URL per label,
  no `#` or `-` items. The footer uses the longer search phrases ("Custom printed T-shirts").
- Until `cm-main` exists the theme keeps the old header menu; until the footer menus exist it keeps
  the old footer. So the theme can go first.

**App scopes:** the Dev Dashboard app needs `read_online_store_navigation` and
`write_online_store_navigation` (add them to the app version and release it).

**Go-live:** run `st menus` then `st menusgo` on the live store after the switch, as the menus are
store data and don't move with the theme.
