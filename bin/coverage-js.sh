#!/usr/bin/env bash
# Coverage of the storefront JS (irios-woocommerce/assets/js). The tests run that file with eval, exactly as
# WordPress serves it, so no coverage tool sees inside it: here it is instrumented before being eval'd and what each
# page covered is added up.
#
#   bin/coverage-js.sh         # summary, and irios-woocommerce/build/js-coverage/html/index.html
#
# It fails below the minimum. The minimum goes up as what is missing gets covered; it is not lowered to make the
# build pass. The PHP coverage is bin/coverage.sh.
set -euo pipefail
cd "$(dirname "$0")/.."
# Today's floor, so it cannot go down. Raise it as the gaps get covered; never lower it.
MIN=${MIN:-73}
rm -rf irios-woocommerce/build/js-coverage
IRIOS_COVERAGE=1 npx vitest run >/dev/null
MIN="$MIN" node bin/coverage-js-report.mjs
