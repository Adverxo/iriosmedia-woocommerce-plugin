#!/usr/bin/env bash
# Prints the CHANGELOG.md section of a version (without its "## X.Y.Z - date" heading), for the GitHub release notes.
# Fails if the version has no section or it is empty, so that no release is published without notes.
#
#   bin/release-notes.sh 1.0.0
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION="${1:?Usage: bin/release-notes.sh <version>}"

NOTES=$(awk -v version="$VERSION" '
    /^## / { if (found) exit; found = ($2 == version); next }
    found { print }
' CHANGELOG.md | sed -e '/./,$!d')   # without leading blank lines

if [[ -z "${NOTES//[[:space:]]/}" ]]; then
    echo "CHANGELOG.md has no notes for version $VERSION (a \"## $VERSION - YYYY-MM-DD\" section)" >&2
    exit 1
fi
printf '%s\n' "$NOTES"
