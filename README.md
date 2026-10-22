# Irios for WooCommerce

WordPress plugin that connects a WooCommerce store with **IRIOS Media**:

- **Events and identity**: `page-view`, `category-view`, `search`, `product-view` (including the selected variation),
  `checkout-step`, `add-to-cart`, `remove-from-cart` and `cart-view`. The browser is identified by the IRIOS Media
  cookie (`irs1P`), which is also stored in the order; for logged-in customers, also their id and their hashed email
  and phone (SHA-256). Purchases are not sent by the plugin: IRIOS Media's synchronization service sends them from the
  store's orders.
- **Sponsored Products** (optional): product ads in the search results and in the theme's live search, rendered with
  the store's own product card and an ad label ("Sponsored").
- **Banners** (optional): banners through a shortcode or at automatic positions (below the product listing of a
  category and before the description on the product page).
- **Ad transparency** (EU Digital Services Act, art. 26): an info button next to every ad label opens "About this
  ad", with why the ad is shown and on whose behalf it is shown and who paid for it.

**Privacy.** Without the visitor's marketing consent, only contextual ads are shown (based on the search or the page):
no identifiers, no IRIOS Media cookies and no events. With consent, events, identifiers and personalised ads as well.

Requirements: WordPress 6.4+, PHP 8.1+ and WooCommerce 8.5+.

## Installation

1. Download `irios-woocommerce.zip` from the [latest release](../../releases/latest).
2. In WordPress, **Plugins → Add New → Upload Plugin**, choose the zip, **Install Now** → **Activate**. WooCommerce
   must be active.
3. Go to **WooCommerce → Settings → Integration → Irios Media** and fill in the settings (below) with the details
   provided by IRIOS Media.
4. Make sure the store has a cookie banner compatible with the selected consent mode.

### Updates

The plugin never updates itself. When a new version is published, administrators who can update plugins see a notice
on the Dashboard, Plugins and WooCommerce settings pages with links to the release notes and the zip. To update,
upload the new zip the same way: WordPress detects it is already installed and offers **Replace current with
uploaded**. Settings are kept. The notice can be dismissed per version. What changes in each version is in the
[changelog](CHANGELOG.md).

To know about new versions, the store checks the public list of releases on GitHub at most every 12 hours, only when
one of those admin pages is opened; no store data is sent. To turn the check off, add to the theme or a custom plugin:

```php
add_filter( 'irios_woocommerce_check_updates', '__return_false' );
```

### Uninstall

**Plugins → Deactivate** and then **Delete**. Once deactivated it stops loading and sending anything; deleting it
removes its settings. The browser identifier stored in past orders is kept as part of the order history. If the
dashboard does not respond, delete the `wp-content/plugins/irios-woocommerce` folder over FTP/SSH.

## Settings

**WooCommerce → Settings → Integration → Irios Media.** Nothing configured here is secret: it all reaches the browser.

| Setting | Description |
|---|---|
| Irios API domain | Domain of the store's network, without `https://` or path (e.g. `example.iriosmedia.online`) |
| Store ID | Numeric id of the store in IRIOS Media |
| Consent | How the plugin knows whether the visitor accepted marketing cookies: **WP Consent API** (with a compatible banner), **Asesor de Cookies RGPD** (reads its `cdp_cookie_consent` cookie) or **Always** (the store handles consent another way) |

**Sponsored Products** (the fields appear once enabled):

| Setting | Description |
|---|---|
| Enable | Shows ads in the search results and in the live search |
| Positions | One row per ad: **Position**, **Ad unit (id)** and **Auth** (see below) |
| Ad label | Text of the sponsored cards ("Sponsored" by default, translated to the store language); do not leave it empty |
| Single-result search | Keeps the results page instead of redirecting to the product, so ads can be shown |

**Positions**: each row is a slot in the results grid. Position 1 is top left and positions follow reading order (left
to right, row by row); with 4 columns, position 5 is the first of the second row. An ad unit can take several
positions (e.g. 1001 at 1 and 2, and 1002 at 6):

- All ad units go in a single auction and each one asks for as many ads as positions it has. Its winners take its
  positions from lowest to highest. If it wins fewer ads, its last positions keep the organic results.
