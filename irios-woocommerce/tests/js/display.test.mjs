// Display (image and video): each placement is requested with its IRIOS ad unit type, only on its device, and the
// automatic positions of the common catalog are found in the theme.
import { afterEach, describe, expect, it } from "vitest";
import { settle, storefront } from "./harness.mjs";

let store;
afterEach(() => store && store.close());

const width = (px) => (window) => Object.defineProperty(window, "innerWidth", { value: px, configurable: true });
const products = (n) => Array.from({ length: n }, (_, i) => `<li class="product post-${i + 1}">P${i + 1}</li>`).join("");

describe("Display", () => {
  it("each placement goes with its IRIOS ad unit type: banner for images, video", async () => {
    store = storefront({
      page: { type: "page", pageType: "HOMEPAGE" },
      config: { bannersEnabled: "1" },
      body:
        '<div class="irios-banner" data-ad-unit="1" data-auth="a" data-type="banner" data-device="all"></div>' +
        '<div class="irios-banner" data-ad-unit="2" data-auth="b" data-type="video" data-device="all"></div>',
    });
    await settle();
    expect(store.log.auctions[0].adUnits).toEqual([
      { id: 1, auth: "a", type: "banner" },
      { id: 2, auth: "b", type: "video" },
    ]);
  });

  it("only the placements of the device the page is seen on (desktop from 768 px)", async () => {
    const body =
      '<div class="irios-banner" data-ad-unit="1" data-auth="a" data-device="desktop"></div>' +
      '<div class="irios-banner" data-ad-unit="2" data-auth="b" data-device="mobile"></div>' +
      '<div class="irios-banner" data-ad-unit="3" data-auth="c" data-device="all"></div>';
    store = storefront({ page: { type: "page", pageType: "HOMEPAGE" }, config: { bannersEnabled: "1" }, body, before: width(1280) });
    await settle();
    expect(store.log.auctions[0].adUnits.map((u) => u.id)).toEqual([1, 3]);
    store.close();

    store = storefront({ page: { type: "page", pageType: "HOMEPAGE" }, config: { bannersEnabled: "1" }, body, before: width(375) });
    await settle();
    expect(store.log.auctions[0].adUnits.map((u) => u.id)).toEqual([2, 3]);
  });

  it("category: inside the grid after product N (full width), in the sidebar and after the products", async () => {
    store = storefront({
      page: { type: "category", pageType: "CATEGORY", category: ["Sticks"] },
      config: { bannersEnabled: "1", gridAfter: 2 },
      body:
        `<aside id="secondary"><div class="widget">W</div></aside><ul class="products">${products(4)}</ul>` +
        '<div class="irios-banner" data-ad-unit="1" data-auth="a" data-irios-position="category_in_grid" hidden></div>' +
        '<div class="irios-banner" data-ad-unit="2" data-auth="b" data-irios-position="category_sidebar" hidden></div>' +
        '<div class="irios-banner" data-ad-unit="3" data-auth="c" data-irios-position="category_after_products" hidden></div>',
    });
    await settle();
    const doc = store.window.document;
    const grid = doc.querySelector("ul.products");
    const item = grid.children[2];
    expect(item.className).toBe("irios-banner-grid-item");
    expect(item.tagName).toBe("LI");
    expect(item.querySelector('.irios-banner[data-ad-unit="1"]')).not.toBeNull();
    expect(doc.querySelector("#secondary").firstElementChild.getAttribute("data-ad-unit")).toBe("2");
    expect(grid.nextElementSibling.getAttribute("data-ad-unit")).toBe("3");
    expect(store.log.auctions[0].adUnits.map((u) => u.id)).toEqual([1, 2, 3]);
  });

  it("inside the grid needs more products than N; otherwise the placement is not shown", async () => {
    store = storefront({
      page: { type: "category", pageType: "CATEGORY", category: ["Sticks"] },
      config: { bannersEnabled: "1", gridAfter: 6 },
      body: `<ul class="products">${products(3)}</ul><div class="irios-banner" data-ad-unit="1" data-auth="a" data-irios-position="category_in_grid" hidden></div>`,
    });
    await settle();
    expect(store.window.document.querySelector(".irios-banner")).toBeNull();
    expect(store.log.auctions).toHaveLength(0);
  });

  it("product page: before the related products", async () => {
    store = storefront({
      page: { type: "product", pageType: "PRODUCT_DETAIL", category: ["Sticks"] },
      config: { bannersEnabled: "1" },
      body:
        '<div class="woocommerce-tabs">Tabs</div><section class="related products"><ul class="products"></ul></section>' +
        '<div class="irios-banner" data-ad-unit="4" data-auth="d" data-irios-position="product_before_related" hidden></div>',
    });
    await settle();
    const related = store.window.document.querySelector("section.related");
    expect(related.previousElementSibling.getAttribute("data-ad-unit")).toBe("4");
  });
});

