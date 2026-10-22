# Development

Internal notes for working on the plugin: repository layout, builds and releases, tests, the Docker test stores, the
theme compatibility matrix and translations. The [README](../README.md) is for the stores that install the plugin.

## Repository layout

```
├── irios-woocommerce/              # the plugin (what gets installed in WordPress)
│   ├── irios-woocommerce.php       # plugin header (version 0.0.0-dev: package.sh sets it), autoload
│   ├── src/                        # PHP: settings, events, identity, consent, banners, REST, update notice…
│   ├── assets/js/irios-woocommerce.js  # storefront JS (events, ads, banners, live search, ad info)
│   ├── languages/                  # .pot template and translations
│   ├── tests/                      # PHPUnit (and tests/js: vitest)
│   └── uninstall.php               # on delete: removes the plugin settings
├── bin/
│   ├── package.sh                  # builds the installable zip
│   ├── release-notes.sh            # CHANGELOG.md section of a version (release notes)
│   ├── setup-store.sh              # Docker test store from scratch
│   ├── setup-zip-store.sh          # second Docker store that installs the plugin from a zip
│   ├── import-store.sh             # imports a WXR export of a real store
│   └── anonymize-wxr.py            # anonymises orders and customers in the export before importing it
├── compat/                         # theme compatibility matrix (Playwright)
├── e2e/                            # end-to-end flow (Playwright)
├── dev/                            # development only: IRIOS Media simulator and import helpers
├── docs/                           # this file
└── docker-compose.yml              # WordPress + MariaDB + wp-cli
```

## Building the plugin

```bash
bin/package.sh                              # → dist/irios-woocommerce-<version>.zip
```

The zip has Composer's production dependencies (`giggsey/libphonenumber-for-php-lite`, for E.164 phone numbers), the
license and no tests. It uses `composer` if installed and otherwise the `composer:2` Docker image.

`package.sh` writes the version into the header (`Version:`) and `IRIOS_WC_VERSION` of the copy that goes into the
zip; the source code keeps `0.0.0-dev`. The version comes from `git describe`: on a tag, `1.0.6`; after it,
`1.0.6-3-g<hash>` (3 commits after the tag), with `.dirty` if there are uncommitted changes; without tags,
`0.0.0-<hash>`. Package from a tag whatever goes to a store: only `X.Y.Z` versions get the "new version" notice.

## Versions and releases

Versions are this repository's **git tags** (`X.Y.Z`, without `v`). To publish one:

1. Add the version's section at the top of [`CHANGELOG.md`](../CHANGELOG.md), written for the stores (what changes
   for them, not commit messages): `## 1.0.6 - YYYY-MM-DD`, then the changes. It becomes the release notes, which the
   update notice in the stores links to. Check it with `bin/release-notes.sh 1.0.6`.
2. Commit and push, then tag:

```bash
git tag 1.0.6 && git push origin main 1.0.6
```

The [Release workflow](../.github/workflows/release.yml) runs the PHP tests (PHP 8.2 and 8.4, plus a syntax check on
PHP 8.1), checks that `CHANGELOG.md` has notes for the version (no notes, no release), builds the zip with
`bin/package.sh` and publishes a GitHub Release with those notes and two assets:
`irios-woocommerce-1.0.6.zip` and `irios-woocommerce.zip`. The latter always has the same name, so this link always
downloads the latest version:

```
https://github.com/Adverxo/iriosmedia-woocommerce-plugin/releases/latest/download/irios-woocommerce.zip
```

If the workflow fails, delete the tag, fix and tag again:

```bash
git tag -d 1.0.6 && git push origin :refs/tags/1.0.6
```

Installed stores learn about the release through the update notice (`src/Updates.php`): GitHub's
`releases/latest` API, cached for 12 hours. The repository must be public for it to work.

The version is shown in **Plugins**, in the settings header (**WooCommerce → Settings → Integration → Irios Media**)
and, with `?irios_debug=1`, in the browser console (`[irios-woo] Irios for WooCommerce <version>`).

## Tests

```bash
cd irios-woocommerce
composer install
vendor/bin/phpunit
```

They run without WordPress: pure logic is tested directly, and the code that calls WordPress/WooCommerce (consent,
cart events, the context endpoint, the order uid, translatable settings errors…) with
[Brain Monkey](https://github.com/Brain-WP/BrainMonkey) and the minimal doubles in `tests/doubles.php` (base class
`tests/WordPressTestCase.php`).

The storefront JS (`assets/js/irios-woocommerce.js`) is tested as published, in a simulated store page (jsdom) with
doubles of IRIOS Media and WordPress that record what they receive: events and consent, cart events, deduplication, ad
positions, hidden duplicates, ad label and tracking, and contextual auctions without consent
(`irios-woocommerce/tests/js/`). From the repository root, with Node.js 20+:

```bash
npm install
npm test
```

## Docker test store

