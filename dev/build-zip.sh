#!/bin/sh
# Builds dist/ai-translator-for-translatepress.zip (the asset name the updater expects).
# Only runtime files go in: no tests, dev tools, docs or private data.
set -eu
ROOT=$(cd "$(dirname "$0")/.." && pwd)
SLUG=ai-translator-for-translatepress
VERSION=$(sed -n "s/^define( 'AITP_VERSION', '\(.*\)' );$/\1/p" "$ROOT/$SLUG.php")
HEADER=$(sed -n 's/^ \* Version: *//p' "$ROOT/$SLUG.php")
STABLE=$(sed -n 's/^Stable tag: *//p' "$ROOT/readme.txt")
if [ "$VERSION" != "$HEADER" ] || [ "$VERSION" != "$STABLE" ]; then
	echo "Version mismatch: AITP_VERSION=$VERSION header=$HEADER readme=$STABLE" >&2
	exit 1
fi
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/$SLUG"
cp "$ROOT/$SLUG.php" "$ROOT/uninstall.php" "$ROOT/readme.txt" "$ROOT/LICENSE" "$TMP/$SLUG/"
cp -R "$ROOT/includes" "$ROOT/admin" "$ROOT/languages" "$TMP/$SLUG/"
find "$TMP/$SLUG" -name '.DS_Store' -delete
mkdir -p "$ROOT/dist"
rm -f "$ROOT/dist/$SLUG.zip"
(cd "$TMP" && zip -qr "$ROOT/dist/$SLUG.zip" "$SLUG")
echo "dist/$SLUG.zip ($VERSION)"
