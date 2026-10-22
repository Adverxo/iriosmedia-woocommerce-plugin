#!/usr/bin/env bash
# WordPress theme compatibility matrix: in its own Docker store (project irios-compat, with sample products and the
# Irios simulator), activates each theme in compat/themes.txt and checks with Playwright where the ads show up.
# Results in compat/RESULTS.md and in the README table.
#
#   compat/run.sh                 # every theme
#   compat/run.sh astra kadence   # only those
#
# Needs port 80 to be free (stop the usual test store first: docker compose stop).
set -euo pipefail
cd "$(dirname "$0")/.."
export COMPOSE_PROJECT_NAME=irios-compat
PW_VERSION=1.48.2
wp() { docker compose run --rm -T cli wp "$@"; }

echo "==> Test store (project $COMPOSE_PROJECT_NAME)"
docker compose up -d
bin/setup-store.sh >/dev/null

echo "==> Irios simulator and test settings"
docker compose exec -T wordpress mkdir -p /var/www/html/wp-content/mu-plugins
docker compose cp dev/irios-stub.php wordpress:/var/www/html/wp-content/mu-plugins/irios-stub.php
SETTINGS=$(python3 -I -c '
import json
print(json.dumps({
    "enabled": "yes", "js_domain": "tracker.irios.test", "store_id": "1234", "consent_mode": "always",
    "sponsored_enabled": "yes", "sponsored_label": "Patrocinado", "keep_single_result": "yes",
    "sponsored_slots": json.dumps([{"position": 1, "id": 9001, "auth": "compat"}]),
    "banners_enabled": "yes",
    "banners": json.dumps([
        {"tag": "listado", "id": 9101, "auth": "compat", "position": "category_after_products"},
        {"tag": "ficha", "id": 9102, "auth": "compat", "position": "product_before_description"},
    ]),
}))')
wp option update woocommerce_irios_settings --format=json "$SETTINGS" >/dev/null

DEBUG="irios_debug=1"
# The simulator advertises the simple product (irios_ads), so that "Add to cart" can be tested on the ad
SIMPLE_ID=$(wp post list --post_type=product --name=stick-bauer-vapor --field=ID)
export SEARCH_URL="http://localhost/?s=stick&post_type=product&irios_ads=$SIMPLE_ID&$DEBUG"
CATEGORY_URL="$(wp eval 'echo get_term_link( get_term_by( "name", "Sticks", "product_cat" ) );')?$DEBUG"
PRODUCT_URL="$(wp post list --post_type=product --name=stick-bauer-vapor --field=url)?$DEBUG"
BASE_PAGE_URL="$(wp eval 'echo wc_get_page_permalink( "myaccount" );')?$DEBUG"
export CATEGORY_URL PRODUCT_URL

# Search form shortcode of each plugin in compat/themes.txt
shortcode_of() {
    case "$1" in
        ajax-search-for-woocommerce) echo "[fibosearch]" ;;
        yith-woocommerce-ajax-search) echo "[yith_woocommerce_ajax_search]" ;;
        *) echo "" ;;
    esac
}

NET=$(docker inspect "$(docker compose ps -q wordpress)" --format '{{range $k, $v := .NetworkSettings.Networks}}{{$k}}{{end}}')
pw() {
    docker run --rm --network "$NET" -v "$PWD/compat:/compat" -w /compat \
        -e COMBO -e SEARCH_URL -e CATEGORY_URL -e PRODUCT_URL -e PAGE_URL -e SEARCH_INPUT \
        "mcr.microsoft.com/playwright:v$PW_VERSION-noble" "$@"
}
[[ -d compat/node_modules/@playwright/test ]] || pw npm install --no-save --no-audit --no-fund "@playwright/test@$PW_VERSION" >/dev/null

mkdir -p compat/results
# The list is read from fd 3: docker (wp, Playwright) reads stdin and would swallow the lines
while read -r theme plugin <&3; do
    [[ -z "$theme" || "$theme" == \#* ]] && continue
    if [[ $# -gt 0 && ! " $* " =~ " $theme " ]]; then continue; fi
    export COMBO="$theme${plugin:++$plugin}"
    echo "==> $COMBO"
    wp theme is-installed "$theme" || wp theme install "$theme" >/dev/null
    wp theme activate "$theme" >/dev/null
    PAGE_URL=$BASE_PAGE_URL
    SEARCH_INPUT=""
    PAGE_ID=""
    if [[ -n "${plugin:-}" ]]; then
        wp plugin install "$plugin" --activate >/dev/null
        # Search plugins do not replace the header search on their own: their form goes on a page, via its shortcode
        shortcode=$(shortcode_of "$plugin")
        if [[ -n "$shortcode" ]]; then
            PAGE_ID=$(wp post create --post_type=page --post_status=publish --post_title="Buscador $plugin" \
                --post_content="$shortcode" --porcelain)
            PAGE_URL="$(wp post list --post_type=page --post__in="$PAGE_ID" --field=url)?$DEBUG"
            SEARCH_INPUT='main input:not([type="hidden"]):visible, .entry-content input:not([type="hidden"]):visible'
        fi
    fi
    export PAGE_URL SEARCH_INPUT
    rm -f "compat/results/$COMBO.json"
    pw npx playwright test || true
    if [[ -n "${plugin:-}" ]]; then
        [[ -n "$PAGE_ID" ]] && wp post delete "$PAGE_ID" --force >/dev/null
        wp plugin deactivate "$plugin" >/dev/null
    fi
done 3< compat/themes.txt

python3 -I compat/report.py compat/results compat/RESULTS.md README.md
