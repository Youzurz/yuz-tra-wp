#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
version=$(sed -n 's/^ \* Version: //p' yuz-tra.php)
[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]
stage=$(mktemp -d)
mkdir -p "$stage/yuz-tra" dist
while IFS= read -r file; do
  [[ "$file" != /* && "$file" != *..* && -f "$file" ]]
  mkdir -p "$stage/yuz-tra/$(dirname "$file")"
  cp "$file" "$stage/yuz-tra/$file"
  chmod 644 "$stage/yuz-tra/$file"
done < release-files.txt
find "$stage/yuz-tra" -type f -name '*.php' -print0 | xargs -0 -n1 php -l > dist/php-lint.log
while IFS= read -r file; do node --input-type=module --check < "$file"; done < <(find "$stage/yuz-tra" -type f -name '*.js')
# Stable file metadata and ordering: local/CI archives can be compared byte-for-byte.
find "$stage/yuz-tra" -exec touch -t 198001010000 {} +
(cd "$stage" && find yuz-tra -type f | LC_ALL=C sort | zip -q -X "yuz-tra-$version.zip" -@)
unzip -tq "$stage/yuz-tra-$version.zip"
cp "$stage/yuz-tra-$version.zip" "dist/yuz-tra-$version.zip"
(cd dist && sha256sum "yuz-tra-$version.zip" > "yuz-tra-$version.zip.sha256")
printf 'Built %s (staging retained: %s)\n' "$version" "$stage"
