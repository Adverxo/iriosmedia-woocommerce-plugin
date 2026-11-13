# IRIOS Media for WooCommerce

WordPress plugin that connects a WooCommerce store with **IRIOS Media**:

- **Events and identity**: `page-view`, `category-view`, `search`, `product-view` (including the selected variation),
  `checkout-step`, `add-to-cart`, `remove-from-cart` and `cart-view`. The browser is identified by the IRIOS Media
  cookie (`irs1P`), which is also stored in the order with the consent answer; for logged-in customers, also their id
  and their hashed email and phone (SHA-256). Purchases are not sent by the plugin: IRIOS Media's synchronization
  service reads them, only with consent, through the plugin's own API (see below).
- **Sponsored Products** (optional): product ads in the search results and in the theme's live search, rendered with
  the store's own product card and an ad label ("Sponsored").
- **Display** (optional): image and video ads through a shortcode or at automatic positions (category: sidebar, inside
  the grid, after the products; product page: before the description, before the related products), per device.
- **Push Notifications** (optional): brand notifications for the shoppers who subscribe in their browser, asked after
  a purchase or after a number of pages, only with consent.
- **Ad transparency** (EU Digital Services Act, art. 26): an info button next to every ad label opens "About this
  ad", with why the ad is shown and on whose behalf it is shown and who paid for it.

**Privacy.** Without the visitor's marketing consent, only contextual ads are shown (based on the search or the page):
no identifiers, no IRIOS Media cookies and no events. With consent, events, identifiers and personalised ads as well.

Requirements: WordPress 6.4+, PHP 8.1+ and WooCommerce 8.5+.

**API for the IRIOS Media sync service.** Orders and customers are read through the plugin's API, which gives the
shopper's identifiers already hashed in the store (SHA-256 of the normalized email and of the phone in E.164, the same
rules as the storefront): names, emails, phones, addresses and IP addresses never leave WooCommerce.

| Endpoint (`/wp-json/irios/v1/...`) | Returns |
|---|---|
| `orders?status=&modified_after=&created_after=&created_before=&page=&per_page=` | Paid orders (`processing`, `completed`): amounts, lines and whether there was consent. Only with consent: the hashed identifiers, the IRIOS browser ID and the billing country, state and postcode. |
| `orders/<id>` | One order |
| `customers?page=&per_page=`, `customers/<id>` | Registered customers with an order placed with consent: customer ID, hashed identifiers and billing country, state and postcode |

It is authenticated with a WooCommerce REST API key with read access (WooCommerce → Settings → Advanced → REST API),
over HTTPS. The sync service only uses WooCommerce's own API for the catalog, and only product webhooks: delete any
order or customer webhook pointing to it, since WooCommerce sends the full order or customer in them.

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

#### Updating from "Irios for WooCommerce"

The first version of the plugin was called "Irios for WooCommerce" and lived in the `irios-for-woocommerce` folder.
WordPress sees this one as a different plugin, so it does not replace it:

1. **Plugins → Deactivate** "Irios for WooCommerce". Do not delete it yet, so it can be reactivated if needed.
2. Upload and activate `irios-woocommerce.zip` as above. The settings are shared, so they are already there: check
   them in **WooCommerce → Settings → Integration → IRIOS Media**, as this version adds settings the old one did not
   have (page types are sent on their own, Display placements and push notifications).
3. Once it works, **Delete** the old plugin.

While both are active only the old one runs, and the new one shows a notice asking to deactivate it. The old plugin's
uninstall deletes the shared settings, but this one saves them and writes them back, so deleting it does not lose the
configuration.

Shoppers get a new IRIOS Media identifier: the old plugin used its own `_irios_uid` cookie, and this one uses the
tracker's `irs1P`, the same as every other IRIOS Media integration.

### Uninstall

**Plugins → Deactivate** and then **Delete**. Once deactivated it stops loading and sending anything; deleting it
removes its settings. The browser identifier stored in past orders is kept as part of the order history. If the
dashboard does not respond, delete the `wp-content/plugins/irios-woocommerce` folder over FTP/SSH.

## Settings

**WooCommerce → Settings → Integration → IRIOS Media.** Nothing configured here is secret: it all reaches the browser.
The settings are in English, as in every IRIOS Media integration.

**General**:

