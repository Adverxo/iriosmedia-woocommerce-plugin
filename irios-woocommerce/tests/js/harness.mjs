// Simulated store page to test assets/js/irios-woocommerce.js exactly as published: jsdom with the configuration
// printed by PHP (iriosWooConfig, iriosWooPage), cookies, and Irios and WordPress doubles that record what they
// receive:
//   - IriosEvent (tracker) → events;  IriosLoader (auction) → auctions, with the winners given to it;
//   - IriosTracker (ad load, visibility and click) → tracked;
//   - fetch: /irios/v1/context and /irios/v1/cards with the test's responses → requests.
import { mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { JSDOM } from "jsdom";
import { createInstrumenter } from "istanbul-lib-instrument";

const SCRIPT_URL = new URL("../../assets/js/irios-woocommerce.js", import.meta.url);
const SCRIPT_PATH = fileURLToPath(SCRIPT_URL);
const SOURCE = readFileSync(SCRIPT_URL, "utf8");

/**
 * The storefront JS is run with window.eval, exactly as WordPress serves it, so no coverage tool can see inside it.
 * With IRIOS_COVERAGE=1 it is instrumented first and what each page covered is added up (tests/js/coverage.mjs), so
 * what is tested can be measured without changing how it is tested.
 */
const COVERAGE = process.env.IRIOS_COVERAGE === "1";
const instrumenter = COVERAGE ? createInstrumenter({ esModules: false, coverageGlobalScopeFunc: false }) : null;
const SCRIPT = COVERAGE ? instrumenter.instrumentSync(SOURCE, SCRIPT_PATH) : SOURCE;

// Tests run in parallel workers, so each page leaves what it covered in its own file and bin/coverage-js.sh adds
// them all up.
const COVERAGE_DIR = fileURLToPath(new URL("../../build/js-coverage", import.meta.url));
let pagesCovered = 0;

function keepWhatThisPageCovered(window) {
  if (!COVERAGE || !window.__coverage__) {
    return;
  }
  mkdirSync(COVERAGE_DIR, { recursive: true });
  writeFileSync(`${COVERAGE_DIR}/${process.pid}-${pagesCovered++}.json`, JSON.stringify([window.__coverage__]));
}
const ORIGIN = "https://shop.test";

export const CONFIG = {
  version: "1.0.0",
  jsDomain: "tracker.irios.test",
  storeId: "1234",
  consentMode: "asesor_cookies",
  consentCategory: "marketing",
  asesorCookie: "cdp_cookie_consent",
  contextUrl: `${ORIGIN}/wp-json/irios/v1/context`,
  restNonce: "nonce",
  cardsUrl: `${ORIGIN}/wp-json/irios/v1/cards`,
  storeApiUrl: `${ORIGIN}/wp-json/wc/store/v1/`,
  sponsoredLabel: "Promoted",
  currency: "EUR",
  productBase: "/product/",
  liveSearchAds: null,
  bannersEnabled: false,
  adInfo: { button: "Information about this ad", title: "About this ad", reasons: {}, ok: "Got it", name: "Advertiser name", payer: "Who paid for this ad", unknown: "Not available" },
  theme: "Storefront (storefront)",
};

/** Auction response for a product: like the one from Irios (getAds). */
export function winner(adUnitId, position, productId) {
  const t = (kind) => [`https://tracker.irios.test/${kind}?ad=${productId}`];
  return {
    adUnitId,
    position,
    requestId: `r-${productId}`,
    targetingTypes: ["SEARCH"],
    native: { product: { id: String(productId) }, trackingUrls: { loadTrackingUrl: t("load"), viewTrackingUrl: t("view"), clickTrackingUrl: t("click") } },
  };
}

/** Theme card (classic WooCommerce grid), like the one on the page or the one returned by /cards. */
export function card(id) {
  return (
    `<li class="product type-product post-${id} instock">` +
    `<a href="${ORIGIN}/product/p${id}/" class="woocommerce-LoopProduct-link"><h2 class="woocommerce-loop-product__title">P${id}</h2></a>` +
    `<a href="?add-to-cart=${id}" class="button add_to_cart_button ajax_add_to_cart" data-product_id="${id}">Add</a></li>`
  );
}

export const grid = (...ids) => `<ul class="products columns-4">${ids.map(card).join("")}</ul>`;

/**
 * Mounts the page and runs the script. Options: consent (banner cookie), page (iriosWooPage), body (HTML),
 * config (CONFIG overrides), context ({uids, events}), winners (from the auction), cards (ids returned by /cards or
 * null for an error), before(window) to set up more doubles.
 */
export function storefront({ consent = true, page = { type: "page" }, body = "", config = {}, context = {}, winners = [], cards, before } = {}) {
  const dom = new JSDOM(`<!doctype html><html><head></head><body>${body}</body></html>`, {
    url: `${ORIGIN}/`,
    runScripts: "outside-only",
    pretendToBeVisual: true,
  });
  const { window } = dom;
  const log = { events: [], auctions: [], tracked: [], requests: [] };

  if (consent !== null) {
    window.document.cookie = `cdp_cookie_consent=${encodeURIComponent(JSON.stringify({ necessary: true, marketing: consent }))}; path=/`;
  }
  window.iriosWooConfig = { ...CONFIG, ...config };
  window.iriosWooPage = page;

  window.IriosEvent = function (payload) {
    log.events.push(payload[0]);
  };
  window.IriosTracker = function (units) {
    log.tracked.push(...units);
  };
  window.IriosLoader = function (cfg) {
    log.auctions.push(cfg);
    this.adResponsesPromise = Promise.resolve(winners);
  };
  window.IriosLoader.prototype.getAds = async function (adUnitId) {
    return (await this.adResponsesPromise).filter((a) => a.adUnitId === adUnitId);
  };
  window.fetch = async (url, options = {}) => {
    const u = new URL(url, `${ORIGIN}/`);
    log.requests.push(u.pathname + u.search);
    const json = (status, data) => ({ ok: status < 400, status, headers: new Map(), json: async () => data });
    if (u.pathname === "/wp-json/irios/v1/context") {
      return json(200, { uids: context.uids || [], events: context.events || [] });
    }
    if (u.pathname === "/wp-json/irios/v1/cards") {
      if (cards === null) return json(500, {});
      const wanted = (u.searchParams.get("ids") || "").split(",");
      const ids = (cards || wanted).filter((id) => wanted.includes(String(id)));
      return json(200, { cards: ids.map((id) => ({ id: String(id), productId: Number(id), html: card(id) })) });
    }
    return json(404, {});
  };
  if (before) before(window);

  window.eval(SCRIPT);
  return {
    window,
    document: window.document,
    log,
    close: () => {
      keepWhatThisPageCovered(window);
      window.close();
    },
  };
}

/** Lets the script's promises and short timers run. */
export const settle = (ms = 30) => new Promise((resolve) => setTimeout(resolve, ms));