describe("push notifications", () => {
  const pushConfig = (trigger, extra = {}) => ({
    push: { id: 9, auth: "p", trigger, afterPages: 2, repeatDays: 30, serviceWorker: "https://shop.test/irios-sw.js", scope: "/", ...extra },
  });
  const browser = (registered) => (window) => {
    Object.defineProperty(window.navigator, "serviceWorker", {
      value: { register: async (url, options) => registered.push([url, options]), getRegistration: async () => null },
      configurable: true,
    });
    window.PushManager = function () {};
    window.Notification = { permission: "default" };
  };
  const pushRender = (window) =>
    [...window.document.querySelectorAll("script")].some((s) => /__iriosWooPush\.render\(9\)/.test(s.text));

  it("after a purchase: on the order received page, registers the service worker and asks through the IRIOS loader", async () => {
    const registered = [];
    store = storefront({ page: { type: "page", pageType: "CONFIRMATION" }, config: pushConfig("after_purchase"), before: browser(registered) });
    await settle(60);
    expect(registered).toEqual([["https://shop.test/irios-sw.js", { scope: "/" }]]);
    const auction = store.log.auctions.find((a) => a.adUnits.some((u) => u.type === "push"));
    expect(auction.adUnits).toEqual([{ id: 9, auth: "p", type: "push" }]);
    expect(auction.consent).toEqual({ gdpr: true });
    expect(pushRender(store.window)).toBe(true);
  });

  it("after a purchase: not on other pages; without consent, never", async () => {
    const registered = [];
    store = storefront({ page: { type: "page", pageType: "HOMEPAGE" }, config: pushConfig("after_purchase"), before: browser(registered) });
    await settle(60);
    expect(registered).toEqual([]);
    store.close();

    store = storefront({ consent: false, page: { type: "page", pageType: "CONFIRMATION" }, config: pushConfig("after_purchase"), before: browser(registered) });
    await settle(60);
    expect(registered).toEqual([]);
  });

  it("after N pages: from the Nth page of the visit; if not answered, not again until the days have passed", async () => {
    const registered = [];
    const visit = (window) => {
      browser(registered)(window);
      window.sessionStorage.setItem("irios_push_pages", "1"); // one page already seen
    };
    store = storefront({ page: { type: "page", pageType: "HOMEPAGE" }, config: pushConfig("after_pages"), before: visit });
    await settle(60);
    expect(registered).toHaveLength(1);
    store.close();

    const asked = (window) => {
      browser(registered)(window);
      window.sessionStorage.setItem("irios_push_pages", "5");
      window.localStorage.setItem("irios_push_asked", String(Date.now() - 2 * 24 * 3600 * 1000)); // 2 days ago
    };
    store = storefront({ page: { type: "page", pageType: "HOMEPAGE" }, config: pushConfig("after_pages"), before: asked });
    await settle(60);
    expect(registered).toHaveLength(1); // 30 days not passed yet
  });
});
