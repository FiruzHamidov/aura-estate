#!/usr/bin/env bash
# A short-lived PHP process may read its own application configuration, never run as root.
set -euo pipefail
source_dir=$1
cache_dir=$2
env_copy=$3
shift 3
app=/var/www/aura-estate
exec systemd-run --quiet --wait --pipe --collect --service-type=exec \
  -p User=www-data -p Group=www-data -p NoNewPrivileges=yes -p PrivateTmp=yes \
  -p UMask=0027 -p ProtectSystem=strict -p ProtectHome=yes -p CapabilityBoundingSet= \
  -p "BindReadOnlyPaths=$source_dir:$app" -p "BindReadOnlyPaths=$env_copy:$app/.env" \
  -p "BindPaths=$app/storage:$app/storage $cache_dir:$app/bootstrap/cache" \
  -p "WorkingDirectory=$app" /usr/bin/php "$@"
