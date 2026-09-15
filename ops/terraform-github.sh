#!/usr/bin/env bash
# Read credentials privately; never pass them as backend arguments or log them.
set +x
set -euo pipefail
umask 077
case "${1:-}" in
  init|validate|import|plan|state|fmt) ;;
  *) echo 'Only init/validate/import/plan/state/fmt are enabled; no apply or destroy.' >&2; exit 2 ;;
esac
if [[ "$1" == state && "${2:-}" != list ]]; then
  echo 'Only state list is enabled.' >&2; exit 2
fi
auth=/home/ubuntu/.config/yuz-tra/terraform-auth.json
[[ $(stat -c %a "$auth") == 600 ]]
export TF_HTTP_USERNAME
export TF_HTTP_PASSWORD
export GITHUB_TOKEN
TF_HTTP_USERNAME=$(jq -er '.username' "$auth")
TF_HTTP_PASSWORD=$(jq -er '.password' "$auth")
GITHUB_TOKEN=$(gh auth token --hostname github.com)
export TF_VAR_release_reviewer=Youzurz
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../infra/github" && pwd)
exec "${YUZ_TERRAFORM_BIN:-terraform}" -chdir="$root" "$@"
