#!/usr/bin/env bash
# End-to-end flow: a visitor browses, searches, adds to the cart and buys in the Docker store (project irios-compat,
# Storefront, Irios simulator), with and without consent. Playwright checks what Irios receives (e2e/flow.spec.mjs);
# this script then checks the browser identifier saved in each order.
#
#   e2e/run.sh
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
wp theme is-installed storefront || wp theme install storefront >/dev/null
wp theme activate storefront >/dev/null
wp wc payment_gateway update cod --enabled=true --user=admin >/dev/null

echo "==> Irios simulator and test settings (consent from the \"Asesor de Cookies RGPD\" banner cookie)"
docker compose exec -T wordpress mkdir -p /var/www/html/wp-content/mu-plugins
docker compose cp dev/irios-stub.php wordpress:/var/www/html/wp-content/mu-plugins/irios-stub.php
SETTINGS=$(python3 -I -c '
import json
print(json.dumps({
    "enabled": "yes", "js_domain": "tracker.irios.test", "store_id": "1234", "consent_mode": "asesor_cookies",
    "sponsored_enabled": "yes", "sponsored_label": "Patrocinado", "keep_single_result": "yes",
    "sponsored_slots": json.dumps([{"position": 1, "id": 9001, "auth": "e2e"}]),
    "banners_enabled": "no",
}))')
wp option update woocommerce_irios_settings --format=json "$SETTINGS" >/dev/null

SIMPLE_ID=$(wp post list --post_type=product --name=stick-bauer-vapor --field=ID)
export SIMPLE_ID
# Each run buys 4 units: stock back up so that "Add to cart" is always there
wp wc product update "$SIMPLE_ID" --stock_quantity=100 --user=admin >/dev/null
export SEARCH_URL="http://localhost/?s=stick&post_type=product&irios_ads=$SIMPLE_ID"
CATEGORY_URL="$(wp eval 'echo get_term_link( get_term_by( "name", "Sticks", "product_cat" ) );')"
PRODUCT_URL="$(wp post list --post_type=product --name=stick-bauer-vapor --field=url)"
CART_URL="$(wp eval 'echo wc_get_cart_url();')"
CHECKOUT_URL="$(wp eval 'echo wc_get_checkout_url();')"
export CATEGORY_URL PRODUCT_URL CART_URL CHECKOUT_URL CATEGORY_PATH="Sticks"

NET=$(docker inspect "$(docker compose ps -q wordpress)" --format '{{range $k, $v := .NetworkSettings.Networks}}{{$k}}{{end}}')
pw() {
    docker run --rm --network "$NET" -v "$PWD/e2e:/e2e" -w /e2e \
        -e SIMPLE_ID -e SEARCH_URL -e CATEGORY_URL -e PRODUCT_URL -e CART_URL -e CHECKOUT_URL -e CATEGORY_PATH \
        "mcr.microsoft.com/playwright:v$PW_VERSION-noble" "$@"
}
[[ -d e2e/node_modules/@playwright/test ]] || pw npm install --no-save --no-audit --no-fund "@playwright/test@$PW_VERSION" >/dev/null

rm -rf e2e/results
echo "==> Playwright"
status=0
pw npx playwright test || status=1

echo "==> Browser identifier saved in the orders"
if [[ -f e2e/results/orders.json ]]; then
    read -r uuid consent_order no_consent_order < <(python3 -I -c '
import json, sys
o = json.load(open(sys.argv[1]))
print(o["uuid"], o.get("consent", 0), o.get("noConsent", 0))' e2e/results/orders.json)
    meta() { wp eval "\$o = wc_get_order( $1 ); echo \$o ? (string) \$o->get_meta( '_irios_uid' ) : 'no order';"; }
    got=$(meta "$consent_order")
    if [[ "$got" == "$uuid" ]]; then echo "ok: order $consent_order (consent) has _irios_uid"; else echo "FAIL: order $consent_order (consent): _irios_uid='$got'"; status=1; fi
    got=$(meta "$no_consent_order")
    if [[ -z "$got" ]]; then echo "ok: order $no_consent_order (no consent) has no _irios_uid"; else echo "FAIL: order $no_consent_order (no consent): _irios_uid='$got'"; status=1; fi
else
    echo "FAIL: no orders (e2e/results/orders.json)"
    status=1
fi
exit $status
