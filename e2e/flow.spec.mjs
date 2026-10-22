// Full visitor flow against the Docker store and the Irios simulator (dev/irios-stub.php): home page, category,
// search with Sponsored Products, product page, add to cart (AJAX and form), cart, checkout and purchase. Checks what
// Irios receives, with and without consent ("Asesor de Cookies RGPD" banner).
//
// The simulator logs every event ([irios-stub] {…}), every auction ([irios-stub] IriosLoader {…}) and every
// tracking URL ([irios-stub] tracking …). The order is placed through the Store API (like the blocks checkout);
// e2e/run.sh then checks with wp-cli the identifier saved on each order (results/orders.json).
import { test, expect } from "@playwright/test";
import { mkdirSync, writeFileSync } from "node:fs";

const { SEARCH_URL, CATEGORY_URL, PRODUCT_URL, CART_URL, CHECKOUT_URL, SIMPLE_ID, CATEGORY_PATH } = process.env;
const BROWSER_UUID = "0f8fad5b-d9cb-469f-a165-70867728950e";
const orders = {};

/** Visitor with the banner cookie and the Irios one (irs1P, created by its script in the store). */
async function visitor(browser, marketing) {
  const context = await browser.newContext();
  await context.addCookies([
    { name: "cdp_cookie_consent", value: encodeURIComponent(JSON.stringify({ necessary: true, marketing })), url: "http://localhost" },
    { name: "irs1P", value: `fc:${BROWSER_UUID}`, url: "http://localhost" },
  ]);
  const page = await context.newPage();
  const log = { events: [], auctions: [], tracking: [], errors: [] };
  page.on("console", (m) => {
    const text = m.text();
    if (text.startsWith("[irios-stub] IriosLoader ")) log.auctions.push(JSON.parse(text.slice("[irios-stub] IriosLoader ".length)));
    else if (text.startsWith("[irios-stub] tracking ")) log.tracking.push(text.slice("[irios-stub] tracking ".length));
    else if (text.startsWith("[irios-stub] {")) log.events.push({ ...JSON.parse(text.slice("[irios-stub] ".length)), page: page.url() });
  });
  page.on("pageerror", (e) => log.errors.push(e.message));
  return { context, page, log };
}

/** Opens the page and waits until the plugin has started (with consent) or has nothing more to do. */
async function open(page, url, consent) {
  await page.goto(url);
  await settle(page, consent);
}

async function settle(page, consent) {
  if (consent) {
    await page.waitForFunction(() => window.IriosWoo && window.IriosWoo.context().ready, null, { timeout: 10_000 });
  }
  await page.waitForTimeout(500); // queued events and ads
}

const ids = (events) => events.map((e) => e.id);
const on = (log, url) => log.events.filter((e) => new URL(e.page).href === new URL(url).href);

/** Purchase through the Store API with the browser session: fires the same hooks as the blocks checkout. */
async function placeOrder(page) {
  const result = await page.evaluate(async () => {
    const cart = await fetch("/wp-json/wc/store/v1/cart", { credentials: "same-origin" });
    const nonce = cart.headers.get("Nonce");
    const address = {
      first_name: "Test", last_name: "Buyer", address_1: "Calle Falsa 1", city: "Madrid", state: "M",
      postcode: "28001", country: "ES", email: "buyer@example.com", phone: "600000000",
    };
    const r = await fetch("/wp-json/wc/store/v1/checkout", {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json", Nonce: nonce },
      body: JSON.stringify({ billing_address: address, shipping_address: address, payment_method: "cod" }),
    });
    return { status: r.status, body: await r.json() };
  });
  expect(result.status, JSON.stringify(result.body)).toBe(200);
  return result.body.order_id;
}

/** The whole purchase journey; returns the order. */
async function shop(page, consent) {
  await open(page, "http://localhost/", consent);
  await open(page, CATEGORY_URL, consent);
  await open(page, SEARCH_URL, consent);
  await expect(page.locator(".irios-sponsored").first()).toBeVisible();
  // Add to cart via AJAX from the ad (the simulator advertises that product and its organic result is hidden)
  await page.locator(".irios-sponsored").first().hover();
  const added = page.waitForResponse((r) => /add_to_cart/.test(r.url()));
  await page.locator(`.irios-sponsored .ajax_add_to_cart[data-product_id="${SIMPLE_ID}"]:visible`).first().click({ timeout: 10_000 });
  await added;
  await page.waitForTimeout(1_000); // the plugin picks up the server event via the context endpoint
  await open(page, PRODUCT_URL, consent);
  // Another unit from the product page (form: reloads the page); the line is already in the cart
  await page.locator("form.cart button[name='add-to-cart']").click({ timeout: 10_000 });
  await page.waitForLoadState("load");
  await settle(page, consent);
  await open(page, CART_URL, consent);
  await open(page, CHECKOUT_URL, consent);
  return placeOrder(page);
}