- A product that already won in another ad unit is not repeated. The organic result for the same product is hidden.
- Positions count the visible results; if the page has fewer, the ad goes at the end. In a search with no results,
  the sponsored products are shown above the "no products found" notice.
- The live search uses the same positions, counted as rows of the dropdown.

**Banners** (the table appears once enabled): one row per banner with **Tag** (lowercase, digits, `-` and `_`;
unique), **Ad unit (id)**, **Auth** and **Position**:

- **Manual (shortcode)**: shown wherever `[irios_banner tag="TAG"]` is placed (a page, a page-builder element, a block,
  a widget or a category description).
- **Category page: bottom**: below the product grid and its sorting and pagination controls, centred.
- **Product page: middle**: before the product page tabs.

Unchecking the box turns all banners off without touching the pages.

## Languages

The settings page, the notices, the "About this ad" dialog and the default ad label follow the store language:
English and Spanish are included; any other language shows the English texts. The ad label can also be set per store
in the settings.

## Theme compatibility

Events, identity and the sponsored product card rely on standard WooCommerce hooks and templates, so they work with any
theme. Where ads are placed depends on the theme: the results grid, the live search dropdown and the automatic banner
positions. If an ad cannot be placed, it is simply not shown and the store keeps working.

<!-- compat:start -->
Tested on 2026-10-21. ✅ works · ❌ fails · — not applicable, e.g. the theme has no live search.

| Theme | Sponsored in search | Label + “About this ad” | Card like the results | Add to cart on the ad | Live search | Category banner | Product page banner |
|---|---|---|---|---|---|---|---|
| `astra` | ✅ | ✅ | ✅ | ✅ | — | ✅ | ✅ |
| `blocksy` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `generatepress` | ✅ | ✅ | ✅ | ✅ | — | ✅ | ✅ |
| `kadence` | ✅ | ✅ | ✅ | ✅ | — | ✅ | ✅ |
| `neve` | ✅ | ✅ | ✅ | ✅ | — | ✅ | ✅ |
| `oceanwp` | ✅ | ✅ | ✅ | ✅ | — | ✅ | ✅ |
| `ridestyle` | ✅ | ✅ | ✅ | — | ✅ | ✅ | ✅ |
| `storefront` | ✅ | ✅ | ✅ | ✅ | — | ✅ | ✅ |
| `twentytwentyfive` | ✅ | ✅ | ✅ | ✅ | — | ✅ | ✅ |
| `storefront` + FiboSearch plugin | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `storefront` + YITH WooCommerce Ajax Search plugin | ✅ | ✅ | ✅ | ✅ | ❌ | ✅ | ✅ |
<!-- compat:end -->

- A child theme of a supported theme is supported too.
- Live search: besides the theme's own live search, ads are placed in the dropdown of the **FiboSearch** plugin. The
  **YITH WooCommerce Ajax Search** plugin is not supported.
- With any other theme, the settings page shows an "unsupported theme" warning: contact IRIOS Media support.

## Troubleshooting

With `?irios_debug=1` in the URL (or `localStorage.irios_debug = '1'` in the browser console), the console shows what
is sent (`[irios] events`, `[irios] ad request`, `[irios] ad response`), one line per ad request with its timing, and
a warning when an ad cannot be placed on the theme:

```
[irios-woo] ad unit 1001 · search "running shoes" · Irios answered in 411 ms with 1 ad · rendered in 95 ms · ad visible 967 ms after the page started loading
[irios-woo] ad unit 1002 · banner · Irios answered in 350 ms with no ads
[irios-woo] live search: no dropdown with product links found next to the search field, no ads shown there (theme: …)
```

- Nothing is sent and only contextual ads are shown until the cookie banner is accepted: accept it to test everything.
- The browser's mobile emulation is seen as a mobile device, so desktop-only campaigns are not served.
- **Sponsored Products do not show although an ad is returned**: WordPress may not be returning the product cards,
  for example because another plugin breaks the request. The plugin then renders the ad with the catalog data from
  IRIOS Media and says so in the console. To find out which plugin breaks it without access to the server logs, open
  `https://<store>/wp-json/irios/v1/cards?layout=data&ids=<product id>&irios_debug=1`: the response lists the failing
  fields and why (e.g. `product 6172 · priceHtml: Error: … (file.php:123)`).

## License

[GPL-2.0-or-later](LICENSE).
