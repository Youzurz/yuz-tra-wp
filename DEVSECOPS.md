# YUZ-TRA maintenance and release gates

## Prepared on 2026-09-15 — not a certification of live protection

The implementation is isolated from the submitted plugin. The WordPress.org
1.5.6 ZIP remains byte-for-byte unchanged:
`5ffd2a29f512ecd037c3761886a38011da70e2835dde807b584aede31da4daa3`.
`release-inputs/reviewed.json` pins that input and its source-file inventory.
CI refuses to reuse it if plugin sources change. A new plugin modification needs
a new reviewed/versioned artifact; there is no silent replacement of 1.5.6.

## Gates and evidence

| Gate | Implementation | Activation proof required |
|---|---|---|
| G01 source | PR + protected main and immutable version tags, Terraform module | Remote rules + failing-check/force-update refusal |
| G02 test | PHP 8.1/8.3, Node negative/security tests, four browser suites | GitHub run for the exact commit |
| G03 WordPress | Disposable database, real runtime/HTTP, Plugin Check; WP 6.5/7.1 | All four CI matrix jobs; local PHP 8.3 evidence is not PHP 8.1 evidence |
| G04 security | Redacted Gitleaks history, npm audit, bundled CycloneDX + Trivy | Complete reports, no unreviewed blocking findings |
| G05 release | Manual dispatch + explicit ZIP SHA + protected reviewer environment | Successful approved workflow; never a push-triggered release |
| G06 provenance | ZIP/SHA, original manifest, external tested-commit/run trace, attestation | Published asset digests and tag commit agree |
| G07 mirror | Existing fast-forward GitHub → GitLab; internal CI added | Same merged main/tag on both remotes, internal run observed |
| G08 detection | JSON hash observation + Wazuh rule/collector fragments | Real manager alert and operator acknowledgement |

The workflow runs weekly too, so newly disclosed dependency issues can fail a
previously passing build. Actions are pinned by commit; Dependabot proposes updates.
GitLab verification is secondary and cannot publish. `ops/extensions.json` is an
honest onboarding registry: other plugins are not marked integrated without evidence.

## Findings that must not be hidden

The first full history scan found four historical hard-coded LibreTranslate
credential occurrences (initial commit `8482737`), absent from current source.
Consider the credential exposed until its provider-side revocation is confirmed.
No value is reproduced here. No history rewrite or credential revocation was
performed by these changes. The secret gate intentionally stays blocking until
an incident owner confirms revocation and reviews any exact historical exceptions.
An unrelated Axios variable-name false positive has one fingerprint-only exception.

The bundled Axios 1.11.0 scan reports HIGH advisories. Some affect only the Node
adapter, others concern configuration/prototype handling and browser behavior.
Do not waive the complete package by claiming “browser-only.” Prepare a separately
versioned dependency upgrade or advisory-by-advisory reachability evidence. Keep
the submitted 1.5.6 immutable; its previous runtime check is not a vulnerability audit.

## Operations boundary

The Terraform module has no server/DNS/VPN deployment. Never apply without a
reviewed plan, protected state backend and an authenticated repository administrator.
Wazuh fragments are not live configuration; test with the manager and actual collector.
No automatic quarantine, deletion, restart, rollback or infrastructure apply exists.
Artifact verification is not proof of an installed WordPress directory: deployed-file
verification and a restore rehearsal must be integrated by the deployment owner.

GitHub administration/authentication and Wazuh manager access are prerequisites,
not facts implied by a checked-in YAML file. Existing public releases and the
WordPress.org submission are not changed by committing this hardening branch.
