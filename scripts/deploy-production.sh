#!/usr/bin/env bash
set -Eeuo pipefail
source /etc/aura-deploy/runtime.env
repo=/var/www/aura-estate
backup_root=/var/backups/aura-deploy
build_root=/var/lib/aura-deploy/backend-builds
deploy_step=bootstrap

report_deploy_error() {
  local exit_code=$?
  local failed_command=${BASH_COMMAND:-unknown}
  printf 'Backend deploy step "%s" failed (exit %s): %s\n' "$deploy_step" "$exit_code" "$failed_command" >&2
  return "$exit_code"
}
trap report_deploy_error ERR

prune_backend_artifacts() {
  local kept=0
  local entry
  local target

  while IFS= read -r -d '' entry; do
    target=${entry#* }
    if (( kept < 2 )); then
      ((kept += 1))
      continue
    fi
    [[ $target == "$backup_root/"backend-* ]] || exit 2
    rm -rf -- "$target"
  done < <(find "$backup_root" -mindepth 1 -maxdepth 1 -type d -name 'backend-*' -printf '%T@ %p\0' | sort -z -nr)

  while IFS= read -r -d '' target; do
    [[ $target == "$build_root/"* ]] || exit 2
    rm -rf -- "$target"
  done < <(find "$build_root" -mindepth 1 -maxdepth 1 -type d -print0)
}
export GIT_SSH_COMMAND="ssh -i /etc/aura-deploy/backend_readonly -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=/etc/aura-deploy/github_known_hosts"
export GIT_TERMINAL_PROMPT=0 COMPOSER_ALLOW_SUPERUSER=1
remote=git@github.com:FiruzHamidov/aura-estate.git

for command in git php composer curl flock tar gzip mysqldump install supervisorctl systemctl; do command -v "$command" >/dev/null; done
test -d "$repo/.git"
test "$(git -C "$repo" branch --show-current)" = main

check_public_api() {
  curl --fail --silent --show-error --max-time 20 https://backend.aura.tj/up >/dev/null
  curl --fail --silent --show-error --max-time 20 \
    'https://backend.aura.tj/api/new-buildings?per_page=1' | php -r '
      $payload = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
      if (!isset($payload["data"]) || !is_array($payload["data"])) {
          fwrite(STDERR, "Residential catalog returned an invalid payload.\n");
          exit(1);
      }
    '
}

wait_for_supervisor_program() {
  local program=$1

  for attempt in {1..15}; do
    if supervisorctl status "$program" | grep -q RUNNING; then return 0; fi
    sleep 2
  done

  supervisorctl status "$program" | grep RUNNING
}

wait_for_realtime_runtime() {
  local attempt

  for attempt in {1..3}; do
    if php scripts/verify-reverb-runtime.php --expect-enabled; then return 0; fi
    if (( attempt < 3 )); then
      echo "Reverb readiness check failed; retrying (${attempt}/3)." >&2
      sleep 3
    fi
  done

  return 1
}

check_realtime_auth_boundaries() {
  local authenticated_status
  local guest_status

  authenticated_status=$(curl --silent --output /dev/null --max-time 20 --write-out '%{http_code}' \
    --request POST https://backend.aura.tj/api/broadcasting/auth \
    --header 'Origin: https://aura.tj' \
    --header 'Accept: application/json' \
    --data-urlencode 'socket_id=1234.5678' \
    --data-urlencode 'channel_name=private-messaging.user.1')
  guest_status=$(curl --silent --output /dev/null --max-time 20 --write-out '%{http_code}' \
    --request POST https://backend.aura.tj/api/guest-support/broadcasting/auth \
    --header 'Origin: https://aura.tj' \
    --header 'Accept: application/json' \
    --data-urlencode 'socket_id=1234.5678' \
    --data-urlencode 'channel_name=private-guest-support.conversation.00000000-0000-4000-8000-000000000000')

  [[ $authenticated_status == 401 ]]
  [[ $guest_status == 401 ]]
}

# Existing generated runtime files and their permissions are not application changes.
git -C "$repo" diff --quiet HEAD -- . ':(exclude)storage/**' ':(exclude)bootstrap/cache/**' || {
  echo 'Uncommitted application changes on the server; deployment stopped.' >&2; exit 1;
}
if [[ ${1:-} == --check ]]; then
  git ls-remote --exit-code "$remote" refs/heads/main >/dev/null
  check_public_api
  echo 'Backend SSH, repository access, tools, health and residential catalog: OK'
  exit 0
fi

sha=${1:?Commit SHA required}
[[ $sha =~ ^[0-9a-f]{40}$ ]] || exit 2
git -C "$repo" fetch --no-tags "$remote" refs/heads/main:refs/remotes/aura-deploy/main
latest=$(git -C "$repo" rev-parse refs/remotes/aura-deploy/main)
if [[ $sha != "$latest" ]]; then
  git -C "$repo" merge-base --is-ancestor "$sha" "$latest" || exit 2
  echo 'A newer main commit exists; skipping this superseded deployment.'
  exit 0
fi
git -C "$repo" merge-base --is-ancestor HEAD "$sha"
install -d -m 700 "$backup_root" "$build_root"
prune_backend_artifacts
available_kb=$(df -Pk /var/www | awk 'NR==2 {print $4}')
(( available_kb > 3 * 1024 * 1024 )) || { echo 'Less than 3 GiB free; deployment stopped.' >&2; exit 1; }
backup=$(mktemp -d "$backup_root/backend-$(date -u +%Y%m%dT%H%M%SZ).XXXXXX")
stage=$(mktemp -d "$build_root/${sha}.XXXXXX")
git -C "$repo" rev-parse HEAD > "$backup/previous-commit"
git -C "$repo" archive HEAD | gzip > "$backup/source.tar.gz"
install -m 600 "$repo/.env" "$backup/environment"
php /usr/local/lib/aura-deploy/backup-database.php "$repo" "$backup/database.sql"
gzip "$backup/database.sql"

git -C "$repo" archive "$sha" | tar -x -C "$stage"
install -m 600 "$repo/.env" "$stage/.env"
cd "$stage"
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts
if [[ -f package-lock.json ]]; then
  npm ci --no-audit --no-fund
  npm run build
fi

maintenance=0
realtime_env_changed=0
finish() {
  result=$?
  trap - EXIT
  if (( result != 0 && realtime_env_changed == 1 )); then
    install -m 600 "$backup/pre-realtime-environment" "$repo/.env" || true
    (cd "$repo" && php artisan config:cache && php artisan queue:restart && php artisan reverb:restart) || true
  fi
  if (( maintenance == 1 )); then (cd "$repo" && php artisan up) || true; fi
  if (( result != 0 )); then
    echo "Backend deployment failed. Source, environment, database and previous dependencies: $backup" >&2
    echo "Failed build retained for diagnosis until the next deploy: $stage" >&2
    echo 'Database rollback is deliberately not automatic; review migration compatibility before recovery.' >&2
  fi
  exit "$result"
}
trap finish EXIT
trap 'exit 143' TERM INT

install -m 644 "$stage/deploy/supervisor/aura-estate-reverb.conf" /etc/supervisor/conf.d/aura-estate-reverb.conf
supervisorctl reread
supervisorctl update
wait_for_supervisor_program aura-estate-reverb
(cd "$stage" && php scripts/verify-reverb-runtime.php)

cd "$repo"
maintenance=1
php artisan down --retry=10
git merge --ff-only "$sha"
if [[ -d vendor ]]; then mv vendor "$backup/vendor"; fi
mv "$stage/vendor" vendor
if [[ -d $stage/public/build ]]; then
  if [[ -d public/build ]]; then mv public/build "$backup/public-build"; fi
  mv "$stage/public/build" public/build
fi
# Rebuild generated manifests against the new dependency set (not stale dev providers).
for manifest in config.php packages.php services.php; do
  if [[ -f bootstrap/cache/$manifest ]]; then mv "bootstrap/cache/$manifest" "$backup/$manifest"; fi
done
php artisan config:clear
php artisan package:discover --ansi
php artisan migrate --force --no-interaction
php artisan config:cache
php artisan route:cache
php artisan view:cache
chown -R www-data:www-data bootstrap/cache
systemctl reload php8.2-fpm
php artisan up
maintenance=0

deploy_step='preserve realtime environment'
install -m 600 "$repo/.env" "$backup/pre-realtime-environment"
deploy_step='enable messaging realtime'
php scripts/enable-messaging-realtime.php
realtime_env_changed=1
deploy_step='cache realtime configuration'
php artisan config:cache
deploy_step='restart queues'
php artisan queue:restart
deploy_step='restart Reverb'
php artisan reverb:restart
deploy_step='wait for queue worker'
wait_for_supervisor_program aura-estate-queue:aura-estate-queue_00
deploy_step='wait for Reverb worker'
wait_for_supervisor_program aura-estate-reverb
deploy_step='verify Reverb runtime'
wait_for_realtime_runtime
deploy_step='verify realtime auth boundaries'
check_realtime_auth_boundaries
deploy_step='verify public API'
check_public_api
realtime_env_changed=0
deploy_step='record deployed commit'
printf '%s\n' "$sha" > /var/lib/aura-deploy/backend.current
prune_backend_artifacts
echo "Backend deployed: $sha; recovery backup: $backup"
