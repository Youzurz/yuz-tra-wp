#!/usr/bin/env bash
# Disposable integration acceptance; never connects to a production database.
set -euo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
profile=${1:-submitted}
case "$profile" in submitted|working-tree|candidate) ;; *) echo 'Unknown proof profile' >&2; exit 2;; esac
archive="$root/release-inputs/yuz-tra-1.5.6.zip"
if [[ "$profile" == candidate ]]; then
  version=$(node -p "require('$root/version.json').version")
  [[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]
  archive="$root/dist/yuz-tra-$version.zip"
fi
test -f "$archive"
run_dir=$(mktemp -d /tmp/yuz-proof-wordpress.XXXXXX)
run_id="yuz-proof-$(basename "$run_dir" | tr '[:upper:]' '[:lower:]')"
db="$run_id-db"
cli="$run_id-cli"
cleanup(){
  docker rm -f -v "$cli" "$db" >/dev/null 2>&1 || true
  docker network rm "$run_id" >/dev/null 2>&1 || true
  # Preserve this run's files as evidence; do not remove unrelated directories.
}
trap cleanup EXIT
trap 'exit 124' TERM INT
docker network create --label yuz.proof=true "$run_id" >/dev/null
docker run -d --name "$db" --label yuz.proof=true --network "$run_id" --memory=512m --cpus=1 --tmpfs /var/lib/mysql \
  -e MARIADB_ROOT_PASSWORD=disposable-test-only -e MARIADB_DATABASE=wpcheck \
  -e MARIADB_USER=wpcheck -e MARIADB_PASSWORD=disposable-test-only mariadb:11 >/dev/null
for n in {1..60}; do
  if docker exec "$db" healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; then break; fi
  sleep 1
done
docker exec "$db" healthcheck.sh --connect --innodb_initialized >/dev/null
site="$run_dir/site"
mkdir "$site"
wpcli(){ docker run --rm --name "$cli" --label yuz.proof=true --memory=512m --cpus=1 --user "$(id -u):$(id -g)" --network "$run_id" \
  -v "$site:/var/www/html" -v "$root:/proof:ro" -e WP_CLI_CACHE_DIR=/tmp/wp-cache \
  wordpress:cli --path=/var/www/html "$@"; }
wpcli core download --version=6.5 >"$run_dir/install.log" 2>&1
wpcli config create --dbhost="$db" --dbname=wpcheck --dbuser=wpcheck --dbpass=disposable-test-only >>"$run_dir/install.log" 2>&1
# WP_DEBUG actif : condition exigee par la revue WordPress.org.
wpcli config set WP_DEBUG true --raw --type=constant >>"$run_dir/install.log" 2>&1
wpcli config set WP_DEBUG_LOG true --raw --type=constant >>"$run_dir/install.log" 2>&1
wpcli config set WP_DEBUG_DISPLAY false --raw --type=constant >>"$run_dir/install.log" 2>&1
if ! wpcli config get WP_DEBUG --type=constant 2>/dev/null | grep -qi '^1$\|^true$'; then
  echo "FAIL WP_DEBUG could not be enabled in the disposable installation" | tee -a "$run_dir/runtime.log"
  exit 1
fi
wpcli core install --url=http://proof.invalid/subdirectory --title=YUZ-Proof --admin_user=admin --admin_password=disposable-test-only --admin_email=proof@example.invalid --skip-email >>"$run_dir/install.log" 2>&1
unzip -q "$archive" -d "$site/wp-content/plugins"
if [[ "$profile" == working-tree ]]; then
  # Overlay only the reviewed source files inside this disposable installation.
  for file in class-yuz-ajax.php class-yuz-assets.php class-yuz-core.php class-yuz-frontend.php class-yuz-options-bridge.php class-yuz-logger.php class-yuz-db.php class-yuz-debug-probe.php class-yuz-url-converter.php; do
    cp "$root/includes/$file" "$site/wp-content/plugins/yuz-tra/includes/$file"
    sha256sum "$root/includes/$file" >>"$run_dir/source-overrides.sha256"
  done
  for file in helpers/settings-helpers.php helpers/debug-helpers.php; do
    mkdir -p "$site/wp-content/plugins/yuz-tra/includes/$(dirname "$file")"
    cp "$root/includes/$file" "$site/wp-content/plugins/yuz-tra/includes/$file"
    sha256sum "$root/includes/$file" >>"$run_dir/source-overrides.sha256"
  done
  cp "$root/assets/js/yuz-translate-dom-changes.js" "$site/wp-content/plugins/yuz-tra/assets/js/yuz-translate-dom-changes.js"
  sha256sum "$root/assets/js/yuz-translate-dom-changes.js" >>"$run_dir/source-overrides.sha256"
fi
activation_output="$(wpcli plugin activate yuz-tra 2>&1)"
printf '%s\n' "$activation_output" >>"$run_dir/install.log"
# L'activation ne doit produire aucune sortie inattendue ni entree de debogage.
if printf '%s' "$activation_output" | grep -qiE 'unexpected output|fatal error|warning:|notice:|deprecated:'; then
  echo "FAIL activation produces PHP diagnostics under WP_DEBUG" | tee -a "$run_dir/runtime.log"
  exit 1
fi
debug_log="$site/wp-content/debug.log"
if [[ -f "$debug_log" ]] && grep -qiE 'yuz|yuztra' "$debug_log"; then
  echo "FAIL activation writes plugin entries to debug.log" | tee -a "$run_dir/runtime.log"
  cp "$debug_log" "$run_dir/activation-debug.log"
  exit 1
fi
echo "PASS activation is clean under WP_DEBUG" | tee -a "$run_dir/runtime.log"

wpcli eval-file /proof/tests/review-runtime.php >>"$run_dir/runtime.log" 2>&1
if [[ "$profile" != submitted ]]; then
  wpcli eval-file /proof/tests/review-sept16-runtime.php >>"$run_dir/runtime.log" 2>&1
  wpcli eval-file /proof/tests/frontend-safety-runtime.php --skip-plugins --skip-themes >>"$run_dir/runtime.log" 2>&1
  wpcli eval-file /proof/tests/review-publish-request.php invalid >"$run_dir/publish-invalid.json" 2>&1
  wpcli eval-file /proof/tests/review-publish-request.php valid >"$run_dir/publish-valid.json" 2>&1
  wpcli eval-file /proof/tests/review-publish-verify.php >>"$run_dir/runtime.log" 2>&1
fi
if [[ "$profile" == candidate ]]; then
  wpcli plugin install plugin-check --version=2.1.0 --activate >>"$run_dir/install.log" 2>&1
  wpcli plugin check yuz-tra --format=strict-csv --fields=file,line,column,type,code,message --mode=new >"$run_dir/plugin-check.csv" 2>"$run_dir/plugin-check.stderr" || scan_status=$?
  gate_args=("$run_dir/plugin-check.csv")
  if [[ -n "${YUZ_PLUGIN_CHECK_TRIAGE:-}" ]]; then
    triage=$(realpath "${YUZ_PLUGIN_CHECK_TRIAGE}")
    [[ -f "$triage" ]]
    gate_args+=("$triage")
    sha256sum "$triage" >"$run_dir/plugin-check-triage.sha256"
  fi
  php "$root/tools/check-plugin-report.php" "${gate_args[@]}" >"$run_dir/plugin-check-summary.json"
  [[ "${scan_status:-0}" == 0 ]]
fi
sha256sum "$archive" >"$run_dir/artifact.sha256"
docker image inspect wordpress:cli mariadb:11 --format '{{.Id}}' >"$run_dir/images.txt"
printf 'PASS disposable WordPress 6.5 SQL integration (%s)\nEvidence: %s\n' "$profile" "$run_dir"
cat "$run_dir/runtime.log"
