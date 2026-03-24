#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
EXPECTED_TAG="${1:-}"

fail() {
  echo "ERROR: $*" >&2
  exit 1
}

warn() {
  echo "WARN: $*" >&2
}

normalize_tag() {
  local value="$1"
  value="${value#refs/tags/}"
  value="${value#v}"
  printf '%s' "$value"
}

search_pattern() {
  local pattern="$1"
  if command -v rg >/dev/null 2>&1; then
    rg -n "$pattern" "$ROOT_DIR" -g '*.php' -g '*.js' -g '*.txt'
  else
    find "$ROOT_DIR" \( -name '*.php' -o -name '*.js' -o -name '*.txt' \) -print0 | xargs -0 grep -nE "$pattern"
  fi
}

version="$("$ROOT_DIR/scripts/get-version.sh")"
stable_tag="$(sed -nE 's/^Stable tag: (.+)$/\1/p' "$ROOT_DIR/readme.txt" | head -n1 | tr -d '\r')"

[[ -n "$stable_tag" ]] || fail "Unable to read Stable tag from readme.txt"
[[ "$version" == "$stable_tag" ]] || fail "Version mismatch: plugin=$version readme=$stable_tag"

if [[ -n "$EXPECTED_TAG" ]]; then
  expected_version="$(normalize_tag "$EXPECTED_TAG")"
  [[ "$version" == "$expected_version" ]] || fail "Tag mismatch: expected $expected_version but plugin is $version"
fi

find "$ROOT_DIR" -type f -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null

for forbidden in 'ip-api\.com' 'ltr\.fastendclear\.com' '837689f1-8f52-4db4-8b5a-b12ed806ed06'; do
  if search_pattern "$forbidden" >/dev/null 2>&1; then
    fail "Forbidden public-build pattern still present: $forbidden"
  fi
done

if [[ ! -d "$ROOT_DIR/.wordpress-org" ]]; then
  warn ".wordpress-org directory is missing"
else
  if [[ ! -f "$ROOT_DIR/.wordpress-org/icon-128x128.png" && ! -f "$ROOT_DIR/.wordpress-org/icon.svg" ]]; then
    warn "WordPress.org icon is missing"
  fi
  if [[ ! -f "$ROOT_DIR/.wordpress-org/banner-772x250.png" && ! -f "$ROOT_DIR/.wordpress-org/banner-772x250.jpg" ]]; then
    warn "WordPress.org banner is missing"
  fi
fi

echo "Release checks passed for version $version"
