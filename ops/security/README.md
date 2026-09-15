# Detection integration — no active response

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
