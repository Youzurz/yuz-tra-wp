#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DRY_RUN=0

if [[ "${1:-}" == "--dry-run" ]]; then
  DRY_RUN=1
  shift
fi

VERSION="${1:-$("$ROOT_DIR/scripts/get-version.sh")}"
SLUG="${WPORG_SLUG:-yuz-tra-wp}"
SVN_URL="${WPORG_SVN_URL:-https://plugins.svn.wordpress.org/$SLUG/}"
SVN_USERNAME="${WPORG_SVN_USERNAME:-}"
SVN_PASSWORD="${WPORG_SVN_PASSWORD:-}"

fail() {
  echo "ERROR: $*" >&2
  exit 1
}

command -v svn >/dev/null 2>&1 || fail "svn is required"
command -v rsync >/dev/null 2>&1 || fail "rsync is required"
command -v zip >/dev/null 2>&1 || fail "zip is required"

[[ -n "$SVN_USERNAME" ]] || fail "WPORG_SVN_USERNAME is required"
[[ -n "$SVN_PASSWORD" ]] || fail "WPORG_SVN_PASSWORD is required"

"$ROOT_DIR/scripts/check-release.sh" "$VERSION" >/dev/null
ZIP_PATH="$("$ROOT_DIR/scripts/build-zip.sh")"

WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

STAGE_DIR="$WORK_DIR/stage/$SLUG"
SVN_DIR="$WORK_DIR/svn"

mkdir -p "$STAGE_DIR"
rsync -a --delete --exclude-from="$ROOT_DIR/.distignore" "$ROOT_DIR/" "$STAGE_DIR/"

svn checkout \
  --non-interactive \
  --username "$SVN_USERNAME" \
  --password "$SVN_PASSWORD" \
  "$SVN_URL" \
  "$SVN_DIR" >/dev/null

mkdir -p "$SVN_DIR/trunk" "$SVN_DIR/tags" "$SVN_DIR/assets"

rsync -a --delete --exclude='.svn' "$STAGE_DIR/" "$SVN_DIR/trunk/"

if [[ -d "$ROOT_DIR/.wordpress-org" ]]; then
  rsync -a --delete --exclude='.svn' "$ROOT_DIR/.wordpress-org/" "$SVN_DIR/assets/"
fi

if [[ -e "$SVN_DIR/tags/$VERSION" ]]; then
  fail "SVN tag already exists: $VERSION"
fi

mkdir -p "$SVN_DIR/tags/$VERSION"
rsync -a --delete --exclude='.svn' "$STAGE_DIR/" "$SVN_DIR/tags/$VERSION/"

(
  cd "$SVN_DIR"
  svn add --force trunk tags assets >/dev/null 2>&1 || true

  missing_paths="$(svn status | awk '/^\!/ {print $2}')"
  if [[ -n "$missing_paths" ]]; then
    while IFS= read -r path; do
      [[ -n "$path" ]] && svn rm --force "$path" >/dev/null
    done <<< "$missing_paths"
  fi

  new_paths="$(svn status | awk '/^\?/ {print $2}')"
  if [[ -n "$new_paths" ]]; then
    while IFS= read -r path; do
      [[ -n "$path" ]] && svn add --parents "$path" >/dev/null
    done <<< "$new_paths"
  fi

  echo "Prepared SVN status:"
  svn status

  if [[ "$DRY_RUN" -eq 1 ]]; then
    exit 0
  fi

  if [[ -z "$(svn status)" ]]; then
    echo "No SVN changes to commit"
    exit 0
  fi

  svn commit \
    --non-interactive \
    --username "$SVN_USERNAME" \
    --password "$SVN_PASSWORD" \
    -m "Deploy version $VERSION" >/dev/null
)

echo "WordPress.org deploy prepared for version $VERSION"
echo "ZIP artifact: $ZIP_PATH"
