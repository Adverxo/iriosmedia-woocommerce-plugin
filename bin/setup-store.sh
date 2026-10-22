#!/usr/bin/env bash
# Sets up the Docker test store: WordPress + WooCommerce + WP Consent API + sample data + the plugin configured.
# Idempotent as far as possible: if the store is already installed, it only reactivates and reconfigures.
# SAMPLE_DATA=no: no sample products (to import a real store afterwards with bin/import-store.sh).
set -euo pipefail
cd "$(dirname "$0")/.."

wp() { docker compose run --rm -T cli wp "$@"; }

echo "==> Waiting for WordPress"
for _ in $(seq 1 60); do
    if docker compose exec -T wordpress test -f /var/www/html/wp-config.php 2>/dev/null; then break; fi
    sleep 2
done
until wp db check >/dev/null 2>&1; do sleep 2; done

if ! wp core is-installed 2>/dev/null; then
    echo "==> Installing WordPress"
    wp core install --url=http://localhost --title="Tienda Irios" --admin_user=admin --admin_password=admin \
        --admin_email=admin@example.com --skip-email
    wp language core install es_ES --activate || true
fi

echo "==> Plugins"
wp plugin install woocommerce --activate
wp plugin install wp-consent-api --activate
wp plugin activate irios-woocommerce

echo "==> Basic WooCommerce settings"
wp option update woocommerce_store_address "Calle Falsa 1"
wp option update woocommerce_default_country "ES:M"
wp option update woocommerce_currency "EUR"
wp option update woocommerce_prices_include_tax "yes"
wp option update woocommerce_coming_soon "no"
wp rewrite structure '/%postname%/' --hard

if [[ "${SAMPLE_DATA:-yes}" != "no" && $(wp wc product list --user=admin --format=count) -eq 0 ]]; then
    echo "==> Sample data"
    STICKS=$(wp wc product_cat create --user=admin --name="Sticks" --porcelain)
    SR=$(wp wc product_cat create --user=admin --name="SR Sticks" --parent="$STICKS" --porcelain)
    JUGADOR=$(wp wc product_cat create --user=admin --name="Jugador" --porcelain)
    CAMIS=$(wp wc product_cat create --user=admin --name="Camisetas" --parent="$JUGADOR" --porcelain)
    PROMO=$(wp wc product_cat create --user=admin --name="Promociones" --porcelain)
    BAUER=$(wp wc product_tag create --user=admin --name="bauer" --porcelain)
    ALKALI=$(wp wc product_tag create --user=admin --name="ALKALI" --porcelain)

    # Simple product
    wp wc product create --user=admin --name="Stick Bauer Vapor" --type=simple --regular_price=199.99 \
        --categories="[{\"id\":$SR},{\"id\":$PROMO}]" --tags="[{\"id\":$BAUER}]" --manage_stock=true --stock_quantity=5 >/dev/null

    # Variable WITH a default variation (size JR L/XL)
    V1=$(wp wc product create --user=admin --name="Camiseta Protección Alkali JR" --type=variable \
        --categories="[{\"id\":$CAMIS}]" --tags="[{\"id\":$ALKALI}]" \
        --attributes='[{"name":"TALLA","visible":true,"variation":true,"options":["JR S/M","JR L/XL"]}]' \
        --default_attributes='[{"name":"TALLA","option":"JR L/XL"}]' --porcelain)
    wp wc product_variation create "$V1" --user=admin --regular_price=54.97 --manage_stock=true --stock_quantity=3 \
        --attributes='[{"name":"TALLA","option":"JR S/M"}]' >/dev/null
    wp wc product_variation create "$V1" --user=admin --regular_price=54.97 --manage_stock=true --stock_quantity=5 \
        --attributes='[{"name":"TALLA","option":"JR L/XL"}]' >/dev/null

    # Variable WITHOUT a default variation; the first variation is out of stock
    V2=$(wp wc product create --user=admin --name="Stick CCM Ribcor" --type=variable \
        --categories="[{\"id\":$SR}]" --tags="[{\"id\":$BAUER}]" \
        --attributes='[{"name":"LADO","visible":true,"variation":true,"options":["LEFT","RIGHT"]}]' --porcelain)
    wp wc product_variation create "$V2" --user=admin --regular_price=149.00 --manage_stock=true --stock_quantity=0 \
        --attributes='[{"name":"LADO","option":"LEFT"}]' >/dev/null
    wp wc product_variation create "$V2" --user=admin --regular_price=149.00 --manage_stock=true --stock_quantity=2 \
        --attributes='[{"name":"LADO","option":"RIGHT"}]' >/dev/null
fi

echo "==> Configuring the plugin (test Irios domain: nothing reaches a real Irios network)"
wp option update woocommerce_irios_settings --format=json \
    '{"enabled":"yes","js_domain":"tracker.irios.test","store_id":"1234","consent_mode":"wp_consent_api","sponsored_enabled":"no","search_ad_unit":"","search_ad_unit_auth":"","max_ads":"4","sponsored_label":"Patrocinado","keep_single_result":"yes"}'

echo "==> Ready: http://localhost  (admin / admin)"
