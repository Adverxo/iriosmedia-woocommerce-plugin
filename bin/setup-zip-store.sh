#!/usr/bin/env bash
# WooCommerce store to test the plugin zip as in a real store: http://localhost:8089 (admin / admin).
# Unlike the development store (port 80), the plugin is NOT mounted from the repository here: it is installed and
# updated by uploading the zip from bin/package.sh in Plugins → Add New → Upload Plugin.
#
# Usage:
#   docker compose --profile zip up -d
#   bin/setup-zip-store.sh                                   # empty store with WooCommerce and two products
#   bin/setup-zip-store.sh dist/irios-woocommerce-X.zip  # and installs (or replaces) the plugin from that zip
set -euo pipefail
cd "$(dirname "$0")/.."

ZIP="${1:-}"
[[ -z $ZIP || -f $ZIP ]] || { echo "Zip not found: $ZIP" >&2; exit 1; }

wp() { docker compose --profile zip --profile zip-cli run --rm -T cli-zip wp "$@"; }

echo "==> Waiting for WordPress (8089)"
for _ in $(seq 1 60); do
    if docker compose --profile zip exec -T wordpress-zip test -f /var/www/html/wp-config.php 2>/dev/null; then break; fi
    sleep 2
done
until wp db check >/dev/null 2>&1; do sleep 2; done

if ! wp core is-installed 2>/dev/null; then
    echo "==> Installing WordPress"
    wp core install --url=http://localhost:8089 --title="Tienda zip" --admin_user=admin --admin_password=admin \
        --admin_email=admin@example.com --skip-email
    wp language core install es_ES --activate || true
fi

echo "==> WooCommerce"
wp plugin install woocommerce --activate
wp language plugin install woocommerce es_ES || true
wp option update woocommerce_default_country "ES:M"
wp option update woocommerce_currency "EUR"
wp option update woocommerce_coming_soon "no"
wp rewrite structure '/%postname%/' --hard

if [[ $(wp wc product list --user=admin --format=count) -eq 0 ]]; then
    echo "==> Sample products"
    CAT=$(wp wc product_cat create --user=admin --name="Sticks" --porcelain)
    wp wc product create --user=admin --name="Stick Bauer Vapor" --type=simple --regular_price=199.99 \
        --categories="[{\"id\":$CAT}]" >/dev/null
    wp wc product create --user=admin --name="Stick CCM Ribcor" --type=simple --regular_price=149.00 \
        --categories="[{\"id\":$CAT}]" >/dev/null
fi

if [[ -n $ZIP ]]; then
    echo "==> Installing the plugin from $ZIP"
    # In the volume shared by WordPress and wp-cli (they are separate containers: /tmp is not shared)
    TMP=/var/www/html/wp-content/uploads/irios-woocommerce-upload.zip
    docker compose --profile zip exec -T wordpress-zip mkdir -p /var/www/html/wp-content/uploads
    docker compose --profile zip cp "$ZIP" "wordpress-zip:$TMP"
    docker compose --profile zip exec -T wordpress-zip chown www-data:www-data "$TMP"
    wp plugin install "$TMP" --force --activate
    docker compose --profile zip exec -T wordpress-zip rm -f "$TMP"
    wp plugin get irios-woocommerce --fields=name,version,status
fi

echo "==> Ready: http://localhost:8089/wp-admin  (admin / admin)"
