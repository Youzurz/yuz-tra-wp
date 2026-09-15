#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
version=$(sed -n 's/^ \* Version: //p' "$root/yuz-tra.php")
[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]
wp_version=${WORDPRESS_VERSION:?Explicit WORDPRESS_VERSION required}
[[ "$wp_version" =~ ^[0-9]+\.[0-9]+(\.[0-9]+)?$ ]]
archive="$root/dist/yuz-tra-$version.zip"
evidence="$root/dist/wordpress/$wp_version-$(php -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;')"
http_port=${WPORG_HTTP_PORT:-8088}
[[ "$http_port" =~ ^[0-9]+$ && "$http_port" -gt 1024 && "$http_port" -lt 65536 ]]
http_url="http://127.0.0.1:$http_port"
mkdir -p "$evidence"
site=$(mktemp -d /tmp/yuz-ci-wordpress.XXXXXX)
server_pid=''
cleanup(){
  if [[ -n "$server_pid" ]]; then kill "$server_pid" 2>/dev/null || true; fi
  [[ "$site" == /tmp/yuz-ci-wordpress.* && -d "$site" ]] && rm -rf -- "$site"
}
trap cleanup EXIT
wpcli(){ php -d memory_limit=1G "$(command -v wp)" --path="$site" --allow-root "$@"; }
wpcli core download --version="$wp_version"
wpcli config create --dbhost="${WPORG_DB_HOST:?}" --dbname="${WPORG_DB_NAME:?}" --dbuser="${WPORG_DB_USER:?}" --dbpass="${WPORG_DB_PASSWORD:?}"
wpcli core install --url="$http_url" --title=YUZ-CI --admin_user=admin --admin_password=ci-only-not-reused --admin_email=ci@example.invalid --skip-email
unzip -q "$archive" -d "$site/wp-content/plugins"
wpcli plugin install plugin-check --version=2.1.0 --activate
wpcli plugin activate yuz-tra
wpcli eval-file "$root/tests/review-runtime.php" >"$evidence/runtime.log" 2>&1
php -S "127.0.0.1:$http_port" -t "$site" >"$evidence/http-server.log" 2>&1 &
server_pid=$!
for attempt in {1..30}; do
  kill -0 "$server_pid"
  if curl -fsS "$http_url/" -o "$evidence/frontend.html"; then break; fi
  sleep 0.2
done
grep -qi '<html' "$evidence/frontend.html"
! grep -qiE 'Fatal error|Uncaught Error' "$evidence/http-server.log"
echo 'PASS actual HTTP frontend' >"$evidence/http.log"
set +e
wpcli plugin check yuz-tra --format=strict-csv --fields=file,line,column,type,code,message --mode=new >"$evidence/plugin-check.csv" 2>"$evidence/plugin-check.stderr"
scan_status=$?
set -e
php "$root/tools/check-plugin-report.php" "$evidence/plugin-check.csv" >"$evidence/plugin-check-summary.json"
[[ "$scan_status" == 0 ]]
sha256sum "$archive" >"$evidence/artifact.sha256"
printf 'PASS WordPress %s PHP %s exact-artifact acceptance\n' "$wp_version" "$(php -r 'echo PHP_VERSION;')"
