#!/usr/bin/env bash
set -Eeuo pipefail
source /etc/aura-deploy/runtime.env
repo=/var/www/aura-estate
export GIT_SSH_COMMAND="ssh -i /etc/aura-deploy/backend_readonly -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=/etc/aura-deploy/github_known_hosts"
export GIT_TERMINAL_PROMPT=0 COMPOSER_ALLOW_SUPERUSER=1
remote=git@github.com:FiruzHamidov/aura-estate.git

for command in git php composer curl flock tar gzip mysqldump; do command -v "$command" >/dev/null; done
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
available_kb=$(df -Pk /var/www | awk 'NR==2 {print $4}')
(( available_kb > 3 * 1024 * 1024 )) || { echo 'Less than 3 GiB free; deployment stopped.' >&2; exit 1; }
install -d -m 700 /var/backups/aura-deploy /var/lib/aura-deploy/backend-builds
backup=$(mktemp -d "/var/backups/aura-deploy/backend-$(date -u +%Y%m%dT%H%M%SZ).XXXXXX")
stage=$(mktemp -d "/var/lib/aura-deploy/backend-builds/${sha}.XXXXXX")
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
finish() {
  result=$?
  trap - EXIT
  if (( maintenance == 1 )); then (cd "$repo" && php artisan up) || true; fi
  if (( result != 0 )); then
    echo "Backend deployment failed. Source, environment, database and previous dependencies: $backup" >&2
    echo 'Database rollback is deliberately not automatic; review migration compatibility before recovery.' >&2
  fi
  exit "$result"
}
trap finish EXIT
trap 'exit 143' TERM INT
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
php artisan queue:restart
systemctl reload php8.2-fpm
php artisan up
maintenance=0
check_public_api
for attempt in {1..10}; do
  if supervisorctl status aura-estate-queue:aura-estate-queue_00 | grep -q RUNNING; then break; fi
  sleep 2
done
supervisorctl status aura-estate-queue:aura-estate-queue_00 | grep RUNNING
printf '%s\n' "$sha" > /var/lib/aura-deploy/backend.current
echo "Backend deployed: $sha; recovery backup: $backup"
