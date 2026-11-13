// Banners: a single auction per page with the page context IRIOS requires (pageType and, on category and product
// pages, the category path; on the search page, the search).
import { afterEach, describe, expect, it } from "vitest";
import { settle, storefront } from "./harness.mjs";

let store;
afterEach(() => store && store.close());

/** Page with two banners (a third with the given data-page-type, from [irios_banner page_type="…"]). */
function bannersOn(page, pageTypeAttr) {
  const extra = pageTypeAttr ? `<div class="irios-banner" data-ad-unit="3" data-auth="c" data-page-type="${pageTypeAttr}"></div>` : "";
  store = storefront({
    page,
    config: { bannersEnabled: "1" },
    body: `<div class="irios-banner" data-ad-unit="1" data-auth="a"></div><div class="irios-banner" data-ad-unit="2" data-auth="b"></div>${extra}`,
  });
  return store;
}

describe("banners page context", () => {
  it("one auction for every banner of the page, with its page type", async () => {
    const { log } = bannersOn({ type: "page", pageType: "HOMEPAGE" });
    await settle();
    expect(log.auctions).toHaveLength(1);
    expect(log.auctions[0]).toMatchObject({
      pageType: "HOMEPAGE",
      adUnits: [
        { id: 1, auth: "a", type: "banner" },
        { id: 2, auth: "b", type: "banner" },
      ],
    });
    expect(log.auctions[0]).not.toHaveProperty("category");
    expect(log.auctions[0]).not.toHaveProperty("searchQuery");
  });

  it("category and product pages send their category path", async () => {
    const { log } = bannersOn({ type: "category", value: "Hockey > Sticks", pageType: "CATEGORY", category: ["Hockey", "Sticks"] });
    await settle();
    expect(log.auctions[0]).toMatchObject({ pageType: "CATEGORY", category: ["Hockey", "Sticks"] });
    store.close();

    const product = bannersOn({ type: "product", pageType: "PRODUCT_DETAIL", category: ["Hockey", "Sticks"] });
    await settle();
    expect(product.log.auctions[0]).toMatchObject({ pageType: "PRODUCT_DETAIL", category: ["Hockey", "Sticks"] });
  });

  it("on the search page the banners send the search", async () => {
    const { log } = bannersOn({ type: "search", value: "stick", pageType: "SEARCH" });
    await settle();
    expect(log.auctions[0]).toMatchObject({ pageType: "SEARCH", searchQuery: "stick" });
  });

  it("a page the plugin does not classify uses the banner's page_type, and without it requests no ads", async () => {
    const { log } = bannersOn({ type: "page" }, "PROMOTIONS");
    await settle();
    expect(log.auctions[0].pageType).toBe("PROMOTIONS");
    store.close();

    const other = bannersOn({ type: "page" });
    await settle();
    expect(other.log.auctions).toHaveLength(0);
  });

  it("a search page without a search or a product page without a category requests no ads", async () => {
    const search = bannersOn({ type: "search", value: " ", pageType: "SEARCH" });
    await settle();
    expect(search.log.auctions).toHaveLength(0);
    store.close();

    const product = bannersOn({ type: "product", pageType: "PRODUCT_DETAIL", category: [] });
    await settle();
    expect(product.log.auctions).toHaveLength(0);
  });

  it("the page type of the shop page wins over the banner's", async () => {
    const { log } = bannersOn({ type: "page", pageType: "HOMEPAGE" }, "PROMOTIONS");
    await settle();
    expect(log.auctions[0].pageType).toBe("HOMEPAGE");
  });
});
