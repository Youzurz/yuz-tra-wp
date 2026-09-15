#!/usr/bin/env bash
set -euo pipefail
# Isolated parser tests only; no manager volumes, sockets, ports or network.
rules_dir=${WAZUH_RULES_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../ops/security" && pwd)}
test -f "$rules_dir/test-ossec.conf"
image=wazuh/wazuh-manager@sha256:5a065930682d728e3939a3a34b7c9bc28d55b22d3d93c2fe3cc19cf76d67e8e8
for spec in mismatch:110861:12 unverified:110862:10 verified:110860:3; do
  IFS=: read -r result rule level <<< "$spec"
  printf '{"integration":"yuz-release","plugin":"yuz-tra","version":"1.5.6","result":"%s","commit":"isolated-test-fixture"}\n' "$result" |
    docker run --rm -i --network none --memory 512m --cpus 1 --entrypoint sh \
      -v "$rules_dir:/var/ossec/tmp/yuz-rule-test:ro" "$image" -c \
      'cp /var/ossec/data_tmp/exclusion/var/ossec/etc/internal_options.conf /var/ossec/etc/internal_options.conf && chmod 644 /var/ossec/etc/internal_options.conf && exec /var/ossec/bin/wazuh-logtest-legacy -c tmp/yuz-rule-test/test-ossec.conf -U "$1"' \
      test "$rule:$level:json"
  printf 'PASS isolated Wazuh parser: %s -> %s level %s\n' "$result" "$rule" "$level"
done
