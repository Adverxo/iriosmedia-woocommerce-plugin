#!/usr/bin/env bash
# Test coverage of the plugin's PHP, in a container with pcov so the local PHP is left alone.
#
#   bin/coverage.sh            # summary, and irios-woocommerce/build/coverage/index.html
#
# It fails below the minimum. The minimum goes up as what is missing gets covered; it is not lowered to make the
# build pass. The storefront JS is not measured here: the tests run it with eval (so that it is tested exactly as
# published), and no coverage tool sees inside that.
set -euo pipefail
cd "$(dirname "$0")/../irios-woocommerce"
# Today's floor, so it cannot go down. Raise it as the gaps get covered; never lower it.
MIN=${MIN:-70}
docker run --rm -v "$PWD":/app -w /app php:8.3-cli sh -c '
    pecl install pcov >/dev/null 2>&1
    docker-php-ext-enable pcov >/dev/null 2>&1
    php -d pcov.enabled=1 -d pcov.directory=src vendor/bin/phpunit --coverage-text --coverage-php build/coverage.php --coverage-html build/coverage
' | tee /tmp/irios-coverage.txt
PCT=$(grep -oE 'Lines:[[:space:]]+[0-9.]+%' /tmp/irios-coverage.txt | tail -1 | grep -oE '[0-9.]+' || echo 0)
echo "==> Lines: ${PCT}% (minimum ${MIN}%)"
awk -v p="$PCT" -v m="$MIN" 'BEGIN { exit (p+0 >= m+0) ? 0 : 1 }' \
    || { echo "ERROR: coverage below the minimum" >&2; exit 1; }
