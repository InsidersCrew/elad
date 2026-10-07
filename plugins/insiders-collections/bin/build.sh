#!/usr/bin/env bash
# Builds dist/insiders-collections-<version>.zip with production dependencies only.
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION=$(grep -E "define\( 'ICOL_VERSION'" insiders-collections.php | sed -E "s/.*'([0-9.]+)'.*/\1/")
HEADER=$(grep -E '^ \* Version:' insiders-collections.php | awk '{print $3}')
if [ "$VERSION" != "$HEADER" ]; then echo "version mismatch: header $HEADER vs ICOL_VERSION $VERSION" >&2; exit 1; fi
composer install --no-dev --no-interaction --optimize-autoloader >/dev/null
find vendor -name .git -type d -prune -exec rm -rf {} +
rm -rf vendor/anthropic-ai/sdk/{tests,examples,fixtures,scripts} 2>/dev/null || true
fails=0
for f in $(find includes templates insiders-collections.php -name '*.php'); do php -l "$f" >/dev/null || fails=$((fails+1)); done
[ "$fails" -eq 0 ] || { echo "php -l failed on $fails files" >&2; exit 1; }
for j in assets/*.js; do node -c "$j"; done
STAGE=$(mktemp -d)
mkdir -p "$STAGE/insiders-collections" dist
cp -R insiders-collections.php includes templates assets vendor composer.json README.md "$STAGE/insiders-collections/"
mkdir -p "$STAGE/insiders-collections/docs" && cp -R docs/revenue-dashboard-contract.md "$STAGE/insiders-collections/docs/"
(cd "$STAGE" && zip -qr "insiders-collections-$VERSION.zip" insiders-collections)
mv "$STAGE/insiders-collections-$VERSION.zip" dist/
rm -rf "$STAGE"
ls -la "dist/insiders-collections-$VERSION.zip"
