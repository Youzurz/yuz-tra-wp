# Activation evidence — 2026-09-15

This is not a production-readiness certificate.

- GitLab accepted branch `devsecops/release-gates-20260915` and created pipeline
  68 for `7d11de4e6f757e4047478c2ea4e2b9a7451bc588`.
  The pipeline was pending, not successful.
- Read-only database inspection on H86 (`ns3140186`) found project 20 (`yuz-tra-wp`)
  with zero assigned runners. Active runners 1 and 2 belong to project 24 and
  reject untagged jobs. They were neither borrowed nor reconfigured.
- Project/group variable key inventory contained no credentials for this project.
- Public GitHub `main` was still unprotected at `d358772c1397007cde040a729410b5f8a38b1b54`.
  The hardening branch was not pushed to GitHub. No authenticated GitHub
  administration tool or local token was available.
- Local WordPress 6.5 and 7.1 / PHP 8.3 acceptance passed, including actual HTTP
  and Plugin Check (0 errors, 1673 warnings per run). PHP 8.1 matrix execution
  remains a remote-CI requirement, not a local proof.
- Wazuh 4.14.4 isolated parser tests matched 110861/12 (mismatch), 110862/10
  (unverified), and 110860/3 (verified). Existing manager custom rules did not
  contain these IDs when inspected. No production rule or collector was installed.

## Required activation decisions

1. Connect a GitHub identity allowed to push the review branch and administer
   this repository. Review/import/apply the Terraform plan using protected state;
   do not replace unrelated rules. Merge only after the required checks pass.
2. Provision a project-scoped H86 runner or explicitly authorise a suitable existing
   runner assignment. Use an isolated Docker executor: non-privileged jobs, no host
   Docker socket or host secrets mounted into jobs, bounded concurrency/resources,
   restricted project access. Supply matching runner tags in this CI only after the
   runner identity/tags have been verified. Do not weaken another project's isolation.
3. Verify revocation of the historical LibreTranslate credential. Prepare a distinct
   plugin version for dependency remediation; do not overwrite submitted 1.5.6.
4. Route the release-observer log into the central Wazuh collector through its
   configuration owner, then test actual alert delivery and acknowledgement.

Neither a successful Git push nor Terraform validation proves these live controls.
No WordPress.org re-submission, public release, production WordPress deployment,
Terraform apply or destructive automated response occurred during this hardening.
