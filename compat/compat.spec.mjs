// Compatibility matrix: with the active theme of the Docker store and the Irios simulator (dev/irios-stub.php), checks
// every place where the plugin puts ads. Run by compat/run.sh (once per theme); it writes the result to
// compat/results/<combination>.json: pass, fail or na (the theme lacks that feature, e.g. no live search).
import { test } from "@playwright/test";
import { mkdirSync, writeFileSync } from "node:fs";

const { COMBO, SEARCH_URL, CATEGORY_URL, PRODUCT_URL, PAGE_URL, SEARCH_INPUT } = process.env;
// Search box to type into: the theme's, or the one from a search plugin placed on the page (SEARCH_INPUT)
const INPUT = SEARCH_INPUT || 'input[type="search"]:visible, input[name="s"]:visible';
const result = { combo: COMBO, checks: {}, warnings: [], errors: [] };

const check = async (name, fn) => {
  try {
    result.checks[name] = await fn();
  } catch (e) {
    result.checks[name] = "fail";
    // First line and, if Playwright explains why it could not act (element covered, invisible…), that reason
    const lines = String(e.message).split("\n");
    const why = lines.map((l) => l.replace(/\x1b\[[0-9;]*m/g, "").trim()).filter((l) => /intercepts|not visible|not enabled|not stable|outside of the viewport|waiting for/.test(l)).slice(-2);
    result.errors.push(`${name}: ${[lines[0], ...why].join(" · ").trim()}`);
  }
};

test.describe.configure({ mode: "serial" });

test("compatibilidad", async ({ page }) => {
  test.setTimeout(180_000);
  page.on("console", (m) => {
    if (m.type() === "warning" && m.text().startsWith("[irios-woo]")) result.warnings.push(m.text());
  });
  page.on("pageerror", (e) => result.errors.push(`js: ${e.message}`));

  // Sponsored Products on the results page: theme card with the label, the info button and its popup
  await page.goto(SEARCH_URL);
  await check("search_grid", async () => {
    await page.locator(".irios-sponsored").first().waitFor({ state: "visible", timeout: 10_000 });
    return "pass";
  });
  await check("ad_label_info", async () => {
    const ad = page.locator(".irios-sponsored").first();
    await ad.locator(".irios-sponsored-label").first().waitFor({ state: "visible", timeout: 3_000 });
    await ad.locator(".irios-ad-info").first().click();
    await page.locator('.irios-ad-info-overlay [role="dialog"]').waitFor({ state: "visible", timeout: 3_000 });
    await page.locator(".irios-ad-info-overlay [data-close].button").click();
    return "pass";
  });
  // The sponsored card is the same size as the result cards (the theme lays it out the same way)
  await check("ad_card_layout", async () => {
    const ad = await page.locator(".irios-sponsored").first().boundingBox();
    let organic = page
      .locator(".products > .product:not(.irios-sponsored):visible, .wc-block-product-template > .wc-block-product:not(.irios-sponsored):visible")
      .first();
    // Search the theme does not render as a shop grid (e.g. as blog posts): a result from the same grid
    if ((await organic.count()) === 0) {
      organic = page.locator(".irios-sponsored").first().locator("xpath=..").locator(":scope > :not(.irios-sponsored):visible").first();
    }
    if ((await organic.count()) === 0) return "na";
    const box = await organic.boundingBox();
    if (!ad || !box || Math.abs(ad.width - box.width) > 2) {
      throw new Error(`width ${ad && Math.round(ad.width)} vs ${box && Math.round(box.width)} of a result card`);
    }
    return "pass";
  });
  await check("add_to_cart", async () => {
    // Button that adds without opening the product page (classic AJAX or blocks); a variable product shows
    // "Seleccionar opciones"
    const selector = ".irios-sponsored .ajax_add_to_cart, .irios-sponsored button[data-product_id]";
    if ((await page.locator(selector).count()) === 0) return "na";
    // Some themes (Astra) render two: one hidden in the image and the visible one; hover and use the visible one
    await page.locator(".irios-sponsored").first().hover();
    const button = page.locator(`${selector.split(", ").map((s) => `${s}:visible`).join(", ")}`).first();
    const before = page.url();
    const added = page
      .waitForResponse((r) => /add_to_cart|wc\/store\/v1\/cart\/add-item/.test(r.url()) && r.ok(), { timeout: 8_000 })
      .then(() => true, () => false);
    await button.click({ timeout: 5_000 });
    if (!(await added)) throw new Error("the click did not add to the cart");
    if (page.url() !== before && !/add-to-cart=/.test(page.url())) return "fail"; // the click navigated away like an ad
    return "pass";
  });

  // Banners in their automatic positions
  for (const [name, url] of [
    ["banner_category", CATEGORY_URL],
    ["banner_product", PRODUCT_URL],
  ]) {
    await page.goto(url);
    await check(name, async () => {
      const slot = page.locator(".irios-banner:not([hidden])").first();
      await slot.waitFor({ state: "visible", timeout: 8_000 });
      // "Patrocinado ⓘ" above the creative, on a single line (the ⓘ right after the text, never below it)
      const label = slot.locator(".irios-sponsored-label").first();
      await label.waitFor({ state: "visible", timeout: 5_000 });
      const creative = slot.locator("iframe, img, .irios-stub-banner").first();
      const [l, c, i] = await Promise.all([label.boundingBox(), creative.boundingBox(), label.locator(".irios-ad-info").boundingBox()]);
      if (!l || !c || !i) throw new Error("missing label, creative or info button");
      if (l.y + l.height > c.y + 1) throw new Error("the label is not above the banner");
      if (i.y > l.y + l.height / 2 || l.height > 2 * i.height + 4) throw new Error("the info button is not next to the ad label");
      // In the top right corner of the creative
      if (Math.abs(l.x + l.width - (c.x + c.width)) > 2) throw new Error("the label is not aligned with the right edge of the banner");
      return "pass";
    });
  }

  // Live search: on a page without products (Mi cuenta), type into the theme's search box (the header one, opening it
  // if needed) and check whether product links appear
  await page.goto(PAGE_URL);
  await check("live_search", async () => {
    let input = page.locator(INPUT).first();
    if ((await input.count()) === 0) {
      const opener = page
        .locator(
          '[class*="search"] button:visible, button[class*="search"]:visible, a[class*="search"]:visible, ' +
            '[aria-label*="earch" i]:visible, [aria-label*="buscar" i]:visible',
        )
        .first();
      if ((await opener.count()) === 0) return "na";
      await opener.click();
      input = page.locator(INPUT).first();
      if ((await input.count()) === 0) return "na";
    }
    // Product links visible now (mini cart, widgets…): they do not count as the search dropdown
    const visibles = () =>
      [...document.querySelectorAll('a[href*="/product"], a[href*="/producto"]')].filter(
        (a) =>
          a.offsetParent !== null &&
          !a.closest('.irios-sponsored, [class*="mini-cart"], [class*="minicart"], .widget_shopping_cart, .cart_list'),
      );
    await page.evaluate(`window.__antes = new Set((${visibles.toString()})())`);
    await input.click();
    await input.pressSequentially("stick", { delay: 120 });
    // Does a dropdown with products appear? If not, the theme has no live search
    const appeared = await page
      .waitForFunction(`(${visibles.toString()})().some((a) => !window.__antes.has(a))`, null, { timeout: 6_000 })
      .then(() => true, () => false);
    // No dropdown: the theme has no live search; a search plugin does, so it means we do not recognise it
    if (!appeared && SEARCH_INPUT) throw new Error("the plugin search dropdown has no product links");
    if (!appeared) return "na";
    await page.locator(".irios-sponsored").first().waitFor({ state: "visible", timeout: 6_000 });
    return "pass";
  });

  mkdirSync("results", { recursive: true });
  writeFileSync(`results/${COMBO}.json`, JSON.stringify(result, null, 2));
});
