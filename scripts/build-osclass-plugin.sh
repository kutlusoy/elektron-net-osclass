#!/usr/bin/env bash
# Builds an installable oc-content/plugins/osclass-escrow zip: dependencies
# installed into vendor/, dev-only files stripped, ready to extract into an
# Osclass install.
#
# Usage: scripts/build-osclass-plugin.sh [version]
#   version defaults to the git tag/describe output if omitted.
#
# Requires: composer, zip, php (languages/po2mo.php is the msgfmt fallback).

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="${1:-$(git -C "$REPO_ROOT" describe --tags --always 2>/dev/null || echo "0.0.0-dev")}"
VERSION="${VERSION#osclass-v}"
VERSION="${VERSION#v}"

BUILD_DIR="$(mktemp -d)"
trap 'rm -rf "$BUILD_DIR"' EXIT

echo "Building osclass-escrow ${VERSION} ..."

cp -a "$REPO_ROOT/osclass-escrow" "$BUILD_DIR/osclass-escrow"
cd "$BUILD_DIR/osclass-escrow"

composer install --no-dev --optimize-autoloader --no-interaction

for po in languages/*/messages.po; do
  locale_dir="$(dirname "$po")"
  if command -v msgfmt >/dev/null 2>&1; then
    msgfmt -o "$locale_dir/messages.mo" "$po"
  else
    php languages/po2mo.php "$po" "$locale_dir/messages.mo"
  fi
done

sed -i.bak -E "s/^Version: .*/Version: ${VERSION}/" index.php && rm -f index.php.bak

rm -rf tests
rm -f composer.json composer.lock languages/po2mo.php

cd "$BUILD_DIR"
DIST_DIR="$REPO_ROOT/dist"
mkdir -p "$DIST_DIR"
ZIP_PATH="$DIST_DIR/osclass-escrow-${VERSION}.zip"
rm -f "$ZIP_PATH"
zip -rq "$ZIP_PATH" osclass-escrow

echo "Built $ZIP_PATH"
