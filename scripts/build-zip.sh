#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="${PLUGIN_SLUG:-yuz-tra-wp}"
DIST_DIR="${DIST_DIR:-$ROOT_DIR/dist}"
VERSION="$("$ROOT_DIR/scripts/get-version.sh")"

"$ROOT_DIR/scripts/check-release.sh" "$VERSION" >/dev/null

mkdir -p "$DIST_DIR"

WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

STAGE_DIR="$WORK_DIR/$SLUG"
ZIP_PATH="$DIST_DIR/$SLUG-$VERSION.zip"
LATEST_ZIP_PATH="$DIST_DIR/$SLUG.zip"

mkdir -p "$STAGE_DIR"
rm -f "$ZIP_PATH"
rm -f "$LATEST_ZIP_PATH"

rsync -a --delete --exclude-from="$ROOT_DIR/.distignore" "$ROOT_DIR/" "$STAGE_DIR/"

(
  cd "$WORK_DIR"
  zip -qr "$ZIP_PATH" "$SLUG"
)

cp "$ZIP_PATH" "$LATEST_ZIP_PATH"

printf '%s\n' "$ZIP_PATH"
