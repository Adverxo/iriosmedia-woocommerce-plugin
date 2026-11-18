# Changelog

Changes in each version of IRIOS Media for WooCommerce. Download any version from the [releases](../../releases).

## 1.1.3 - 2026-11-18

### Added

- Display: new automatic position **Category: top**, right above the product grid (after the result count and the
  sorting), the opposite of "Category: bottom". It is where most retailers place their banners in a listing.

### Changed

- The repository moved to the **IRIOSMedia** organization on GitHub: the update check and the `Update URI` header point
  to `IRIOSMedia/iriosmedia-woocommerce-plugin`. Earlier versions keep finding updates through GitHub's redirect from
  the old address.
- Display: the "Category: sidebar" placement goes at the end of the shop sidebar, below its widgets, instead of at
  the top: the filters stay where the shopper expects them.
- Display: "Category: sidebar" is desktop only. A sidebar placement for all devices or for mobile is not saved (on
  phones the sidebar is a filter panel or far below the products, so the ad would be requested and not seen); one
  saved for all devices before this version keeps working, on desktop only.

## 1.1.2 - 2026-11-17

### Fixed

- 1.1.1 was published without the plugin's own PHP classes, and installing it stopped the whole site with a fatal
  error (`Class "Irios\WooCommerce\Plugin" not found`), the admin included. If you installed 1.1.1, delete the
  `irios-woocommerce` folder from `wp-content/plugins` (by FTP or your host's file manager) and install this version.
  The packaging now refuses to build a zip without them.

## 1.1.1 - 2026-11-13

### Added

- `irios_woocommerce_selectors`: a theme that names its results grid, its sidebar or its live search dropdown
  differently can now be adapted from the site itself, without waiting for a version of the plugin.

### Fixed

- The live search no longer watches the whole page: it starts watching only after the shopper types in a search box,
  and only that box's own corner of the page. Until now, from the first keystroke on, every change anywhere made the
  plugin walk every link of the document, for the rest of the page's life.
- The settings are read and checked once per request instead of on every one of the dozen places that ask for them
  while a page is built.
- Events already too old to be duplicates are dropped, so the list does not grow for the whole visit.
- The visitor's own data is served only to pages of the store itself: `/context` and `/cards` now refuse a request
  made from another site. WordPress answers REST requests with credentials, so until now a third-party page could read
  the visitor's cart or make it lose the cart events waiting to be sent.
- The `/cards` diagnostics (`?irios_debug=1`) are only for whoever can configure the store: they used to give file
  names and line numbers to anyone.
- `/customers` no longer loads every order of the store into memory on each call: it asks the database for the page it
  needs. On a store with many orders the call could run out of memory or time. It also counts only customers with a
  paid order, as `/orders` already did.

## 1.1.0 - 2026-11-13

### Added

- Every ad request tells IRIOS Media the type of page it is shown on (`pageType`), required by IRIOS Media from now
  on: home, search, live search, category, product, shop listings, cart and checkout, and order received. Category
  and product pages send their category path; search pages, the search. Manual placements on other pages can set it
  with `[irios_display tag="…" page_type="PROMOTIONS"]`; without it, no ads are requested on those pages.
- "About this ad": reason for ads targeted by page type ("The section of the store you are in").
- Display: video ads. Each placement has a format (image or video, as its ad unit in IRIOS Media), and the IRIOS
  loader renders an image or a video player.
- Display: each placement has a device (all, desktop from 768 px wide, or mobile), so the same spot can show ad units
  of different sizes on desktop and mobile.
- Display: new automatic positions: category sidebar, inside the category grid (after the product set in "Grid: after
  product", 6 by default) and before the related products on the product page.
- API for the IRIOS Media sync service (`/wp-json/irios/v1/orders`, `/wp-json/irios/v1/customers`): orders and
  customers with the shopper's identifiers already hashed in the store, so emails, phones, addresses and IP
  addresses never leave WooCommerce. Authenticated with a read-only WooCommerce REST API key.
- Orders keep the consent answer (`_irios_consent`), so purchases are sent only with consent.
- Push notifications: the shoppers who consent can subscribe to brand notifications, asked after a purchase (default)
  or after a number of pages, and not again for a number of days if they did not answer. The plugin serves the service
  worker at `/irios-sw.js`.
- Updating from the earlier "Irios for WooCommerce" (folder `irios-for-woocommerce`): this version keeps its
  settings, stays off with a notice while the old one is active (deactivate it first), and deleting the old one from
  Plugins no longer wipes the shared settings.

### Changed

- "Banners" is now "Display (Image and Video)", with the `[irios_display]` shortcode. `[irios_banner]` and the saved
  banners keep working (as images for all devices).
- The settings follow the names shared by every IRIOS Media integration, written in English: General (with a new
  "Enable IRIOS Media" switch, on for existing installs), Sponsored Products, Display (Image and Video) and Push
  Notifications.
- Spanish translation complete: the settings, the admin notices and what shoppers see are all translated on stores
  in Spanish.
- The plugin is now called "IRIOS Media for WooCommerce".
- Live search: at most one Sponsored Product, at the top of the dropdown (the ad unit of the lowest search position),
  as in every IRIOS Media integration.
- Banners on product pages send the product's category, and search pages no longer send a category (IRIOS Media
  ignores it there).
- "About this ad": clearer reason for ads that use no targeting criteria ("No information about you was used. It is
  shown to everyone who visits this part of the store.").

### Fixed

- Sponsored Products on themes whose search results are not product cards (blog-style lists): the ad's image now takes
  the size the theme shows the other results' images at, instead of a taller box that broke the row.
- "About this ad": the advertiser name now shows the brand the ad is shown on behalf of, and "Who paid for this ad"
  shows the advertiser. Before, both lines showed the advertiser, even when IRIOS Media returns a different brand.

## 1.0.0 - 2026-10-22

First public version.

- Store events and identity: page, category, search, product (including the selected variation), cart and checkout
  events, with the visitor's consent.
- Sponsored Products in the search results and in the theme's live search, with configurable positions and several
  ad units in a single auction. In a search with no results, sponsored products are shown above the "no products
  found" notice.
- Banners through the `[irios_banner]` shortcode or at automatic positions on category and product pages.
- Contextual ads without consent: no identifiers, no cookies and no events.
- "About this ad" information next to every ad label (EU Digital Services Act).
- Notice in the WordPress dashboard when a new version is available.
- Warning in the settings when the active theme is not among the supported themes.
- English and Spanish.
