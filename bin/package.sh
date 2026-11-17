#!/usr/bin/env bash
# Packages the plugin to install it in a store: dist/irios-woocommerce-<version>.zip, with Composer's production
# dependencies (vendor/ without dev dependencies), the license and no tests. Uses a local composer or, if missing, the
# composer:2 Docker image.
#
# The version is the repository's, from its git tags (git describe): 1.0.1 at a tagged commit, 1.0.1-3-g<hash> after it,
# with .dirty if there are uncommitted changes; without any tag, 0.0.0-<hash>. It is written into the header (Version:)
# and IRIOS_WC_VERSION of the copy that goes into the zip; the source code keeps 0.0.0-dev.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PLUGIN="$ROOT/irios-woocommerce"

VERSION=$(git -C "$ROOT" describe --tags --always --dirty=.dirty)
[[ $VERSION =~ ^[0-9] ]] || VERSION="0.0.0-$VERSION"   # no tags: describe only gives the hash
[[ $VERSION =~ ^[0-9A-Za-z.+-]+$ ]] || { echo "Invalid version: '$VERSION'" >&2; exit 1; }

OUT="$ROOT/dist/irios-woocommerce-$VERSION.zip"
BUILD="$(mktemp -d)"
trap 'rm -rf "$BUILD"' EXIT

mkdir -p "$BUILD/irios-woocommerce" "$ROOT/dist"
# The storefront JS is built from assets/js/src (npm run build:js) and committed: if what is committed is not what
# the sources build to, the zip would ship a different file from the one the tests ran against
if command -v node >/dev/null 2>&1 && [[ -d "$ROOT/node_modules/esbuild" ]]; then
    built="$(mktemp)"
    cp "$PLUGIN/assets/js/irios-woocommerce.js" "$built"
    (cd "$ROOT" && node bin/build-js.mjs)
    if ! diff -q "$built" "$PLUGIN/assets/js/irios-woocommerce.js" >/dev/null; then
        echo "assets/js/irios-woocommerce.js was out of date; rebuilt. Commit it." >&2
    fi
    rm -f "$built"
fi

# Anchored with a leading /: a bare "src" also matches the plugin's own src/ (all its PHP classes), and 1.1.1 shipped
# without them. Only the JS sources (assets/js/src, built into assets/js/irios-woocommerce.js) and build/ stay out.
rsync -a --exclude vendor --exclude tests --exclude /assets/js/src --exclude /build --exclude '.phpunit*' --exclude phpunit.xml.dist \
    --exclude .gitignore \
    "$PLUGIN/" "$BUILD/irios-woocommerce/"

# The zip must carry the plugin's classes and nothing it only needs to be built: a release without them takes down
# every site that installs it (the classes are loaded on plugins_loaded, front end included)
[[ -f "$BUILD/irios-woocommerce/src/Plugin.php" ]] || { echo "src/ is missing from the package" >&2; exit 1; }
[[ ! -e "$BUILD/irios-woocommerce/assets/js/src" ]] || { echo "assets/js/src should not be in the package" >&2; exit 1; }

MAIN="$BUILD/irios-woocommerce/irios-woocommerce.php"
sed -i.bak -E \
    -e "s/^( \* Version: +).*/\1$VERSION/" \
    -e "s/^const IRIOS_WC_VERSION = '.*';/const IRIOS_WC_VERSION = '$VERSION';/" \
    "$MAIN"
rm -f "$MAIN.bak"
grep -q "^ \* Version: *$VERSION\$" "$MAIN" && grep -q "^const IRIOS_WC_VERSION = '$VERSION';" "$MAIN" \
    || { echo "Could not write version $VERSION into irios-woocommerce.php" >&2; exit 1; }

if command -v composer >/dev/null 2>&1; then
    (cd "$BUILD/irios-woocommerce" && composer install --no-dev --optimize-autoloader --no-interaction --quiet)
else
    docker run --rm -v "$BUILD/irios-woocommerce":/app -w /app composer:2 \
        install --no-dev --optimize-autoloader --no-interaction --quiet
fi
rm -f "$BUILD/irios-woocommerce/composer.json" "$BUILD/irios-woocommerce/composer.lock"
cp "$ROOT/LICENSE" "$BUILD/irios-woocommerce/LICENSE"

rm -f "$OUT"
(cd "$BUILD" && zip -qr "$OUT" irios-woocommerce)
echo "$OUT"
