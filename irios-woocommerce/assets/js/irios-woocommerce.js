/**
 * IRIOS Media for WooCommerce — the storefront JS.
 *
 * DO NOT EDIT: built from assets/js/src (npm run build:js). Each part is a module of its own in that folder:
 * config, consent, identity, tracker, events, search-ads, ad-info, display, push, live-search and startup.
 */
(function() {
  // irios-woocommerce/assets/js/src/config.mjs
  var cfg = window.iriosWooConfig || {};
  function isConfigured() {
    return !!(window.iriosWooConfig && cfg.jsDomain && cfg.storeId);
  }
  var SESSION_KEY = "irios_session_id";
  var DEDUP_MS = 2e3;
  var state = {
    started: false,
    ready: false,
    queue: [],
    // uids of the logged-in customer (CRM, email, phone). The browser one (irs1P cookie) is added by the Irios script to
    // its requests; the server stores it on the order (Orders.php)
    uids: [],
    sessionId: null,
    trackerPromise: null,
    loaderPromise: null,
    recent: {},
    lastVariation: null,
    flushWhenReady: false
  };
  var page = window.iriosWooPage || { type: "page" };
  var DEBUG = function() {
    try {
      return /[?&]irios_debug=1\b/.test(window.location.search) || window.localStorage.getItem("irios_debug") === "1" || window.IRIOS_DEBUG === true;
    } catch (e) {
      return window.IRIOS_DEBUG === true;
    }
  }();
  function debug() {
    if (DEBUG && window.console) {
      window.console.log.apply(window.console, ["[irios-woo]"].concat(Array.prototype.slice.call(arguments)));
    }
  }
  var warned = {};
  function warnTheme(key, message) {
    if (!DEBUG || !window.console || warned[key]) {
      return;
    }
    warned[key] = true;
    window.console.warn("[irios-woo] " + message + " (theme: " + (cfg.theme || "?") + ")");
  }
  debug("IRIOS Media for WooCommerce " + (cfg.version || "?"));
  if (DEBUG && window.performance && typeof window.performance.setResourceTimingBufferSize === "function") {
    window.performance.setResourceTimingBufferSize(5e3);
  }
  function now() {
    return window.performance && window.performance.now ? window.performance.now() : Date.now();
  }
  function ms(value) {
    return Math.round(value) + " ms";
  }
  function answerBreakdown(from, to) {
    if (!window.performance || typeof window.performance.getEntriesByType !== "function") {
      return "";
    }
    var entries = window.performance.getEntriesByType("resource");
    var ads = entries.filter(function(e) {
      return /\/v1\/ads\b/.test(e.name) && e.startTime >= from - 1 && e.responseEnd <= to + 1;
    }).pop();
    if (!ads) {
      return "";
    }
    var iuid = entries.filter(function(e) {
      return /\/iuid\b/.test(e.name) && e.startTime >= from - 1 && e.responseEnd <= ads.startTime + 1;
    }).pop();
    return " (before the request " + ms(ads.startTime - from) + (iuid ? ", incl. /iuid " + ms(iuid.duration) : "") + " · /v1/ads " + ms(ads.responseEnd - ads.startTime) + " · after the response " + ms(to - ads.responseEnd) + ")";
  }
  function loaderReady(loaderMs) {
    var entry = window.performance && typeof window.performance.getEntriesByType === "function" ? window.performance.getEntriesByType("resource").filter(function(e) {
      return /\/irios-loader\.js/.test(e.name);
    }).pop() : null;
    var source = "";
    if (entry) {
      var fetchMs = entry.responseEnd - entry.startTime;
      if (entry.decodedBodySize > 0) {
        source = 0 === entry.transferSize ? " (from browser cache)" : " (downloaded in " + ms(fetchMs) + ")";
      } else {
        source = fetchMs < 5 ? " (from memory cache)" : " (fetched in " + ms(fetchMs) + ")";
      }
    }
    return "irios-loader.js ready in " + ms(loaderMs) + source;
  }
  function unitsLabel(units) {
    var ids = (units || []).map(function(u) {
      return u.adUnit;
    });
    return "ad unit" + (ids.length > 1 ? "s " : " ") + ids.join(", ");
  }
  function debugTiming(label, where, timing, visible, pageTime) {
    if (!DEBUG || timing.logged) {
      return;
    }
    timing.logged = true;
    var winners = timing.winners || 0;
    var parts = [label, where];
    if (timing.loader > 5) {
      parts.push(loaderReady(timing.loader));
    }
    parts.push(
      "Irios answered in " + ms(timing.auction || 0) + answerBreakdown(timing.auctionStart, timing.answeredAt) + " with " + (winners ? winners + " ad" + (winners > 1 ? "s" : "") : "no ads")
    );
    if (visible) {
      if (timing.answeredAt) {
        parts.push("rendered in " + ms(now() - timing.answeredAt));
      }
      if (pageTime) {
        parts.push("ad visible " + ms(now()) + " after the page started loading");
      }
    }
    debug(parts.join(" · "));
  }

  // irios-woocommerce/assets/js/src/identity.mjs
  function uuid() {
    if (window.crypto && typeof window.crypto.randomUUID === "function") {
      return window.crypto.randomUUID();
    }
    var bytes = new Uint8Array(16);
    window.crypto.getRandomValues(bytes);
    bytes[6] = bytes[6] & 15 | 64;
    bytes[8] = bytes[8] & 63 | 128;
    var hex = Array.prototype.map.call(bytes, function(b) {
      return ("0" + b.toString(16)).slice(-2);
    }).join("");
    return hex.slice(0, 8) + "-" + hex.slice(8, 12) + "-" + hex.slice(12, 16) + "-" + hex.slice(16, 20) + "-" + hex.slice(20);
  }
  function readCookie(name) {
    var match = document.cookie.match(new RegExp("(?:^|; )" + name.replace(/[.*+?^${}()|[\]\\]/g, "\\$&") + "=([^;]*)"));
    return match ? decodeURIComponent(match[1]) : null;
  }
  function sessionId() {
    try {
      var value = window.sessionStorage.getItem(SESSION_KEY);
      if (!value) {
        value = uuid();
        window.sessionStorage.setItem(SESSION_KEY, value);
      }
      return value;
    } catch (e) {
      return state.sessionId || uuid();
    }
  }
  function fetchContext() {
    var request = function(withNonce) {
      var headers = { Accept: "application/json" };
      if (withNonce && cfg.restNonce) {
        headers["X-WP-Nonce"] = cfg.restNonce;
      }
      return window.fetch(cfg.contextUrl, { credentials: "same-origin", cache: "no-store", headers: headers });
    };
    return request(true).then(function(r) {
      return r.status === 403 ? request(false) : r;
    }).then(function(r) {
      return r.ok ? r.json() : {};
    }).then(function(body) {
      return {
        uids: Array.isArray(body.uids) ? body.uids : [],
        events: Array.isArray(body.events) ? body.events : []
      };
    }).catch(function() {
      return { uids: [], events: [] };
    });
  }

  // irios-woocommerce/assets/js/src/consent.mjs
  function consentType() {
    if (typeof window.wp_consent_type !== "undefined") {
      return window.wp_consent_type;
    }
    return window.wp_fallback_consent_type;
  }
  function hasConsent() {
    if (cfg.consentMode === "always") {
      return true;
    }
    if (cfg.consentMode === "asesor_cookies") {
      return asesorConsent();
    }
    return !!consentType() && typeof window.wp_has_consent === "function" && window.wp_has_consent(cfg.consentCategory);
  }
  function adUser() {
    return hasConsent() ? { uids: state.uids, sessionId: state.sessionId, consent: { gdpr: true } } : { consent: { gdpr: false } };
  }
  function asesorConsent() {
    var name = window.cdpCookiesConfig && window.cdpCookiesConfig.cookieName || cfg.asesorCookie;
    var raw = readCookie(name);
    if (!raw) {
      return false;
    }
    try {
      return JSON.parse(raw)[cfg.consentCategory] === true;
    } catch (e) {
      return false;
    }
  }

  // irios-woocommerce/assets/js/src/search-ads.mjs
  function selector(name, fallback) {
    var given = cfg.selectors && cfg.selectors[name];
    return typeof given === "string" && given.trim() ? given : fallback;
  }
  var GRID_SELECTOR = selector("grid", ".products, .wc-block-product-template");
  var TITLE_SELECTOR = selector("title", ".woocommerce-loop-product__title, .product-title, .post-title, .entry-title, .wp-block-post-title, h2, h3");
  var CART_LINK_SELECTOR = '.ajax_add_to_cart, [href*="add-to-cart="]';
  function nativeOf(ad) {
    return ad ? ad.native || ad.nativee : null;
  }
  function emptyResultsGrid() {
    var notice = document.querySelector(".woocommerce-no-products-found") || document.querySelector(".woocommerce-info");
    var parent = notice ? notice.parentNode : document.querySelector("main, #main, #primary, .site-main");
    if (!parent) {
      return null;
    }
    var grid = document.createElement("ul");
    grid.className = "products columns-4 irios-empty-results";
    parent.insertBefore(grid, notice || parent.firstChild);
    return grid;
  }
  function renderSearchAds() {
    var ads = page.ads;
    var grid = ads ? findGrid() || findResultsGrid(ads.results) || (ads.empty ? emptyResultsGrid() : null) : null;
    if (ads && !grid) {
      warnTheme("search-grid", "Sponsored Products: no results grid found on this search page, no ads shown");
    }
    if (!grid || grid.getAttribute("data-irios-ads")) {
      revealResults();
      return;
    }
    grid.setAttribute("data-irios-ads", "1");
    var layout = grid.matches(".wc-block-product-template") ? "block" : grid.matches(GRID_SELECTOR) ? "classic" : "data";
    var timing = {};
    requestAds(ads, "SEARCH", ads.searchQuery, layout, timing, "data" === layout ? imageSizeOf(grid) : "").then(function(result) {
      if (!result && grid.classList.contains("irios-empty-results")) {
        grid.remove();
      }
      if (result) {
        if ("data" === layout) {
          insertClonedResults(grid, result.placements, result.cards, ads.results || {});
        } else {
          insertCards(grid, result.placements, result.cards);
        }
      }
      debugTiming(unitsLabel(ads.units), "search " + JSON.stringify(ads.searchQuery), timing, !!result, true);
    }).catch(function(e) {
      if (window.console) {
        window.console.warn("[irios] ads not available", e);
      }
    }).then(revealResults);
  }
  function requestAds(config, pageType, searchQuery, layout, timing, imageSize) {
    timing = timing || {};
    timing.start = now();
    var units = (config.units || []).filter(function(u) {
      return u && u.adUnit && u.auth && u.positions && u.positions.length;
    });
    if (!units.length) {
      return Promise.resolve(null);
    }
    return loadLoader().then(function() {
      timing.loader = now() - timing.start;
      timing.auctionStart = now();
      var loader = new window.IriosLoader(Object.assign(adUser(), {
        pageType: pageType,
        searchQuery: String(searchQuery).slice(0, 255),
        language: config.language,
        adUnits: units.map(function(u) {
          return { id: u.adUnit, auth: u.auth, type: "product", maxAds: u.positions.length };
        })
      }));
      if (typeof loader.getAds === "function") {
        return Promise.all(units.map(function(u) {
          return loader.getAds(u.adUnit);
        })).then(function(lists) {
          return [].concat.apply([], lists);
        });
      }
      return loader.adResponsesPromise;
    }).then(function(responses) {
      timing.answeredAt = now();
      timing.auction = timing.answeredAt - timing.auctionStart;
      var placements = placeWinners(units, responses || []);
      timing.winners = placements.length;
      if (!placements.length) {
        return null;
      }
      var cardsStart = now();
      var ids = placements.map(function(p) {
        return String(nativeOf(p.response).product.id);
      });
      return window.fetch(cfg.cardsUrl + "?layout=" + layout + "&ids=" + encodeURIComponent(ids.join(",")) + (imageSize ? "&imageSize=" + encodeURIComponent(imageSize) : ""), {
        credentials: "same-origin",
        headers: { Accept: "application/json" }
      }).then(function(r) {
        return r.ok ? r.json() : { cards: [] };
      }).catch(function() {
        return { cards: [] };
      }).then(function(body) {
        timing.cards = now() - cardsStart;
        var cards = body.cards || [];
        if ("data" === layout) {
          cards.forEach(function(card) {
            if (card.priceHtml) {
              return;
            }
            var placement = placements.find(function(p) {
              return String(nativeOf(p.response).product.id) === String(card.id);
            });
            card.priceHtml = placement ? nativeCard(placement.response, config.language).priceHtml : "";
          });
        }
        if (!cards.length && "data" === layout) {
          cards = placements.map(function(p) {
            return nativeCard(p.response, config.language);
          });
          debug("cards not available from WordPress: ad rendered with Irios catalog data");
        }
        return { placements: placements, cards: cards, timing: timing };
      });
    });
  }
  function placeWinners(units, responses) {
    var used = {};
    var placements = [];
    units.forEach(function(unit) {
      var positions = unit.positions.slice().sort(function(a, b) {
        return a - b;
      });
      var winners = responses.filter(function(r) {
        var creative = nativeOf(r);
        return r && Number(r.adUnitId) === Number(unit.adUnit) && creative && creative.product && creative.product.id;
      }).sort(function(a, b) {
        return (a.position || 0) - (b.position || 0);
      });
      var next = 0;
      winners.forEach(function(r) {
        var id = String(nativeOf(r).product.id);
        if (next >= positions.length || used[id]) {
          return;
        }
        used[id] = true;
        placements.push({ position: positions[next++], response: r });
      });
    });
    return placements.sort(function(a, b) {
      return a.position - b.position;
    });
  }
  function placeAt(container, item, position) {
    var visible = Array.prototype.filter.call(container.children, function(el) {
      return el.style.display !== "none" && !el.hidden && el.tagName !== "SCRIPT" && el.tagName !== "STYLE" && el.tagName !== "TEMPLATE";
    });
    var before = visible[position - 1];
    if (before) {
      container.insertBefore(item, before);
    } else {
      container.appendChild(item);
    }
  }
  function findGrid() {
    return Array.prototype.find.call(document.querySelectorAll(GRID_SELECTOR), function(el) {
      return !el.closest(".related, .upsells, .cross-sells, .widget") && Array.prototype.some.call(el.children, function(child) {
        return child.matches(".product, .wc-block-product");
      });
    }) || null;
  }
  function normalizeUrl(url) {
    try {
      var u = new URL(url, window.location.href);
      return (u.host + u.pathname).replace(/\/+$/, "");
    } catch (e) {
      return "";
    }
  }
  function findResultsGrid(results) {
    var wanted = {};
    Object.keys(results || {}).forEach(function(link) {
      wanted[normalizeUrl(link)] = true;
    });
    var links = Array.prototype.filter.call(document.querySelectorAll("a[href]"), function(a) {
      return wanted[normalizeUrl(a.href)] && !a.closest("header, footer, nav, .widget");
    });
    if (!links.length) {
      return null;
    }
    var other = links.find(function(a) {
      return normalizeUrl(a.href) !== normalizeUrl(links[0].href);
    });
    if (!other) {
      var node = links[0];
      while (node.parentElement && node.parentElement.children.length < 2) {
        node = node.parentElement;
      }
      return node.parentElement;
    }
    var container = links[0].parentElement;
    while (container && !container.contains(other)) {
      container = container.parentElement;
    }
    return container;
  }
  function itemOf(grid, el) {
    while (el && el.parentElement !== grid) {
      el = el.parentElement;
    }
    return el;
  }
  function resultItem(grid, results, productId) {
    var link = Object.keys(results).find(function(url) {
      return Number(results[url]) === Number(productId);
    });
    if (!link) {
      return null;
    }
    var a = Array.prototype.find.call(grid.querySelectorAll("a[href]"), function(el) {
      return normalizeUrl(el.href) === normalizeUrl(link);
    });
    return a ? itemOf(grid, a) : null;
  }
  function insertClonedResults(grid, placements, cards, results) {
    var byId = {};
    cards.forEach(function(card) {
      byId[card.id] = card;
    });
    var templateLink = Array.prototype.find.call(grid.querySelectorAll("a[href]"), function(a) {
      return Object.keys(results).some(function(url) {
        return normalizeUrl(url) === normalizeUrl(a.href);
      });
    });
    var template = templateLink ? itemOf(grid, templateLink) : null;
    if (!template) {
      return;
    }
    var templateUrl = normalizeUrl(templateLink.href);
    var titleText = resultTitle(template, templateUrl);
    var items = [];
    placements.forEach(function(p) {
      var card = byId[String(nativeOf(p.response).product.id)];
      if (!card) {
        return;
      }
      items.push({ item: cloneResult(template, templateUrl, titleText, card), placement: p });
      var duplicate = resultItem(grid, results, card.productId);
      if (duplicate) {
        duplicate.style.display = "none";
      }
    });
    items.forEach(function(entry) {
      placeAt(grid, entry.item, entry.placement.position);
      addLabel(entry.item, entry.placement.response);
      trackAd(entry.item, entry.placement.response);
    });
  }
  function resultTitle(item, url) {
    return Array.prototype.reduce.call(item.querySelectorAll("a[href]"), function(best, a) {
      var text = a.textContent.trim();
      return normalizeUrl(a.href) === url && text.length > best.length ? text : best;
    }, "");
  }
  function nativeCard(response, language) {
    var product = nativeOf(response).product;
    var price = "";
    if (product.price != null && window.Intl) {
      try {
        price = new window.Intl.NumberFormat(language || "es-ES", { style: "currency", currency: cfg.currency || "EUR" }).format(Number(product.price));
      } catch (e) {
      }
    }
    var span = document.createElement("span");
    span.className = "woocommerce-Price-amount amount";
    span.textContent = price;
    return {
      id: String(product.id),
      productId: 0,
      link: product.clickUrl || "",
      title: product.name || "",
      image: product.imageUrl || "",
      excerpt: "",
      date: "",
      priceHtml: price ? span.outerHTML : ""
    };
  }
  function imageSizeOf(grid) {
    var img = grid.querySelector('img[class*="size-"]');
    var match = img ? /(?:^|\s)size-([a-z0-9_-]+)/.exec(img.className) : null;
    return match ? match[1] : "";
  }
  function renderedImageBox(template) {
    var own = template.querySelector("img");
    var candidates = [own];
    if (template.parentElement) {
      Array.prototype.forEach.call(template.parentElement.children, function(sibling) {
        if (sibling !== template && !sibling.classList.contains("irios-sponsored")) {
          candidates.push(sibling.querySelector("img"));
        }
      });
    }
    for (var i = 0; i < candidates.length; i++) {
      var c = candidates[i];
      if (c && c.offsetWidth > 0 && c.offsetHeight > 0) {
        var fit = window.getComputedStyle(c).objectFit;
        return { w: c.offsetWidth, h: c.offsetHeight, fit: fit && fit !== "fill" ? fit : "cover" };
      }
    }
    var w = own ? Number(own.getAttribute("width")) : 0;
    var h = own ? Number(own.getAttribute("height")) : 0;
    return w && h ? { w: w, h: h, fit: "cover" } : null;
  }
  function cloneResult(template, templateUrl, titleText, card) {
    var item = template.cloneNode(true);
    item.classList.add("irios-sponsored");
    item.removeAttribute("id");
    item.style.display = "";
    Array.prototype.forEach.call(item.querySelectorAll("a[href]"), function(a) {
      if (normalizeUrl(a.href) === templateUrl) {
        a.setAttribute("href", card.link);
      }
    });
    var img = item.querySelector("img");
    if (img && card.image) {
      var box = renderedImageBox(template);
      img.setAttribute("src", card.image);
      img.removeAttribute("srcset");
      img.removeAttribute("sizes");
      img.setAttribute("alt", card.title);
      if (box) {
        img.style.aspectRatio = box.w + " / " + box.h;
        img.style.objectFit = box.fit;
        img.style.height = "auto";
      }
    }
    Array.prototype.forEach.call(item.querySelectorAll("*"), function(el) {
      if (titleText && !el.children.length && el.textContent.trim() === titleText) {
        el.textContent = card.title;
      }
    });
    var price = item.querySelector('[class*="price"]');
    if (price) {
      price.innerHTML = card.priceHtml || "";
    }
    var excerpt = item.querySelector('[class*="excerpt"], .entry-summary, .post-content p, p.desc, .desc');
    if (excerpt) {
      var text = (card.excerpt || "").replace(/\u00a0/g, " ").trim();
      excerpt.textContent = text;
      excerpt.style.display = text ? "" : "none";
    }
    var date = item.querySelector('[class*="date"]');
    if (date && card.date) {
      date.textContent = card.date;
    }
    return item;
  }
  function insertCards(grid, placements, cards) {
    var byId = {};
    cards.forEach(function(card) {
      byId[card.id] = card;
    });
    var items = [];
    placements.forEach(function(p) {
      var card = byId[String(nativeOf(p.response).product.id)];
      if (!card) {
        return;
      }
      var holder = document.createElement("div");
      holder.innerHTML = card.html;
      var item = holder.querySelector(".product, .wc-block-product") || holder.firstElementChild;
      if (!item) {
        return;
      }
      var duplicate = grid.querySelector(":scope > .post-" + card.productId + ":not(.irios-sponsored)");
      if (duplicate) {
        duplicate.style.display = "none";
      }
      item.classList.add("irios-sponsored");
      wireBlockAddToCart(item);
      items.push({ item: item, placement: p });
    });
    var layout = loopLayoutClasses(grid);
    items.forEach(function(entry) {
      layout.forEach(function(c) {
        entry.item.classList.add(c);
      });
      placeAt(grid, entry.item, entry.placement.position);
      addLabel(entry.item, entry.placement.response);
      trackAd(entry.item, entry.placement.response);
    });
    fixLoopClasses(grid);
  }
  var PRODUCT_CLASS = /^(post-\d+|product_cat-|product_tag-|product-type-|status-|pa_|irios-)|^(instock|outofstock|onbackorder|first|last|sale|featured|downloadable|virtual|purchasable|shipping-taxable|taxable|has-post-thumbnail)$/;
  function loopLayoutClasses(grid) {
    var organic = Array.prototype.filter.call(grid.children, function(el) {
      return el.matches(".product") && !el.classList.contains("irios-sponsored");
    });
    if (!organic.length) {
      return [];
    }
    return Array.prototype.filter.call(organic[0].classList, function(c) {
      return !PRODUCT_CLASS.test(c) && organic.every(function(el) {
        return el.classList.contains(c);
      });
    });
  }
  function fixLoopClasses(grid) {
    if (grid.matches(".wc-block-product-template")) {
      return;
    }
    var match = (grid.className || "").match(/columns-(\d+)/) || (grid.firstElementChild && grid.firstElementChild.className || "").match(/list-(\d+)-item/);
    if (!match) {
      return;
    }
    var columns = Number(match[1]);
    var visible = Array.prototype.filter.call(grid.children, function(li) {
      return li.style.display !== "none";
    });
    visible.forEach(function(li, i) {
      li.classList.toggle("first", i % columns === 0);
      li.classList.toggle("last", i % columns === columns - 1);
    });
  }

  // irios-woocommerce/assets/js/src/ad-info.mjs
  var PROFILING = ["CUSTOM_PARAM", "SEGMENT", "ADVERTISER_AUDIENCE"];
  function adInfoButton(response) {
    var texts = cfg.adInfo || {};
    var button = document.createElement("span");
    button.className = "irios-ad-info";
    button.setAttribute("role", "button");
    button.setAttribute("tabindex", "0");
    button.setAttribute("aria-label", texts.button || "Information about this ad");
    button.title = texts.button || "";
    button.style.cssText = "display:inline-flex;vertical-align:middle;margin-left:0.3em;cursor:pointer;";
    button.innerHTML = '<svg width="1.1em" height="1.1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01" stroke-linecap="round"/></svg>';
    var open = function(event) {
      event.preventDefault();
      event.stopPropagation();
      showAdInfo(response);
    };
    button.addEventListener("click", open);
    button.addEventListener("keydown", function(event) {
      if (event.key === "Enter" || event.key === " ") {
        open(event);
      }
    });
    return button;
  }
  function showAdInfo(response) {
    var texts = cfg.adInfo || {};
    var reasons = texts.reasons || {};
    var types = (response && Array.isArray(response.targetingTypes) ? response.targetingTypes : []).filter(function(t, i, all) {
      return reasons[t] && all.indexOf(t) === i;
    });
    var brand = response && (response.brandName || response.advertiserName) || texts.unknown || "";
    var payer = response && (response.advertiserName || response.brandName) || texts.unknown || "";
    var esc = function(text) {
      var div = document.createElement("div");
      div.textContent = text == null ? "" : String(text);
      return div.innerHTML;
    };
    var profiling = types.some(function(t) {
      return PROFILING.indexOf(t) !== -1;
    });
    var overlay = document.createElement("div");
    overlay.className = "irios-ad-info-overlay";
    overlay.style.cssText = "position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;padding:16px;";
    overlay.innerHTML = '<div role="dialog" aria-modal="true" aria-labelledby="irios-ad-info-title" style="background:#fff;color:#222;max-width:480px;width:100%;border-radius:12px;padding:24px;position:relative;font-size:15px;line-height:1.55;box-shadow:0 10px 40px rgba(0,0,0,.25)"><button type="button" data-close aria-label="' + esc(texts.ok) + '" style="position:absolute;top:12px;right:12px;background:none;border:0;font-size:24px;line-height:1;cursor:pointer;color:#333">×</button><h2 id="irios-ad-info-title" style="margin:0 0 14px;font-size:20px">' + esc(texts.title) + '</h2><p style="margin:0"><strong>' + esc(texts.why) + "</strong><br>" + esc(texts.because) + '</p><ul style="margin:4px 0 0;padding-left:1.2em">' + (types.length ? types.map(function(t) {
      return "<li>" + esc(reasons[t]) + "</li>";
    }).join("") : "<li>" + esc(texts.noTargeting) + "</li>") + "</ul>" + (profiling ? '<p style="margin:8px 0 0;opacity:.8">' + esc(texts.profiling) + " " + (texts.privacyUrl ? '<a href="' + esc(texts.privacyUrl) + '">' + esc(texts.privacy) + "</a>" : esc(texts.privacy)) + ".</p>" : "") + '<p style="margin:16px 0 0"><strong>' + esc(texts.advertiser) + '</strong></p><ul style="margin:4px 0 0;padding-left:1.2em"><li>' + esc(texts.name) + ": <strong>" + esc(brand) + "</strong></li><li>" + esc(texts.payer) + ": <strong>" + esc(payer) + '</strong></li></ul><div style="text-align:right;margin-top:20px"><button type="button" data-close class="button" style="cursor:pointer">' + esc(texts.ok) + "</button></div></div>";
    var previous = document.activeElement;
    var close = function() {
      overlay.remove();
      document.removeEventListener("keydown", onKey, true);
      if (previous && previous.focus) {
        previous.focus();
      }
    };
    var onKey = function(event) {
      if (event.key === "Escape") {
        close();
      }
    };
    overlay.addEventListener("click", function(event) {
      if (event.target === overlay || event.target.closest && event.target.closest("[data-close]")) {
        close();
      }
    });
    document.addEventListener("keydown", onKey, true);
    document.body.appendChild(overlay);
    var ok = overlay.querySelector(".button[data-close]");
    if (ok) {
      ok.focus();
    }
  }
  function addLabel(item, response) {
    var label = document.createElement("span");
    label.className = "irios-sponsored-label";
    label.textContent = cfg.sponsoredLabel || "Sponsored";
    label.appendChild(adInfoButton(response));
    label.style.cssText = "display:block;white-space:nowrap;font-size:0.75em;opacity:0.7;margin:0.25em 0;";
    var title = item.querySelector(TITLE_SELECTOR);
    if (title && title.parentNode) {
      var titleStyle = window.getComputedStyle(title);
      label.style.textAlign = titleStyle.textAlign || "";
      label.style.color = titleStyle.color || "";
      label.style.fontSize = Math.max(11, parseFloat(titleStyle.fontSize) * 0.75) + "px";
      title.parentNode.insertBefore(label, title);
    } else {
      item.insertBefore(label, item.firstChild);
    }
  }
  function wireBlockAddToCart(item) {
    Array.prototype.forEach.call(item.querySelectorAll("button[data-product_id][data-wp-on--click]"), function(button) {
      button.removeAttribute("data-wp-on--click");
      button.addEventListener("click", function(event) {
        event.preventDefault();
        if (button.disabled) {
          return;
        }
        button.disabled = true;
        button.classList.add("loading");
        storeApiAddItem(Number(button.getAttribute("data-product_id"))).then(function(ok) {
          button.classList.remove("loading");
          button.disabled = false;
          if (ok) {
            button.classList.add("added");
            document.body.dispatchEvent(new CustomEvent("wc-blocks_added_to_cart", { bubbles: true }));
          } else {
            window.location.href = button.closest("li").querySelector("a[href]").href;
          }
        });
      });
    });
  }
  function storeApiAddItem(productId) {
    var base = cfg.storeApiUrl;
    return window.fetch(base + "cart", { credentials: "same-origin" }).then(function(r) {
      return window.fetch(base + "cart/add-item", {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json", Nonce: r.headers.get("Nonce") || "" },
        body: JSON.stringify({ id: productId, quantity: 1 })
      });
    }).then(function(r) {
      return r.ok;
    }).catch(function() {
      return false;
    });
  }
  function trackAd(item, response) {
    loadTracker().then(function() {
      trackAdNow(item, response);
    }).catch(function() {
    });
  }
  function trackAdNow(item, response) {
    var urls = nativeOf(response).trackingUrls || {};
    if (typeof window.IriosTracker !== "function") {
      return;
    }
    var user = adUser();
    var units = [
      Object.assign({}, user, {
        element: item,
        "tracking-load": urls.loadTrackingUrl,
        "tracking-view": urls.viewTrackingUrl,
        loadUrls: urls.loadTrackingUrl,
        viewUrls: urls.viewTrackingUrl
      })
    ];
    if (urls.clickTrackingUrl) {
      Array.prototype.forEach.call(item.querySelectorAll("a[href]"), function(link) {
        if (!link.matches(CART_LINK_SELECTOR)) {
          units.push(Object.assign({}, user, {
            element: link,
            "tracking-click": urls.clickTrackingUrl,
            clickUrls: urls.clickTrackingUrl
          }));
        }
      });
    }
    try {
      new window.IriosTracker(units);
    } catch (e) {
    }
  }

  // irios-woocommerce/assets/js/src/display.mjs
  var LISTING_CONTROLS_SELECTOR = selector(
    "listingControls",
    ".storefront-sorting, .woocommerce-ordering, .woocommerce-result-count, .woocommerce-pagination, nav.pagination, .pagi-nav"
  );
  var PRODUCT_TABS_SELECTOR = selector("productTabs", ".woocommerce-tabs, .detail-tabs, .wp-block-woocommerce-product-details");
  var RELATED_SELECTOR = selector("related", "section.related.products, .related.products, section.upsells.products, .upsells.products, .wp-block-woocommerce-related-products");
  var SIDEBAR_SELECTOR = selector("sidebar", "#secondary, aside.widget-area, .widget-area, aside.sidebar, #sidebar, .shop-sidebar");
  var DESKTOP_FROM = 768;
  function forThisDevice(slot) {
    var device = slot.getAttribute("data-device") || "all";
    if (device === "all") {
      return true;
    }
    return window.innerWidth >= DESKTOP_FROM === (device === "desktop");
  }
  function inGridTarget() {
    var grid = findGrid();
    if (!grid) {
      return null;
    }
    var products = Array.prototype.filter.call(grid.children, function(child) {
      return child.matches(".product, .wc-block-product");
    });
    var after = products[Math.min(Number(cfg.gridAfter) || 6, products.length) - 1];
    if (!after || after === products[products.length - 1]) {
      return null;
    }
    var item = document.createElement(grid.tagName === "UL" || grid.tagName === "OL" ? "li" : "div");
    item.className = "irios-banner-grid-item";
    item.style.cssText = "grid-column:1/-1;width:100%;flex:0 0 100%;max-width:100%;float:none;clear:both;margin:1em 0;list-style:none;";
    grid.insertBefore(item, after.nextSibling);
    return { parent: item, before: null };
  }
  function placeBanners() {
    Array.prototype.forEach.call(document.querySelectorAll(".irios-banner[data-irios-position]"), function(slot) {
      var position = slot.getAttribute("data-irios-position");
      slot.removeAttribute("data-irios-position");
      var target = null;
      if (!forThisDevice(slot)) {
        slot.parentNode.removeChild(slot);
        return;
      }
      if (position === "category_sidebar") {
        var sidebar = document.querySelector(SIDEBAR_SELECTOR);
        target = sidebar && { parent: sidebar, before: sidebar.firstChild };
      } else if (position === "category_in_grid") {
        target = inGridTarget();
      } else if (position === "product_before_related") {
        var related = document.querySelector(RELATED_SELECTOR);
        target = related && { parent: related.parentNode, before: related };
      } else if (position === "category_after_products") {
        var grid = findGrid();
        var after = grid;
        while (after && after.nextElementSibling && after.nextElementSibling.matches(LISTING_CONTROLS_SELECTOR)) {
          after = after.nextElementSibling;
        }
        target = after && { parent: after.parentNode, before: after.nextSibling };
      } else if (position === "product_before_description") {
        var tabs = Array.prototype.find.call(document.querySelectorAll(PRODUCT_TABS_SELECTOR), function(el) {
          return !el.closest(".related, .upsells, .widget");
        });
        target = tabs && { parent: tabs.parentNode, before: tabs };
      }
      if (!target) {
        warnTheme("banner-" + position, 'banner "' + (slot.getAttribute("data-tag") || slot.getAttribute("data-ad-unit")) + '": automatic position "' + position + '" not found on this page, banner not shown');
        slot.parentNode.removeChild(slot);
        return;
      }
      target.parent.insertBefore(slot, target.before);
      slot.removeAttribute("hidden");
      slot.style.display = "flex";
      slot.style.flexDirection = "column";
      slot.style.alignItems = "center";
      slot.style.clear = "both";
      slot.style.margin = "1.5em 0";
    });
  }
  function bannerPage(slots) {
    var pageType = page.pageType;
    if (!pageType) {
      slots.some(function(slot) {
        pageType = slot.getAttribute("data-page-type") || "";
        return !!pageType;
      });
    }
    if (!pageType) {
      return null;
    }
    var context = { pageType: pageType };
    if (pageType === "SEARCH") {
      context.searchQuery = String(page.value || "").trim().slice(0, 255);
      if (!context.searchQuery) {
        return null;
      }
    } else if (pageType === "CATEGORY" || pageType === "PRODUCT_DETAIL") {
      context.category = page.category || [];
      if (!context.category.length) {
        return null;
      }
    }
    return context;
  }
  function renderBanners() {
    if (!cfg.bannersEnabled || cfg.bannersEnabled === "0") {
      return;
    }
    placeBanners();
    var slots = Array.prototype.filter.call(document.querySelectorAll(".irios-banner[data-ad-unit]"), function(slot) {
      return !slot.hasAttribute("data-irios-rendered") && forThisDevice(slot);
    });
    if (!slots.length) {
      return;
    }
    var units = {};
    slots.forEach(function(slot) {
      slot.setAttribute("data-irios-rendered", "1");
      units[slot.getAttribute("data-ad-unit")] = {
        auth: slot.getAttribute("data-auth"),
        type: slot.getAttribute("data-type") === "video" ? "video" : "banner"
      };
    });
    var context = bannerPage(slots);
    if (!context) {
      return;
    }
    var start2 = now();
    loadLoader().then(function() {
      var loaderMs = now() - start2;
      var auctionStart = now();
      window.__iriosWooBanners = new window.IriosLoader(Object.assign(adUser(), context, {
        adUnits: Object.keys(units).map(function(id) {
          return { id: Number(id), auth: units[id].auth, type: units[id].type };
        })
      }));
      slots.forEach(function(slot) {
        var script = document.createElement("script");
        script.text = "window.__iriosWooBanners.render(" + Number(slot.getAttribute("data-ad-unit")) + ");";
        debugBanner(slot, start2, loaderMs, auctionStart);
        slot.appendChild(script);
        labelBanner(slot);
      });
    }).catch(function(e) {
      if (window.console) {
        window.console.warn("[irios] banners not available", e);
      }
    });
  }
  function labelBanner(slot) {
    var loader = window.__iriosWooBanners;
    if (!loader || typeof loader.getAds !== "function") {
      return;
    }
    loader.getAds(Number(slot.getAttribute("data-ad-unit"))).then(function(ads) {
      if (!ads || !ads.length || slot.querySelector(".irios-sponsored-label")) {
        return;
      }
      var label = document.createElement("span");
      label.className = "irios-sponsored-label";
      label.textContent = cfg.sponsoredLabel || "Sponsored";
      label.style.cssText = "display:block;white-space:nowrap;text-align:right;font-size:11px;opacity:0.7;margin:0 0 0.25em;box-sizing:border-box;align-self:center;max-width:100%;";
      var creative = ads[0].banner || ads[0].video || {};
      var width = Number(creative.width);
      if (width) {
        label.style.width = width + "px";
      }
      label.appendChild(adInfoButton(ads[0]));
      slot.insertBefore(label, slot.firstChild);
      fitLabelToCreative(slot, label);
    }, function() {
    });
  }
  function fitLabelToCreative(slot, label) {
    var fit = function() {
      var creative = Array.prototype.find.call(slot.querySelectorAll("iframe, img, a, ins, div"), function(el) {
        return !label.contains(el) && !el.contains(label) && el.getBoundingClientRect().width > 0;
      });
      if (!creative) {
        return false;
      }
      label.style.width = Math.round(creative.getBoundingClientRect().width) + "px";
      if (window.ResizeObserver && !label.__iriosResize) {
        label.__iriosResize = new window.ResizeObserver(fit);
        label.__iriosResize.observe(creative);
      }
      return true;
    };
    if (fit() || !window.MutationObserver) {
      return;
    }
    var observer = new window.MutationObserver(function() {
      if (fit()) {
        observer.disconnect();
      }
    });
    observer.observe(slot, { childList: true, subtree: true });
    window.setTimeout(function() {
      observer.disconnect();
    }, 1e4);
  }
  function debugBanner(slot, start2, loaderMs, auctionStart) {
    if (!DEBUG || !window.MutationObserver) {
      return;
    }
    var id = slot.getAttribute("data-ad-unit");
    var renderAt = auctionStart;
    var answeredAt = null;
    var loader = window.__iriosWooBanners;
    if (loader && typeof loader.getAds === "function") {
      loader.getAds(Number(id)).then(function(ads) {
        answeredAt = now();
        if (!ads || !ads.length) {
          observer.disconnect();
          window.clearTimeout(giveUp);
          debug("ad unit " + id + " · banner · Irios answered in " + ms(answeredAt - renderAt) + answerBreakdown(renderAt, answeredAt) + " with no ads");
        }
      }, function() {
      });
    }
    var observer = new window.MutationObserver(function() {
      var painted = Array.prototype.some.call(slot.querySelectorAll("*"), function(el) {
        return el.tagName !== "SCRIPT" && (el.tagName === "IFRAME" || el.tagName === "IMG" || el.offsetHeight > 0);
      });
      if (painted) {
        observer.disconnect();
        window.clearTimeout(giveUp);
        debug(
          "ad unit " + id + " · banner" + (loaderMs > 5 ? " · " + loaderReady(loaderMs) : "") + (answeredAt ? " · Irios answered in " + ms(answeredAt - renderAt) + answerBreakdown(renderAt, answeredAt) + " · rendered in " + ms(now() - answeredAt) : " · Irios answered and rendered in " + ms(now() - renderAt)) + " · ad visible " + ms(now()) + " after the page started loading"
        );
      }
    });
    var giveUp = window.setTimeout(function() {
      observer.disconnect();
      debug("ad unit " + id + " · banner · no ad after " + ms(now() - start2));
    }, 1e4);
    observer.observe(slot, { childList: true, subtree: true, attributes: true });
  }

  // irios-woocommerce/assets/js/src/events-api.mjs
  function enqueue(event) {
    if (state.ready) {
      send(event);
    } else {
      state.queue.push(event);
    }
  }
  function isDuplicate(event) {
    var key = JSON.stringify([event.id, event.value, event.products]);
    var now2 = Date.now();
    var last = state.recent[key];
    Object.keys(state.recent).forEach(function(old) {
      if (now2 - state.recent[old] >= DEDUP_MS) {
        delete state.recent[old];
      }
    });
    state.recent[key] = now2;
    return last !== void 0 && now2 - last < DEDUP_MS;
  }
  function send(event) {
    if (isDuplicate(event)) {
      return;
    }
    var payload = Object.assign({}, event, {
      storeId: cfg.storeId,
      sessionId: state.sessionId,
      uids: state.uids,
      consent: { gdpr: true }
      // only sent with consent
    });
    try {
      new window.IriosEvent([payload]);
    } catch (e) {
      if (window.console) {
        window.console.warn("[irios] event not sent", payload.id, e);
      }
    }
  }
  window.IriosWoo = {
    /** Queues or sends an event ({id, value} or {id, products}). Without consent, it is dropped. */
    track: function(event) {
      if (!hasConsent()) {
        return;
      }
      enqueue(event);
      start();
    },
    context: function() {
      return { ready: state.ready, uids: state.uids.slice(), sessionId: state.sessionId };
    }
  };

  // irios-woocommerce/assets/js/src/events.mjs
  var PENDING_SEARCH_KEY = "irios_pending_search";
  function pageEvents() {
    var events = [{ id: "page-view", value: window.location.pathname }];
    var pending = null;
    try {
      pending = window.sessionStorage.getItem(PENDING_SEARCH_KEY);
      window.sessionStorage.removeItem(PENDING_SEARCH_KEY);
    } catch (e) {
    }
    if (pending) {
      events.push({ id: "search", value: pending });
    }
    if (page.type === "category" && page.value) {
      events.push({ id: "category-view", value: page.value });
    } else if (page.type === "search" && page.value) {
      events.push({ id: "search", value: page.value });
    } else if (page.type === "checkout") {
      events.push({ id: "checkout-step", value: page.value });
    } else if (page.type === "product" && page.product) {
      state.lastVariation = page.variable ? page.product.id : null;
      events.push({ id: "product-view", products: [page.product] });
    }
    return events;
  }
  function onVariation(variation) {
    if (!variation || !variation.variation_id || !page.product) {
      return;
    }
    var id = String(variation.variation_id);
    if (id === state.lastVariation) {
      return;
    }
    state.lastVariation = id;
    var price = Number(variation.display_price);
    window.IriosWoo.track({
      id: "product-view",
      products: [{ id: id, price: isFinite(price) ? price : page.product.price, quantity: 1 }]
    });
  }
  function flushCartEvents() {
    if (!hasConsent()) {
      return;
    }
    if (!state.ready) {
      state.flushWhenReady = true;
      return;
    }
    fetchContext().then(function(ctx) {
      ctx.events.forEach(send);
    });
  }
  if (window.jQuery) {
    window.jQuery(document.body).on("found_variation", "form.variations_form", function(e, variation) {
      onVariation(variation);
    });
    window.jQuery(document.body).on("added_to_cart removed_from_cart updated_cart_totals", flushCartEvents);
  }
  document.body.addEventListener("wc-blocks_added_to_cart", flushCartEvents);
  document.body.addEventListener("wc-blocks_removed_from_cart", flushCartEvents);
  function watchBlocksCart() {
    var data = window.wp && window.wp.data;
    if (!data || typeof data.select !== "function" || !data.select("wc/store/cart")) {
      return;
    }
    var signature = function() {
      var cart = data.select("wc/store/cart").getCartData();
      return (cart && cart.items ? cart.items : []).map(function(item) {
        return item.key + ":" + item.quantity;
      }).join("|");
    };
    var last = signature();
    var timer = null;
    data.subscribe(function() {
      var current = signature();
      if (current === last) {
        return;
      }
      last = current;
      window.clearTimeout(timer);
      timer = window.setTimeout(flushCartEvents, 300);
    });
  }
  if (document.readyState === "complete") {
    watchBlocksCart();
  } else {
    window.addEventListener("load", watchBlocksCart);
  }

  // irios-woocommerce/assets/js/src/push.mjs
  var PUSH_PAGES_KEY = "irios_push_pages";
  var PUSH_VISIT_KEY = "irios_push_visit";
  var PUSH_ASKED_KEY = "irios_push_asked";
  var DAY_MS = 24 * 3600 * 1e3;
  function stored(storage, key) {
    try {
      return window[storage].getItem(key);
    } catch (e) {
      return null;
    }
  }
  function store(storage, key, value) {
    try {
      window[storage].setItem(key, value);
    } catch (e) {
    }
  }
  function pushSupported() {
    return "serviceWorker" in navigator && "PushManager" in window && "Notification" in window;
  }
  function pushOnPage() {
    var push = cfg.push;
    if (!push || !hasConsent() || !pushSupported()) {
      return;
    }
    try {
      var pages = Number(stored("sessionStorage", PUSH_PAGES_KEY) || 0) + 1;
      store("sessionStorage", PUSH_PAGES_KEY, String(pages));
      if (window.Notification.permission === "denied" || stored("sessionStorage", PUSH_VISIT_KEY)) {
        return;
      }
      var asked = Number(stored("localStorage", PUSH_ASKED_KEY) || 0);
      if (window.Notification.permission === "default" && Date.now() - asked < Number(push.repeatDays) * DAY_MS) {
        return;
      }
      var moment = push.trigger === "after_pages" ? pages >= Number(push.afterPages) : page.pageType === "CONFIRMATION";
      if (!moment) {
        return;
      }
      store("sessionStorage", PUSH_VISIT_KEY, "1");
      if (window.Notification.permission === "default") {
        store("localStorage", PUSH_ASKED_KEY, String(Date.now()));
      }
      navigator.serviceWorker.register(push.serviceWorker, { scope: push.scope }).then(function() {
        return loadLoader();
      }).then(function() {
        if (!window.IriosLoader || !hasConsent()) {
          return;
        }
        window.__iriosWooPush = new window.IriosLoader(Object.assign(adUser(), {
          adUnits: [{ id: Number(push.id), auth: push.auth, type: "push" }]
        }));
        var script = document.createElement("script");
        script.text = "window.__iriosWooPush && window.__iriosWooPush.render(" + Number(push.id) + ");";
        document.body.appendChild(script);
      }).catch(function() {
      });
    } catch (e) {
    }
  }
  function cancelPush() {
    if (!cfg.push || !("serviceWorker" in navigator)) {
      return;
    }
    try {
      navigator.serviceWorker.getRegistration(cfg.push.serviceWorker).then(function(registration) {
        return registration && registration.pushManager.getSubscription();
      }).then(function(subscription) {
        return subscription && subscription.unsubscribe();
      }).catch(function() {
      });
    } catch (e) {
    }
  }

  // irios-woocommerce/assets/js/src/tracker.mjs
  function loadScript(src) {
    return new Promise(function(resolve, reject) {
      var script = document.createElement("script");
      script.src = src;
      script.async = true;
      script.onload = resolve;
      script.onerror = reject;
      document.head.appendChild(script);
    });
  }
  function loadLoader() {
    if (!state.loaderPromise) {
      state.loaderPromise = window.IriosLoader ? Promise.resolve() : loadScript("https://" + cfg.jsDomain + "/v1/irios-loader.js");
    }
    return state.loaderPromise;
  }
  function revealResults() {
    document.documentElement.classList.remove("irios-ads-pending");
  }
  function loadTracker() {
    if (!state.trackerPromise) {
      state.trackerPromise = window.IriosEvent ? Promise.resolve() : loadScript("https://" + cfg.jsDomain + "/v1/irios-tracker.js");
    }
    return state.trackerPromise;
  }
  function start() {
    if (state.started || !hasConsent()) {
      return;
    }
    state.started = true;
    state.sessionId = sessionId();
    pageEvents().forEach(enqueue);
    if (page.ads || document.querySelector(".irios-banner[data-ad-unit]")) {
      loadLoader().catch(function() {
      });
    }
    var anonymous = !document.body.classList.contains("logged-in");
    state.uids = [];
    if (anonymous) {
      renderAds();
    }
    Promise.all([fetchContext(), loadTracker()]).then(function(results) {
      if (!state.started) {
        return;
      }
      state.uids = results[0].uids;
      state.ready = true;
      var pending = state.queue.concat(results[0].events);
      state.queue = [];
      pending.forEach(send);
      if (state.flushWhenReady) {
        state.flushWhenReady = false;
        flushCartEvents();
      }
      if (!anonymous) {
        renderAds();
      }
      pushOnPage();
      document.dispatchEvent(new CustomEvent("irios:ready", { detail: { uids: state.uids, sessionId: state.sessionId } }));
    }).catch(function() {
      revealResults();
      state.started = false;
      state.queue = [];
    });
  }
  function renderAds() {
    renderSearchAds();
    renderBanners();
  }
  function stop() {
    state.started = false;
    state.ready = false;
    state.queue = [];
    state.uids = [];
    state.recent = {};
    state.lastVariation = null;
    try {
      ["irs1P", "irsUserData"].forEach(function(name) {
        document.cookie = name + "=; Max-Age=0; Path=/";
      });
      window.sessionStorage.removeItem(SESSION_KEY);
    } catch (e) {
    }
    cancelPush();
  }

  // irios-woocommerce/assets/js/src/live-search.mjs
  var live = { timer: null, prefetch: null, ads: {}, typedInput: null, watch: null, found: false };
  var NOT_RESULTS_SELECTOR = selector("notResults", '[class*="mini-cart"], [class*="minicart"], .widget_shopping_cart, .woocommerce-mini-cart, .cart_list');
  var LIVE_HOLD_MS = 600;
  var SEARCH_INPUT = 'input[name="s"], input[type="search"]';
  var LIVE_DROPDOWN_SELECTOR = selector(
    "liveDropdown",
    '[class*="suggestion"], [class*="autocomplete"], [class*="search-result"], [class*="live-search"], [class*="ajax-search"]'
  );
  function liveSearchEnabled() {
    return !!cfg.liveSearchAds && (hasConsent() ? state.ready : true);
  }
  function requestLiveAds(term) {
    var timing = {};
    var pending = requestAds(cfg.liveSearchAds, "SEARCH_BAR", term, "data", timing).catch(function() {
      return null;
    });
    pending.timing = timing;
    return pending;
  }
  function prefetchLiveAds(term) {
    live.ads = {};
    live.ads[term] = requestLiveAds(term);
  }
  function takeLiveAds(term) {
    var pending = live.ads[term] || requestLiveAds(term);
    delete live.ads[term];
    return pending;
  }
  function isSearchInput(el) {
    return el instanceof Element && el.matches(SEARCH_INPUT);
  }
  document.addEventListener(
    "focusin",
    function(e) {
      if (isSearchInput(e.target) && liveSearchEnabled()) {
        loadLoader().catch(function() {
        });
      }
    },
    true
  );
  document.addEventListener(
    "input",
    function(e) {
      if (!isSearchInput(e.target) || !liveSearchEnabled()) {
        return;
      }
      live.typedInput = e.target;
      watchLiveSearch(e.target);
      var term = e.target.value.trim();
      window.clearTimeout(live.prefetch);
      window.clearTimeout(live.watch);
      if (term.length >= 3 && !live.found) {
        live.watch = window.setTimeout(function() {
          if (!live.found) {
            warnTheme("live-search", "live search: no dropdown with product links found next to the search field, no ads shown there");
          }
        }, 2500);
      }
      if (term) {
        live.prefetch = window.setTimeout(function() {
          prefetchLiveAds(term);
        }, 250);
      }
    },
    true
  );
  function holdList(container) {
    container.style.transition = "none";
    container.style.opacity = "0";
  }
  function releaseList(container) {
    container.style.transition = "opacity .15s ease-in";
    container.style.opacity = "";
  }
  function isProductLink(a) {
    try {
      var u = new URL(a.href, window.location.href);
      return u.host === window.location.host && u.pathname.indexOf(cfg.productBase || "/product/") === 0;
    } catch (e) {
      return false;
    }
  }
  function searchScope(el) {
    var scope = el.closest('form, [class*="search"]');
    while (scope && scope !== document.body && scope.tagName !== "MAIN") {
      var input = scope.querySelector('input[name="s"], input[type="search"]');
      if (input) {
        return { el: scope, input: input };
      }
      scope = scope.parentElement && scope.parentElement.closest('form, [class*="search"]');
    }
    var dropdown = el.closest(LIVE_DROPDOWN_SELECTOR);
    if (dropdown && live.typedInput && live.typedInput.value.trim()) {
      return { el: dropdown, input: live.typedInput };
    }
    return null;
  }
  document.addEventListener(
    "click",
    function(e) {
      var a = e.target instanceof Element ? e.target.closest("a[href]") : null;
      var scope = a && isProductLink(a) ? searchScope(a) : null;
      var term = scope ? scope.input.value.trim() : "";
      if (term && hasConsent()) {
        try {
          window.sessionStorage.setItem(PENDING_SEARCH_KEY, term);
        } catch (err) {
        }
      }
    },
    true
  );
  function scanLiveSearch() {
    if (!liveSearchEnabled()) {
      return;
    }
    if (!live.typedInput) {
      return;
    }
    var links = Array.prototype.filter.call(document.querySelectorAll("a[href]"), function(a) {
      return isProductLink(a) && a.offsetParent !== null && !a.closest(".irios-sponsored, [data-irios-ads]") && !a.closest(NOT_RESULTS_SELECTOR);
    });
    var scope = null;
    var inScope = links.filter(function(a) {
      var s2 = searchScope(a);
      if (s2 && s2.input === live.typedInput && s2.input.value.trim() && (!scope || scope.el === s2.el)) {
        scope = s2;
        return true;
      }
      return false;
    });
    if (!scope || !inScope.length) {
      return;
    }
    var container = inScope[0].parentElement;
    var other = inScope.find(function(a) {
      return normalizeUrl(a.href) !== normalizeUrl(inScope[0].href);
    });
    if (other) {
      while (container && !container.contains(other)) {
        container = container.parentElement;
      }
    } else {
      var node = inScope[0];
      while (node.parentElement && node.parentElement.children.length < 2 && node.parentElement !== scope.el) {
        node = node.parentElement;
      }
      container = node.parentElement;
    }
    var term = scope.input.value.trim();
    var template = container && itemOf(container, inScope[0]);
    if (!container || container.getAttribute("data-irios-live-term") === term && container.__iriosRow && container.__iriosRow.parentElement === container) {
      return;
    }
    container.setAttribute("data-irios-live-term", term);
    live.found = true;
    Array.prototype.forEach.call(container.querySelectorAll(":scope > .irios-sponsored"), function(el) {
      el.remove();
    });
    Array.prototype.forEach.call(container.querySelectorAll(":scope > [data-irios-hidden]"), function(el) {
      el.style.display = "";
      el.removeAttribute("data-irios-hidden");
    });
    container.__iriosRow = template;
    var templateUrl = normalizeUrl(inScope[0].href);
    var titleText = resultTitle(template, templateUrl);
    holdList(container);
    var hold = window.setTimeout(function() {
      releaseList(container);
    }, LIVE_HOLD_MS);
    var pending = takeLiveAds(term);
    pending.then(function(result) {
      window.clearTimeout(hold);
      var where = "live search " + JSON.stringify(term);
      if (!result) {
        debugTiming(unitsLabel(cfg.liveSearchAds.units), where, pending.timing, false);
        return;
      }
      if (container.getAttribute("data-irios-live-term") !== term || container.__iriosRow !== template || !container.isConnected) {
        return;
      }
      var byId = {};
      result.cards.forEach(function(card) {
        byId[card.id] = card;
      });
      var items = [];
      result.placements.forEach(function(p) {
        var card = byId[String(nativeOf(p.response).product.id)];
        if (!card) {
          return;
        }
        items.push({ item: cloneResult(template, templateUrl, titleText, card), placement: p });
        Array.prototype.forEach.call(container.children, function(row) {
          var link = row.querySelector("a[href]");
          if (link && !row.classList.contains("irios-sponsored") && Number(card.productId) && isSameProduct(link.href, card)) {
            row.style.display = "none";
            row.setAttribute("data-irios-hidden", "1");
          }
        });
      });
      items.forEach(function(entry) {
        placeAt(container, entry.item, entry.placement.position);
        addLabel(entry.item, entry.placement.response);
        trackAd(entry.item, entry.placement.response);
      });
      debugTiming(unitsLabel(cfg.liveSearchAds.units), where, pending.timing, true);
    }).catch(function() {
    }).then(function() {
      releaseList(container);
    });
  }
  function isSameProduct(href, card) {
    return normalizeUrl(href) === normalizeUrl(card.link);
  }
  function watchLiveSearch(input) {
    if (!window.MutationObserver || live.observer) {
      return;
    }
    live.observer = new window.MutationObserver(function() {
      window.clearTimeout(live.timer);
      live.timer = window.setTimeout(scanLiveSearch, 50);
    });
    var scope = input.closest("form");
    scope = scope && scope.parentElement || input.parentElement || document.body;
    live.observer.observe(scope, { childList: true, subtree: true });
  }

  // irios-woocommerce/assets/js/src/startup.mjs
  if (!isConfigured()) {
  } else {
    document.addEventListener("wp_consent_type_defined", start);
    if (cfg.consentMode === "asesor_cookies") {
      lastConsent = asesorConsent();
      window.setInterval(function() {
        var current = asesorConsent();
        if (current === lastConsent) {
          return;
        }
        lastConsent = current;
        if (current) {
          start();
        } else {
          stop();
        }
      }, 1e3);
    }
    document.addEventListener("wp_listen_for_consent_change", function(e) {
      var changes = e.detail || {};
      if (changes[cfg.consentCategory] === "allow") {
        start();
      } else if (changes[cfg.consentCategory] === "deny") {
        stop();
      }
    });
    if (hasConsent()) {
      start();
    } else {
      renderAds();
    }
  }
  var lastConsent;
})();
