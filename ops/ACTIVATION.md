# Activation evidence — 2026-09-15

## Follow-up after explicit execution authorisation

Runner 3 (`yuz-tra-isolated-docker`) has now been created through GitLab's runner
creation service and assigned exclusively to project 20. Its authentication was
verified on H86. The daemon is `yuz-tra-runner`; the root-only configuration is
outside this repository. The temporary credential file was removed after setup.
Jobs use tag `yuz-tra-ci`, one concurrent job, non-privileged Docker, no host socket
or credentials mounted in jobs, 1 GiB memory and 2 CPU limits. Existing Terraform
runners were not changed. Pipeline 70 started on this runner for commit `8c559bc`.
Use the actual pipeline result, not this start observation, as the acceptance gate.

## Initial observations before runner provisioning

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
2. Runner provisioning is complete as described above. Verify successful jobs for
   the exact branch commit. Do not broaden this runner to other projects or weaken
   another project's isolation.
3. Verify revocation of the historical LibreTranslate credential. Prepare a distinct
   plugin version for dependency remediation; do not overwrite submitted 1.5.6.
4. Route the release-observer log into the central Wazuh collector through its
   configuration owner, then test actual alert delivery and acknowledgement.

Neither a successful Git push nor Terraform validation proves these live controls.
No WordPress.org re-submission, public release, production WordPress deployment,
Terraform apply or destructive automated response occurred during this hardening.
