/**
 * IRIOS Media for WooCommerce — the storefront JS, built from the modules in this folder into
 * assets/js/irios-woocommerce.js, which is what WordPress serves (npm run build:js).
 *
 * Nothing is sent or stored without consent (WP Consent API, the "Asesor de Cookies" banner or "always" mode). With
 * consent: the browser identifier (the Irios irs1P cookie), a per-tab session, the uids of the logged-in customer
 * from /wp-json/irios/v1/context (never from the page, which may be cached) and the Irios tracker. Without it, the
 * ads are still shown, contextual.
 *
 * What each module does:
 *   config       the settings PHP printed, the page, and debug mode
 *   consent      whether there is consent, and noticing when it is given or withdrawn
 *   identity     the uids of the logged-in customer and the session
 *   tracker      loading the Irios script, the event queue, start() and stop()
 *   events-api   window.IriosWoo.track for custom events
 *   events       the events of the page and of the cart
 *   search-ads   Sponsored Products in the results
 *   ad-info      the "About this ad" dialog (DSA, art. 26)
 *   display      image and video placements
 *   push         notification subscription
 *   live-search  Sponsored Products in the theme's dropdown
 *   startup      what runs on load and when consent changes
 */
import './startup.mjs';
