# GitHub activation — 2026-09-15

Authenticated account `Youzurz`, repository administration and push permissions
verified through GitHub API. No credentials belong in this repository.

Controls created through GitHub API after verifying that no rulesets or release
environment existed:

- Main ruleset `23463834`: mandatory PR, resolved review threads, strict
  `Release gate` check bound to GitHub Actions app `15368`, no deletion or force push.
- Version tag ruleset `23463836`: no updates or deletion of `v*` tags.
- Release environment `21988102445`: reviewer `Youzurz`, protected branches only,
  administrators cannot bypass approval. This is single-maintainer approval,
  not independent review.

Branch `devsecops/release-gates-20260915` pushed without rewriting history.
The submitted 1.5.6 artifact is unchanged; no release was published.

Terraform remains unapplied. Import these existing resources into an approved
protected state backend before any Terraform apply; do not recreate them.
Import IDs: `yuz-tra-wp:23463834`, `yuz-tra-wp:23463836`, `yuz-tra-wp:release`.

API configuration is not proof that all CI jobs pass. Historical secret findings
and bundled dependency advisories remain release blockers, not waived findings.
