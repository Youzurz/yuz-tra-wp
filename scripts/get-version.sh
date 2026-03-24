#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_FILE="$ROOT_DIR/yuz-tra.php"

version="$(sed -nE 's/^ \* Version: (.+)$/\1/p' "$PLUGIN_FILE" | head -n1 | tr -d '\r')"

if [[ -z "$version" ]]; then
  echo "Unable to read plugin version from $PLUGIN_FILE" >&2
  exit 1
fi

printf '%s\n' "$version"
