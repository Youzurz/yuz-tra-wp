# Repository protections — prepared, not applied

This module manages only YUZ-TRA repository rules and its release approval environment.
It does not create/recreate the repository, deploy WordPress, or change DNS/VPN.

1. Authenticate through a GitHub account authorised to administer `Youzurz/yuz-tra-wp`.
   Do not commit tokens, state, saved plans or credentials. Use a protected, encrypted,
   locked state backend approved by infrastructure operations before any apply.
2. Inspect existing rulesets and environments. Import matching existing resources into
   state rather than duplicating or replacing them. Import environment ID:
   `yuz-tra-wp:release`; ruleset IDs: `yuz-tra-wp:<observed-id>`.
3. Run `terraform init`, `terraform validate`, then
   `terraform plan -var='release_reviewer=<actual-login>' -out=<protected-plan-path>`.
4. Review the plan, identity and access impact. Apply only this reviewed plan with
   explicit production-configuration authority. No unattended apply is provided.
5. Test a PR with a failing required check, a tag rewrite refusal, and a manual
   release stopped at approval before declaring protection operational.

Single-maintainer default: PR + strict checks + explicit release approval, not a
claim of independent review. Set `independent_reviews=1` once a second reviewer
exists. The release script independently refuses an unprotected environment.

GitHub is currently the public source; the existing GitLab mirror remains
fast-forward-only. An internal feature branch does not change that direction.
Generalise this module only after each plugin's repository and checks are inventoried.
