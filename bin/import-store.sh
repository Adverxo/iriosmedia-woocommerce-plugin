#!/usr/bin/env bash
# Imports a WXR export of a real store into the Docker store, to get an environment close to production.
#
#   bin/import-store.sh <anonymised-export.xml> [--with-images]
#
# First: an empty store (docker compose down -v && docker compose up -d && SAMPLE_DATA=no bin/setup-store.sh), so that
# production ids are kept. Anonymise the export first with bin/anonymize-wxr.py.
# --with-images downloads the images from the store's website (slow: one request per image).
# Import ONCE only: the WordPress importer does not detect duplicate menus or some orders, so a second run duplicates
# them. To repeat, start again from an empty store.
set -euo pipefail
cd "$(dirname "$0")/.."
WXR="${1:?Usage: bin/import-store.sh <export.xml> [--with-images]}"
WITH_IMAGES="${2:-}"

wp() { docker compose run --rm -T cli wp "$@"; }

echo "==> Importer, classic theme and cookie banner (Storefront stands in for the real store theme)"
wp plugin install wordpress-importer --activate
wp plugin install asesor-cookies-para-la-ley-en-espana --activate
wp theme install storefront --activate

echo "==> Orders in the classic tables: the WordPress importer does not write to the HPOS tables"
wp option update woocommerce_custom_orders_table_enabled no
wp option update woocommerce_custom_orders_table_data_sync_enabled no

echo "==> Importing $(basename "$WXR")"
# Without this filter the importer skips variations with the same title and date as another one
# (dev/import-variations-filter.php)
docker compose exec -T wordpress mkdir -p /var/www/html/wp-content/mu-plugins
docker compose cp dev/import-variations-filter.php wordpress:/var/www/html/wp-content/mu-plugins/irios-import-variations.php
docker compose cp "$WXR" wordpress:/var/www/html/wp-content/uploads/irios-import.xml
SKIP="--skip=attachment"
[[ "$WITH_IMAGES" == "--with-images" ]] && SKIP=""
wp import /var/www/html/wp-content/uploads/irios-import.xml --authors=skip $SKIP --quiet || true
docker compose exec -T wordpress rm -f /var/www/html/wp-content/uploads/irios-import.xml \
    /var/www/html/wp-content/mu-plugins/irios-import-variations.php
wp eval 'foreach ( wc_get_products( array( "type" => "variable", "limit" => -1, "status" => array( "publish", "trash", "draft", "private" ) ) ) as $p ) { WC_Product_Variable::sync( $p->get_id() ); } wc_delete_product_transients();' 

echo "==> Real store settings"
wp option update woocommerce_default_country "ES"
wp option update woocommerce_currency "EUR"
wp option update woocommerce_prices_include_tax "yes"
wp option update woocommerce_coming_soon "no"
# Same URLs as production: /producto/<slug>/ and /cat/<slug>/
wp option update woocommerce_permalinks --format=json '{"product_base":"/producto","category_base":"cat","tag_base":"etiqueta","attribute_base":"","use_verbose_page_rules":false}'
wp rewrite structure '/%postname%/' --hard
wp wc tool run regenerate_product_lookup_tables --user=admin >/dev/null 2>&1 || true
wp transient delete --all >/dev/null

echo "==> Summary"
for type in product product_variation shop_order shop_coupon page attachment; do
    echo "  $type: $(wp post list --post_type=$type --post_status=any --format=count)"
done
echo "==> Ready: http://localhost  (admin / admin)"
