# Changelog

Changes in each version of IRIOS Media for WooCommerce. Download any version from the [releases](../../releases).

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
