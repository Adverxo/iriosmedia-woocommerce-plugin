#!/usr/bin/env bash
# WordPress coding standards over the plugin's PHP, in a container so the local PHP is left alone.
#
#   bin/lint.sh          # reports
#   bin/lint.sh --fix    # fixes what can be fixed
#
# The point is not the formatting: the code is full of phpcs:ignore comments (output that is JavaScript, queries that
# are slow on purpose) and without this nobody checks that what they silence is still what is there.
set -euo pipefail
cd "$(dirname "$0")/../irios-woocommerce"
TOOL=phpcs
[[ "${1:-}" == "--fix" ]] && TOOL=phpcbf
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php "vendor/bin/$TOOL" "${@:2}"
