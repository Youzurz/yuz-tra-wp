# Detection integration — no active response

## Live acceptance on 2026-09-15

After explicit owner approval, `wam_master` was restarted using wazuh-control.
Rule configuration passed `wazuh-analysisd -t`; dedicated group configuration
passed `verify-agent-conf`. Agent 011 retains `wordpress-origin-host` and
`linux-core`, with additive group `yuz-release`. The agent reconnected.
The cluster copied `yuz-release.xml` to the worker; no worker restart is claimed.

An explicitly labelled negative test measured empty bytes against the reviewed
ZIP digest. Test ID `80874fd9-d136-4c06-a5fb-57f4a884baf7` was received by
`wam-master` at `2026-09-15T16:25:58.509+0000`, rule `110861`, level 12,
agent `011`, location `/var/log/yuz-release/events.jsonl`.
This proves collector-to-manager alert delivery, not email receipt or a real
corrupted installation. Existing quarantine rules target only 100510/100511;
no active response was added. No WordPress deployment was performed.

The log is root-owned, group wazuh, mode 0640, directory 0750; dedicated
logrotate configuration is installed and passed debug validation.
`emit-labelled-test.cjs` is a manual acceptance tool, not an automatic job.
Automatic production release observation is not asserted by this test.

Rollback: remove only group `yuz-release` from agent 011 (preserve its other
groups), remove only the added `yuz-release.xml`, validate, then restart the
manager during an approved window. Preserve test logs as evidence.

`tools/release-observation.cjs TRACE ZIP` emits a real SHA-256 comparison as JSON.
It records an artifact verification, NOT a deployment. It exits nonzero on mismatch
or missing CI verification. It never sends secrets or modifies the artifact.
The trace must come from the protected CI artifact/provenance channel; a JSON file
alone is not a signature and must not be trusted if supplied by an attacker.

Central Wazuh integration requires the manager configuration owner:
- reserve/check IDs 110860–110862 for collisions;
- merge the rules and agent log collector, without replacing existing FIM rules;
- provision `/var/log/yuz-release/events.jsonl` writable only by the authorised
  release observer, not WordPress, with rotation and central retention;
- send a labelled test mismatch through the real collector;
- verify rule 110861 in manager alerts and receipt/acknowledgement by operations.

Existing FIM remains useful for plugin file changes. No rule here suppresses FIM
events during a deployment. No automatic delete, quarantine, restart, rollback or
Terraform apply is configured. A human investigates using commit, run and hash.
XML/source tests are NOT proof that the manager received an alert. Connection is
not considered operational until the end-to-end test is evidenced.

`bash tools/test-wazuh-rules.sh` tests the three expected rule IDs/levels in a
network-disabled, resource-limited disposable Wazuh 4.14.4 container pinned by digest.
It uses the legacy standalone parser, not the active manager or alert pipeline.
The image emits unrelated missing-list warnings in this minimal fixture; these
remain in the raw log. The `-U` assertion must succeed for each expected rule.
`test-ossec.conf` is exclusively this test fixture, never a replacement manager config.
