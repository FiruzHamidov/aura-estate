#!/usr/bin/env bash
# Root-owned forced command shared by the two restricted Actions keys.
set -euo pipefail
case "${1:-}" in
  backend) script=/usr/local/lib/aura-deploy/backend.sh ;;
  front) script=/usr/local/lib/aura-deploy/frontend.sh ;;
  *) exit 2 ;;
esac
case "${SSH_ORIGINAL_COMMAND:-}" in
  check) exec /usr/bin/timeout 90 /bin/bash "$script" --check ;;
  build-config)
    source /etc/aura-deploy/runtime.env
    export RESIDENTIAL_UI_MODE RESIDENTIAL_UI_PILOT_IDS
    exec /usr/local/bin/aura-node /usr/local/lib/aura-deploy/public-build-config.cjs "$1" ;;
esac
if [[ ${SSH_ORIGINAL_COMMAND:-} =~ ^deploy-bundle\ ([0-9a-f]{40})\ ([0-9a-f]{64})\ ([0-9a-f]{64})$ ]]; then
  sha=${BASH_REMATCH[1]} config=${BASH_REMATCH[2]} archive=${BASH_REMATCH[3]}
  IFS= read -r -n 16385 -t 30 token && (( ${#token} <= 16384 )) || exit 2
  printf '%s' "$token" | /usr/local/bin/aura-node /usr/local/lib/aura-deploy/verify-delivery.cjs "$1" "$sha" "$config" "$archive"
  unset token
  exec /usr/bin/flock -w 1800 /run/lock/aura-production-deploy.lock \
    /usr/bin/timeout 3600 /bin/bash "$script" "$sha" "$config" "$archive"
fi
echo 'Expected check, build-config or deploy-bundle <commit> <config digest> <archive digest>.' >&2
exit 2