```bash
docker compose up -d
SAMPLE_DATA=no bin/setup-store.sh                        # WordPress + WooCommerce + plugin, no products
python3 -I bin/anonymize-wxr.py export.xml anon.xml      # anonymises the export of a real store
bin/import-store.sh anon.xml --with-images               # imports catalog, pages, menus and orders
```

`bin/setup-store.sh` alone (without `SAMPLE_DATA=no`) creates a store with sample products. Store exports and dumps
(`*.xml`, `*.sql`) are git-ignored: never commit them.

- Dashboard: `http://localhost/wp-admin` (admin / admin).
- **The plugin is not installed or updated in the Docker store**: its folder is this repository's
  `irios-woocommerce/`, mounted read-only. Code changes show up on reload. Do not upload a zip from **Plugins**:
  WordPress would try to delete the current plugin.
- **IRIOS Media simulator** instead of a real network: copy `dev/irios-stub.php` as a must-use plugin
  (`docker compose cp dev/irios-stub.php wordpress:/var/www/html/wp-content/mu-plugins/irios-stub.php`). It replaces
  the IRIOS Media scripts, answers with products from the store itself and test banners, and logs everything to the
  console. With `irios_ads=13,10` in the URL it advertises those catalog ids.
- **Against a real IRIOS Media network**: the store domain (without port) is validated, so the store must be served on
  the domain registered in IRIOS Media. Whatever is done in the local store (visits, cart) arrives as real events.

### Testing the zip as in a real store

A second store at `http://localhost:8089` (admin / admin), with its own database and **without** the mounted code: the
plugin is installed and updated by uploading the zip, as in a real store.

```bash
docker compose --profile zip up -d
bin/setup-zip-store.sh                                    # WordPress + WooCommerce + two products
bin/setup-zip-store.sh dist/irios-woocommerce-1.0.6.zip   # and installs or replaces the plugin with that zip
```

Or upload the zip from **Plugins → Add New → Upload Plugin**. To stop it: `docker compose --profile zip stop`.

### End-to-end flow

A visitor browses, searches, adds to the cart (from the ad, by AJAX, and from the product page) and buys, with and
without consent, in a separate Docker store (`irios-compat`, Storefront, simulator). Playwright checks what IRIOS Media
receives:

- **With consent**: every store event (page-view, category-view, search, product-view, add-to-cart, cart-view,
  checkout-step) once, with its products, price and quantity, the store id, one session and `consent.gdpr = true`;
  never a purchase from the browser; personalised auctions and ad tracking. The order keeps the browser identifier
  (`_irios_uid`).
- **Without consent**: no events, contextual auctions (`consent.gdpr = false`, no uids or session), and no identifier
  in the order.

```bash
e2e/run.sh   # needs port 80 to be free (docker compose stop)
```

## Theme compatibility matrix

The README table is generated by the compatibility matrix, which runs in a separate Docker store (`irios-compat`) with
the simulator and Playwright, and updates `compat/RESULTS.md` (with warnings and errors) and the table in the README:

```bash
compat/run.sh                  # every theme in compat/themes.txt
compat/run.sh astra kadence    # only those
```

- To test another theme, add its wordpress.org slug to `compat/themes.txt` (optionally followed by a search plugin
  slug) and, if everything passes, to `Compatibility::SUPPORTED_THEMES`. That list drives the "unsupported theme"
  warning in the settings.
- Themes that `run.sh` cannot install (premium themes) are checked by hand with `compat/compat.spec.mjs` and their
  result stored in `compat/manual/<theme>.json`, same format; `compat/report.py` merges them into the table.
- In debug mode (`?irios_debug=1`) the console says when the plugin could not find where to place an ad on a theme,
  e.g. `[irios-woo] live search: no dropdown with product links found next to the search field, no ads shown there`.

## Translations

The plugin is written in English and ships a Spanish translation (`es_ES`) in `irios-woocommerce/languages/`. Any
other language shows the English texts.

To add a language, translate `irios-woocommerce.pot` (e.g. with Poedit) into `irios-woocommerce-<locale>.po` and
compile it to `.mo`. After changing texts in the code, update the template and the translations (GNU gettext):

```bash
cd irios-woocommerce
xgettext --language=PHP --from-code=UTF-8 --keyword=__ --keyword=_e --keyword=esc_html__ --keyword=esc_html_e \
  --keyword=esc_attr__ --keyword=esc_attr_e --add-comments=translators --no-wrap \
  -o languages/irios-woocommerce.pot src/*.php irios-woocommerce.php uninstall.php
msgmerge --update --no-wrap languages/irios-woocommerce-es_ES.po languages/irios-woocommerce.pot
msgfmt -o languages/irios-woocommerce-es_ES.mo languages/irios-woocommerce-es_ES.po
```

The plugin description in the header is not picked up by `xgettext`: keep its entry at the end of the `.pot`.

## Legacy settings

Installs from before the positions table (one search ad unit and "Maximum ads") keep working: until the settings are
saved, that ad unit takes positions 1…maximum, and the table shows up already filled with them.
