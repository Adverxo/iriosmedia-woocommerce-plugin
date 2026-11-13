// Sponsored Products on the results page: positions, repeated organic results, label, tracking and auction with
// or without consent.
import { afterEach, describe, expect, it } from "vitest";
import { grid, settle, storefront, winner } from "./harness.mjs";

let store;
afterEach(() => store && store.close());

const UNIT = 9001;
/** Search page with slots at those positions and the given organic results. */
function search({ positions = [1], organic = [1, 2, 3], units, ...options } = {}) {
  store = storefront({
    body: grid(...organic),
    page: {
      type: "search",
      value: "stick",
      ads: {
        units: units || [{ adUnit: UNIT, auth: "abc", positions }],
        searchQuery: "stick",
        language: "es-ES",
        results: {},
        empty: false,
      },
    },
    ...options,
  });
  return store;
}

/** Visible grid: id of each card, with "*" if sponsored. */
const visibleGrid = (document) =>
  [...document.querySelectorAll("ul.products > li")]
    .filter((li) => li.style.display !== "none")
    .map((li) => li.className.match(/post-(\d+)/)[1] + (li.classList.contains("irios-sponsored") ? "*" : ""));

describe("search ads", () => {
  it("places each winner at its position", async () => {
    const { document } = search({ positions: [1, 4], winners: [winner(UNIT, 1, 7), winner(UNIT, 2, 8)] });
    await settle();
    expect(visibleGrid(document)).toEqual(["7*", "1", "2", "8*", "3"]);
  });

  it("a position beyond the results goes at the end", async () => {
    const { document } = search({ positions: [1, 10], organic: [1, 2], winners: [winner(UNIT, 1, 7), winner(UNIT, 2, 8)] });
    await settle();
    expect(visibleGrid(document)).toEqual(["7*", "1", "2", "8*"]);
  });

  it("hides the organic result of an advertised product and counts positions without it", async () => {
    const { document } = search({ positions: [2], winners: [winner(UNIT, 1, 3)] });
    await settle();
    expect(visibleGrid(document)).toEqual(["1", "3*", "2"]);
  });

  it("a product won by two ad units is shown once; its second position stays organic", async () => {
    const units = [
      { adUnit: 1, auth: "a", positions: [1] },
      { adUnit: 2, auth: "b", positions: [2] },
    ];
    const { document } = search({ units, winners: [winner(1, 1, 7), winner(2, 1, 7)] });
    await settle();
    expect(visibleGrid(document)).toEqual(["7*", "1", "2", "3"]);
  });

  it("a winner WordPress does not return (hidden or unpublished) is not shown nor tracked; the others keep their position", async () => {
    const { document, log } = search({ positions: [1, 2], winners: [winner(UNIT, 1, 7), winner(UNIT, 2, 8)], cards: [8] });
    await settle();
    expect(visibleGrid(document)).toEqual(["1", "8*", "2", "3"]);
    expect(log.tracked.flatMap((u) => u["tracking-load"] || [])).toEqual(["https://tracker.irios.test/load?ad=8"]);
  });

  it("labels the ad with the configured text and an «About this ad» button", async () => {
    const { document } = search({ winners: [winner(UNIT, 1, 7)] });
    await settle();
    const label = document.querySelector(".irios-sponsored .irios-sponsored-label");
    expect(label.textContent).toContain("Patrocinado");
    expect(label.querySelector(".irios-ad-info")).not.toBeNull();
  });

  it("«About this ad» names the brand as advertiser and the advertiser as who paid, when IRIOS Media gives both", async () => {
    const ad = { ...winner(UNIT, 1, 7), brandName: "Farmacia Andalucia", advertiserName: "Andalucia Farmacia" };
    const { document } = search({ winners: [ad] });
    await settle();
    document.querySelector(".irios-sponsored .irios-ad-info").click();
    const items = [...document.querySelectorAll('.irios-ad-info-overlay [role="dialog"] li')].map((li) => li.textContent);
    expect(items).toContain("Advertiser name: Farmacia Andalucia");
    expect(items).toContain("Who paid for this ad: Andalucia Farmacia");
  });

  it("tracks load and view on the card and clicks only on links to the product, never on add to cart", async () => {
    const { log } = search({ winners: [winner(UNIT, 1, 7)] });
    await settle();
    const load = log.tracked.find((u) => u["tracking-load"]);
    expect(load["tracking-load"]).toEqual(["https://tracker.irios.test/load?ad=7"]);
    expect(load["tracking-view"]).toEqual(["https://tracker.irios.test/view?ad=7"]);
    const clicks = log.tracked.filter((u) => u["tracking-click"]).map((u) => u.element.getAttribute("href"));
    expect(clicks).toEqual(["https://shop.test/product/p7/"]);
  });

  it("asks one auction with every ad unit and as many ads as positions", async () => {
    const units = [
      { adUnit: 1, auth: "a", positions: [1, 5] },
      { adUnit: 2, auth: "b", positions: [3] },
    ];
    const { log } = search({ units });
    await settle();
    expect(log.auctions).toHaveLength(1);
    expect(log.auctions[0]).toMatchObject({
      pageType: "SEARCH",
      searchQuery: "stick",
      language: "es-ES",
      adUnits: [
        { id: 1, auth: "a", type: "product", maxAds: 2 },
        { id: 2, auth: "b", type: "product", maxAds: 1 },
      ],
    });
    // IRIOS ignores the category on search pages: it is not sent
    expect(log.auctions[0]).not.toHaveProperty("category");
  });

  it("with consent the auction and the tracking are personalised", async () => {
    const { log, window } = search({ winners: [winner(UNIT, 1, 7)], context: { uids: [{ namespace: "CRM", uid: "7" }] } });
    await settle();
    expect(log.auctions[0]).toMatchObject({ consent: { gdpr: true }, sessionId: window.sessionStorage.getItem("irios_session_id") });
    expect(log.tracked[0].consent).toEqual({ gdpr: true });
  });

  it("without consent the ads are contextual: no uids, no session", async () => {
    const { log, document } = search({ consent: false, winners: [winner(UNIT, 1, 7)] });
    await settle();
    expect(visibleGrid(document)[0]).toBe("7*");
    expect(log.auctions[0].consent).toEqual({ gdpr: false });
    expect(log.auctions[0]).not.toHaveProperty("uids");
    expect(log.auctions[0]).not.toHaveProperty("sessionId");
    for (const unit of log.tracked) {
      expect(unit.consent).toEqual({ gdpr: false });
      expect(unit).not.toHaveProperty("uids");
    }
  });

  it("if the cards cannot be loaded the results stay as they were and are shown", async () => {
    const { document } = search({ winners: [winner(UNIT, 1, 7)], cards: null, before: (w) => w.document.documentElement.classList.add("irios-ads-pending") });
    await settle();
    expect(visibleGrid(document)).toEqual(["1", "2", "3"]);
    expect(document.documentElement.classList.contains("irios-ads-pending")).toBe(false);
  });

  it("results that are not product cards (blog-style theme): the ad's image takes the shape the theme renders, not its width/height attributes", async () => {
    const ORIGIN = "https://shop.test";
    // Like a theme that prints square thumbnails (145×145 attributes) in a 290×145 box with object-fit: contain.
    const post = (id) =>
      `<div class="list-col-item"><div class="post-thumb"><a href="${ORIGIN}/product/p${id}/">` +
      `<img width="145" height="145" src="${ORIGIN}/p${id}.jpg" class="attachment-270x145 size-270x145" style="object-fit: contain"></a></div>` +
      `<h3 class="post-title"><a href="${ORIGIN}/product/p${id}/">P${id}</a></h3></div>`;
    const results = Object.fromEntries([1, 2, 3].map((id) => [`${ORIGIN}/product/p${id}/`, id]));
    store = storefront({
      body: `<div class="results">${[1, 2, 3].map(post).join("")}</div>`,
      page: {
        type: "search",
        value: "stick",
        ads: { units: [{ adUnit: UNIT, auth: "abc", positions: [1] }], searchQuery: "stick", language: "es-ES", results, empty: false },
      },
      winners: [winner(UNIT, 1, 7)],
      before: (w) => {
        Object.defineProperty(w.HTMLImageElement.prototype, "offsetWidth", { get: () => 290 });
        Object.defineProperty(w.HTMLImageElement.prototype, "offsetHeight", { get: () => 145 });
        const fetch = w.fetch;
        w.fetch = async (url, options) =>
          String(url).includes("/irios/v1/cards")
            ? { ok: true, status: 200, headers: new Map(), json: async () => ({ cards: [{ id: "7", productId: 7, link: `${ORIGIN}/product/p7/`, title: "P7", image: `${ORIGIN}/p7.jpg` }] }) }
            : fetch(url, options);
      },
    });
    await settle();
    const img = store.document.querySelector(".irios-sponsored img");
    expect(img.getAttribute("src")).toBe(`${ORIGIN}/p7.jpg`);
    expect(img.style.aspectRatio).toBe("290 / 145");
    expect(img.style.objectFit).toBe("contain");
  });

  it("without winners nothing changes and no cards are asked for", async () => {
    const { document, log } = search({ winners: [] });
    await settle();
    expect(visibleGrid(document)).toEqual(["1", "2", "3"]);
    expect(log.requests.some((r) => r.startsWith("/wp-json/irios/v1/cards"))).toBe(false);
  });
});
