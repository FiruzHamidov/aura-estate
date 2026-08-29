# Production deployment

Pushes to `main` and manual runs of **Deploy production** deploy `FiruzHamidov/aura-estate` to `https://backend.aura.tj`. Other branches do not deploy. The workflow uses the `production` environment and read-only GitHub permissions.

Required repository Actions secrets: `DEPLOY_HOST`, `DEPLOY_PORT`, `DEPLOY_USER`, `DEPLOY_SSH_KEY`, `DEPLOY_KNOWN_HOSTS`. The dedicated SSH key has `restrict` and a forced `/usr/local/sbin/aura-deploy-dispatch backend` command. Only `check` and `deploy <40-character SHA>` are allowed. A separate read-only GitHub key is installed at `/etc/aura-deploy/backend_readonly`; host keys are pinned. The personal GitHub token and root password are not used by Actions.

## Deployment sequence

1. Verify `/var/www/aura-estate` is on `main`, source files are clean, history can fast-forward, and at least 3 GiB of disk space remains. Existing generated `storage` and `bootstrap/cache` files are preserved.
2. Save the previous commit, source archive, protected `.env` copy and a full MySQL dump under `/var/backups/aura-deploy/`. A failed database backup stops the deployment.
3. Prepare Composer production dependencies and Vite assets in `/var/lib/aura-deploy/backend-builds/`, before entering maintenance mode.
4. Enter maintenance mode, fast-forward the source checkout, retain previous dependencies/assets in the backup, then install prepared dependencies/assets.
5. Rebuild generated manifests, run `php artisan migrate --force`, and rebuild configuration, route and view caches.
6. Signal Aura queue workers to restart, gracefully reload PHP 8.2 FPM, leave maintenance mode, and check `/up`, the public `/api/new-buildings` JSON contract and the Aura Supervisor worker.

The source directory, `.env`, persistent `storage`, public storage link, existing scheduler and Supervisor configuration remain in place. Other applications are not deployed. Both Aura repositories use one server lock to prevent overlapping builds/migrations. Superseded commits are skipped.

## Recovery and maintenance

Use Actions → Deploy production → Run workflow → `main` to retry. The installed entry point `/usr/local/lib/aura-deploy/backend.sh` comes from `scripts/deploy-production.sh`; `/usr/local/lib/aura-deploy/backup-database.php` performs the database backup. Runtime configuration lives in `/etc/aura-deploy/`. Updating installed deployment tooling requires administrator review/reinstallation.

Failures attempt to leave maintenance mode and report the backup path. Database and source rollback are **not automatic**: an administrator must check migration compatibility before restoring source, dependencies or the database. Never restore a database blindly over new user writes. Prefer a forward fix when possible.

Backups and build directories are deliberately retained for review. Monitor disk space and manually remove only old, validated artifacts; do not remove the current backup while a deployment is running. Backup directories contain sensitive environment and database data and must remain root-only. Rotate keys independently of application deploys.

The deploy backup contains the database, protected environment, source and replaced runtime artifacts. It does **not** copy `storage/app`: production media must have a separate, verified filesystem/object-storage snapshot and restore procedure before a residential release. Do not try to archive the production media into `/var/backups/aura-deploy` without a capacity plan.
