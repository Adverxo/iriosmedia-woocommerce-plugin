// Sponsored Products in the theme's live search: the dropdown that appears while the customer types. At most one ad,
// at the top, and the dropdown must not be left hidden if the auction does not answer.
import { afterEach, describe, expect, it } from "vitest";
import { card, settle, storefront, winner } from "./harness.mjs";

let store;
afterEach(() => store && store.close());

const UNIT = 9001;
const LIVE = { units: [{ adUnit: UNIT, auth: "abc", positions: [1] }], language: "es-ES" };

/** A page with a search box and no dropdown yet, as the theme renders it before the customer types. */
function searchBox({ winners = [], cards } = {}) {
  store = storefront({
    // As most themes render it: the dropdown appears inside the search form
    body: '<form role="search" class="search-form"><input type="search" name="s"><div id="suggestions"></div></form>',
    page: { type: "page" },
    config: { liveSearchAds: LIVE },
    winners,
    cards,
    // jsdom does not lay the page out, so every element looks hidden (offsetParent null) and the plugin would skip
    // the whole dropdown: here everything in the document counts as visible
    before: (w) => Object.defineProperty(w.HTMLElement.prototype, "offsetParent", { get() { return this.ownerDocument.body; } }),
  });
  return store;
}

/** The customer types, and the theme answers with its dropdown of products. */
async function type(term, productIds) {
  await settle(); // el plugin no mira el buscador hasta tener el contexto del visitante
  const { document, window } = store;
  const input = document.querySelector('input[name="s"]');
  input.value = term;
  input.dispatchEvent(new window.Event("input", { bubbles: true }));
  await settle();
  document.querySelector("#suggestions").innerHTML = `<ul>${productIds.map(card).join("")}</ul>`;
  await settle(120);
}

/** Visible rows of the dropdown, marking which one is the ad. */
const rows = (document) =>
  [...document.querySelectorAll("#suggestions li")]
    .filter((li) => li.style.display !== "none")
    .map((li) => (li.classList.contains("irios-sponsored") ? "ad" : "organic"));

describe("live search", () => {
  it("puts the ad at the top of the dropdown the theme renders after typing", async () => {
    searchBox({ winners: [winner(UNIT, 1, 7)] });

    await type("stick", [1, 2]);

    expect(rows(store.document)).toEqual(["ad", "organic", "organic"]);
  });

  it("asks for the term that was typed, as a search bar and not as a results page", async () => {
    searchBox({ winners: [winner(UNIT, 1, 7)] });

    await type("stick", [1, 2]);

    expect(store.log.auctions.at(-1)).toMatchObject({ pageType: "SEARCH_BAR", searchQuery: "stick" });
  });

  it("does not watch the page until the customer types", async () => {
    searchBox({ winners: [winner(UNIT, 1, 7)] });

    // The theme renders something else entirely (a cart drawer, a slider): nothing to do with the search
    store.document.querySelector("#suggestions").innerHTML = `<ul>${card(1)}</ul>`;
    await settle(120);

    expect(store.log.auctions).toHaveLength(0);
  });

  it("without winners the dropdown is left as the theme rendered it", async () => {
    searchBox({ winners: [] });

    await type("stick", [1, 2]);

    expect(rows(store.document)).toEqual(["organic", "organic"]);
  });

  it("after withdrawing consent the dropdown still gets ads, contextual ones", async () => {
    searchBox({ winners: [winner(UNIT, 1, 7)] });
    await type("stick", [1, 2]);
    expect(store.log.auctions).toHaveLength(1);

    store.window.document.cookie = `cdp_cookie_consent=${encodeURIComponent(JSON.stringify({ necessary: true, marketing: false }))}; path=/`;
    await settle(1200); // the banner fires no event: the plugin polls its cookie

    store.document.querySelector("#suggestions").innerHTML = `<ul>${card(3)}</ul>`;
    await settle(200);

    expect(store.log.auctions.at(-1).consent).toEqual({ gdpr: false });
    expect(store.log.auctions.at(-1)).not.toHaveProperty("uids");
  });
});