test.afterAll(() => {
  mkdirSync("results", { recursive: true });
  writeFileSync("results/orders.json", JSON.stringify({ uuid: BROWSER_UUID, ...orders }, null, 2));
});

test("with consent: every store event reaches Irios once, with consent and session", async ({ browser }) => {
  test.setTimeout(120_000);
  const { context, page, log } = await visitor(browser, true);
  orders.consent = await shop(page, true);
  await context.close();

  expect(log.errors).toEqual([]);
  const product = { id: String(SIMPLE_ID), price: 199.99 };

  // Per page: its page-view and its event, without duplicates
  expect(ids(on(log, "http://localhost/"))).toEqual(["page-view"]);
  expect(on(log, CATEGORY_URL).map((e) => [e.id, e.value])).toEqual([
    ["page-view", new URL(CATEGORY_URL).pathname],
    ["category-view", CATEGORY_PATH],
  ]);
  expect(on(log, SEARCH_URL).filter((e) => e.id !== "add-to-cart").map((e) => [e.id, e.value])).toEqual([
    ["page-view", "/"],
    ["search", "stick"],
  ]);
  expect(on(log, CHECKOUT_URL).map((e) => [e.id, e.value])).toEqual([
    ["page-view", new URL(CHECKOUT_URL).pathname],
    ["checkout-step", "checkout"],
  ]);
  expect(on(log, CART_URL).map((e) => e.id)).toEqual(["page-view", "cart-view"]);
  expect(on(log, CART_URL).find((e) => e.id === "cart-view").products).toEqual([{ ...product, quantity: 2 }]);
  expect(log.events.filter((e) => e.id === "product-view").map((e) => e.products)).toEqual([[{ ...product, quantity: 1 }], [{ ...product, quantity: 1 }]]);

  // Cart: one add-to-cart per action (AJAX on the search page and form on the product page), with its quantity
  expect(log.events.filter((e) => e.id === "add-to-cart").map((e) => e.products)).toEqual([
    [{ ...product, quantity: 1 }],
    [{ ...product, quantity: 1 }],
  ]);
  expect(on(log, SEARCH_URL).some((e) => e.id === "add-to-cart"), "AJAX add-to-cart sent without reloading").toBe(true);

  // All with the store, the same session and consent; the purchase never leaves the browser
  expect(new Set(log.events.map((e) => e.storeId))).toEqual(new Set(["1234"]));
  expect(new Set(log.events.map((e) => JSON.stringify(e.consent)))).toEqual(new Set(['{"gdpr":true}']));
  expect(new Set(log.events.map((e) => e.sessionId)).size).toBe(1);
  expect(ids(log.events)).not.toContain("purchase");

  // Personalised auction and ad tracking
  expect(log.auctions.length).toBeGreaterThan(0);
  for (const auction of log.auctions) {
    expect(auction.consent).toEqual({ gdpr: true });
    expect(auction.sessionId).toBeTruthy();
  }
  expect(log.tracking.some((url) => url.includes("/load?"))).toBe(true);
});

test("without consent: nothing is sent, ads are contextual", async ({ browser }) => {
  test.setTimeout(120_000);
  const { context, page, log } = await visitor(browser, false);
  orders.noConsent = await shop(page, false);
  const session = await page.evaluate(() => window.IriosWoo && window.IriosWoo.context());
  await context.close();

  expect(log.errors).toEqual([]);
  expect(log.events).toEqual([]);
  expect(log.auctions.length).toBeGreaterThan(0);
  for (const auction of log.auctions) {
    expect(auction.consent).toEqual({ gdpr: false });
    expect(auction.uids).toBeUndefined();
    expect(auction.sessionId).toBeUndefined();
  }
  expect(session && session.ready).toBeFalsy();
});
