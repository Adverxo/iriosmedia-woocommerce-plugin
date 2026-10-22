// Events and consent: what the Irios tracker receives (Irios bills per event).
import { afterEach, describe, expect, it } from "vitest";
import { settle, storefront } from "./harness.mjs";

let store;
const open = (options) => (store = storefront(options));
afterEach(() => store && store.close());

const ids = (events) => events.map((e) => e.id);

describe("without consent", () => {
  it("sends nothing, asks for no context and keeps no session", async () => {
    const { log, window } = open({ consent: false, page: { type: "search", value: "stick" } });
    window.IriosWoo.track({ id: "page-view", value: "/custom" });
    await settle();

    expect(log.events).toEqual([]);
    expect(log.requests).toEqual([]);
    expect(window.sessionStorage.getItem("irios_session_id")).toBeNull();
    expect(window.IriosWoo.context().ready).toBe(false);
  });

  it("without the banner cookie there is no consent either", async () => {
    const { log } = open({ consent: null });
    await settle();
    expect(log.events).toEqual([]);
  });

  it("WP Consent API without a banner gives everything as allowed: here it is no consent", async () => {
    const { log } = open({
      consent: null,
      config: { consentMode: "wp_consent_api" },
      before: (w) => (w.wp_has_consent = () => true),
    });
    await settle();
    expect(log.events).toEqual([]);
  });
});

describe("with consent", () => {
  it("sends the page events with store, session, customer uids and consent", async () => {
    const uids = [{ namespace: "CRM", uid: "7" }];
    const { log, window } = open({ page: { type: "search", value: "stick" }, context: { uids } });
    await settle();

    expect(ids(log.events)).toEqual(["page-view", "search"]);
    const sessionId = window.sessionStorage.getItem("irios_session_id");
    expect(sessionId).toMatch(/[0-9a-f-]{36}/);
    for (const event of log.events) {
      expect(event).toMatchObject({ storeId: "1234", sessionId, uids, consent: { gdpr: true } });
    }
    expect(log.events[1].value).toBe("stick");
  });

  it("each page type sends its event", async () => {
    const product = { id: "10", price: 199.99, quantity: 1 };
    const cases = [
      [{ type: "category", value: "Sticks > SR Sticks" }, { id: "category-view", value: "Sticks > SR Sticks" }],
      [{ type: "checkout", value: "checkout" }, { id: "checkout-step", value: "checkout" }],
      [{ type: "product", product, variable: false }, { id: "product-view", products: [product] }],
    ];
    for (const [page, expected] of cases) {
      const { log, close } = storefront({ page });
      await settle();
      close();
      expect(log.events.map((e) => e.id)).toEqual(["page-view", expected.id]);
      expect(log.events[1]).toMatchObject(expected);
    }
  });

  it("delivers the cart events the server left in the session", async () => {
    const event = { id: "add-to-cart", products: [{ id: "10", price: 199.99, quantity: 2 }] };
    const { log } = open({ context: { events: [event] } });
    await settle();
    expect(log.events.map((e) => e.id)).toEqual(["page-view", "add-to-cart"]);
    expect(log.events[1].products).toEqual(event.products);
  });

  it("collects the cart events again after an AJAX cart change", async () => {
    const event = { id: "remove-from-cart", products: [{ id: "10", price: 199.99, quantity: 1 }] };
    const { log, window, document } = open({ context: { events: [event] } });
    await settle();
    document.body.dispatchEvent(new window.CustomEvent("wc-blocks_removed_from_cart"));
    await settle();
    // Identical content within 2 s is discarded; what matters here is that the context was requested again
    expect(log.requests.filter((r) => r.startsWith("/wp-json/irios/v1/context"))).toHaveLength(2);
  });

  it("a search typed in the live search and finished with a click is sent on the next page", async () => {
    const { log } = open({ before: (w) => w.sessionStorage.setItem("irios_pending_search", "casco") });
    await settle();
    expect(log.events.map((e) => [e.id, e.value])).toEqual([["page-view", "/"], ["search", "casco"]]);
  });

  it("drops the same event with the same content within 2 s", async () => {
    const { log, window } = open();
    await settle();
    const event = { id: "add-to-cart", products: [{ id: "10", price: 5, quantity: 1 }] };
    window.IriosWoo.track(event);
    window.IriosWoo.track(event);
    window.IriosWoo.track({ ...event, products: [{ id: "11", price: 5, quantity: 1 }] });
    await settle();
    expect(log.events.filter((e) => e.id === "add-to-cart").map((e) => e.products[0].id)).toEqual(["10", "11"]);
  });

  it("events tracked before the tracker is ready are queued, not lost", async () => {
    const { log, window } = open();
    window.IriosWoo.track({ id: "custom", value: "early" });
    expect(log.events).toEqual([]);
    await settle();
    expect(log.events.map((e) => e.id)).toContain("custom");
  });

  it("the variation chosen on a variable product is a product-view, not repeated", async () => {
    const product = { id: "20", price: 54.97, quantity: 1 };
    const { log, window } = open({
      page: { type: "product", product, variable: true },
      before: (w) => {
        // Minimal jQuery: only the event registration the script uses
        const handlers = {};
        w.jQuery = () => ({
          on: (names, selectorOrFn, fn) => names.split(" ").forEach((n) => (handlers[n] = fn || selectorOrFn)),
        });
        w.__found = (variation) => handlers.found_variation({}, variation);
      },
    });
    await settle();
    window.__found({ variation_id: 21, display_price: 59.9 });
    window.__found({ variation_id: 21, display_price: 59.9 });
    window.__found({ variation_id: 22, display_price: 54.97 });
    await settle();
    expect(log.events.filter((e) => e.id === "product-view").map((e) => e.products[0])).toEqual([
      product,
      { id: "21", price: 59.9, quantity: 1 },
      { id: "22", price: 54.97, quantity: 1 },
    ]);
  });

  it("withdrawing consent stops sending and removes the Irios cookies", async () => {
    const { log, window, document } = open();
    await settle();
    document.cookie = "irs1P=fc:abc; path=/";
    document.cookie = `cdp_cookie_consent=${encodeURIComponent('{"marketing":false}')}; path=/`;
    await settle(1_100); // the script checks the banner cookie every second
    const before = log.events.length;
    window.IriosWoo.track({ id: "custom", value: "after" });
    await settle();

    expect(log.events).toHaveLength(before);
    expect(document.cookie).not.toContain("irs1P");
    expect(window.sessionStorage.getItem("irios_session_id")).toBeNull();
  });

  it("accepting consent later starts sending", async () => {
    const { log, document } = open({ consent: false });
    await settle();
    document.cookie = `cdp_cookie_consent=${encodeURIComponent('{"marketing":true}')}; path=/`;
    await settle(1_100);
    expect(log.events.map((e) => e.id)).toEqual(["page-view"]);
  });

  it("WP Consent API with a banner follows the visitor decision", async () => {
    const { log } = open({
      consent: null,
      config: { consentMode: "wp_consent_api" },
      before: (w) => {
        w.wp_consent_type = "optin";
        w.wp_has_consent = (category) => category === "marketing";
      },
    });
    await settle();
    expect(log.events.map((e) => e.id)).toEqual(["page-view"]);
  });
});
