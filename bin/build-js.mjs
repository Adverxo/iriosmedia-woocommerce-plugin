// Builds the storefront JS from assets/js/src into assets/js/irios-woocommerce.js, which is the file WordPress
// serves. One classic script, as before: WordPress loads it with a plain <script>, not a module.
//
// The built file is committed, so the Docker store (which mounts the sources) and the zip serve the same thing.
import { build } from "esbuild";
import { readFileSync, writeFileSync } from "node:fs";

const out = new URL("../irios-woocommerce/assets/js/irios-woocommerce.js", import.meta.url);

await build({
  entryPoints: [new URL("../irios-woocommerce/assets/js/src/index.mjs", import.meta.url).pathname],
  bundle: true,
  format: "iife",
  // The sources are ES5 in everything but import/export, and the published file stays ES5 too
  target: "es5",
  charset: "utf8",
  legalComments: "inline",
  outfile: out.pathname,
});

// Same header the file had before it was split, so whoever opens it in the browser knows where it comes from
const header = `/**
 * IRIOS Media for WooCommerce — the storefront JS.
 *
 * DO NOT EDIT: built from assets/js/src (npm run build:js). Each part is a module of its own in that folder:
 * config, consent, identity, tracker, events, search-ads, ad-info, display, push, live-search and startup.
 */
`;
writeFileSync(out, header + readFileSync(out, "utf8"));