| Setting | Description |
|---|---|
| Enable IRIOS Media | Main switch (on by default; nothing is loaded until the domain and the Store ID are set) |
| IRIOS Media domain | Domain of the store's network, without `https://` or path (e.g. `example.iriosmedia.online`) |
| Store ID | Numeric id of the store in IRIOS Media |
| Consent | How the plugin knows whether the visitor accepted marketing cookies: **WP Consent API** (with a compatible banner), **Asesor de Cookies RGPD** (reads its `cdp_cookie_consent` cookie) or **Always** (the store handles consent another way) |

**Sponsored Products** (the fields appear once enabled):

| Setting | Description |
|---|---|
| Enable | Shows ads in the search results and in the live search |
| Search results positions | One row per ad: **Position**, **Ad unit ID** and **Auth** (see below) |
| Sponsored label | Text of the sponsored cards ("Sponsored" by default, translated to the store language); do not leave it empty |
| Single-result search | Keeps the results page instead of redirecting to the product, so ads can be shown |

**Search results positions**: each row is a place in the results grid. Position 1 is top left and positions follow reading order (left
to right, row by row); with 4 columns, position 5 is the first of the second row. An ad unit can take several
positions (e.g. 1001 at 1 and 2, and 1002 at 6):

- All ad units go in a single auction and each one asks for as many ads as positions it has. Its winners take its
  positions from lowest to highest. If it wins fewer ads, its last positions keep the organic results.
- A product that already won in another ad unit is not repeated. The organic result for the same product is hidden.
- Positions count the visible results; if the page has fewer, the ad goes at the end. In a search with no results,
  the sponsored products are shown above the "no products found" notice.
- The live search shows at most one ad, at the top of the dropdown: the one of the ad unit of the lowest position.

**Display (Image and Video)** (the table appears once enabled): one row per placement with **Tag** (lowercase,
digits, `-` and `_`; unique), **Format** (image or video, as the ad unit in IRIOS Media), **Device** (all, desktop
from 768 px wide, or mobile: the same spot can have one row per device, with ad units of different sizes),
**Ad unit ID**, **Auth** and **Position**:

- **Manual only**: shown wherever `[irios_display tag="TAG"]` is placed (a page, the home page, a page-builder
  element, a block, a widget or a category description). `[irios_banner]`, its name in 1.0, still works.
- **Category: sidebar**: at the top of the shop sidebar.
- **Category: in grid**: inside the product grid, the full width of it, after the product set in
  **Grid: after product** (6 by default).
- **Category: bottom**: below the product grid and its sorting and pagination controls, centred.
- **Product: description**: before the product page tabs.
- **Product: related**: before the related products (or the upsells).

Unchecking the box turns all display ads off without touching the pages.

**Push Notifications** (the fields appear once enabled): the push **Ad unit ID** and its **Ad unit auth**, **When to ask for
permission** (after a purchase, on the order received page, or after a **Number of pages** of the visit) and
**Ask again after (days)** if the shopper did not answer (30 by default; 0: on every visit). The browser prompt is
only shown with consent, at most once per visit. The plugin serves the service worker at the root of the site,
`/irios-sw.js`: in IRIOS Media, the push ad unit must point to it. If consent is withdrawn, the subscription is
cancelled.

**Page type**: every ad request tells IRIOS Media the type of page it is shown on (`pageType`), which campaigns can
target. The plugin sets it from the WooCommerce page: home (`HOMEPAGE`), product search (`SEARCH`), live search
(`SEARCH_BAR`), category (`CATEGORY`, with its path), product (`PRODUCT_DETAIL`, with its deepest category), shop,
tag, brand and attribute listings (`MERCHANDISING`), cart and checkout (`CHECKOUT`) and order received
(`CONFIRMATION`). On other pages (WordPress pages, blog), a manual placement can set it with `page_type`, e.g.
`[irios_display tag="promo" page_type="PROMOTIONS"]` (`HOMEPAGE`, `MERCHANDISING`, `CHECKOUT`, `CONFIRMATION`,
`AI_ASSISTANT` or `PROMOTIONS`); without it, no ads are requested there. Nor on a category or product page without
a category, which IRIOS Media requires. In IRIOS Media, the store must have those page types associated.

## Languages

The plugin is written in English, as every IRIOS Media integration, and follows the store language. Everything is
translated into Spanish: the settings page, the admin notices and what shoppers see (the "About this ad" dialog and
the default "Sponsored" label). Any other language shows the English texts. The sponsored label can also be set in
the settings.

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
