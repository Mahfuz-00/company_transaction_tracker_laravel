#!/usr/bin/env bash
#
# PRODUCTION ENTRYPOINT - SAFE, DATA-PRESERVING BOOT.
#
# WHY THIS EXISTS
# ---------------
# The previous start command was `php artisan migrate --force --seed && ...`,
# which was risky for a SQLite deployment on Render: nothing here may ever
# truncate, overwrite or delete the production database file. This script makes
# the boot sequence explicitly non-destructive:
#
#   1. Create `database/database.sqlite` ONLY IF IT DOES NOT EXIST.
#      An existing file (e.g. a mounted Render persistent disk) is NEVER touched.
#   2. Apply migrations ADDITIVELY with `migrate --force`.
#      There is NO `migrate:fresh`, NO `db:wipe`, and NO file reset anywhere.
#   3. Seed ONLY on first boot (when the RBAC permissions are still missing), so
#      a redeploy never re-runs seeders against live data.
#
# A persistent disk should be mounted at the database directory (see render.yaml)
# so the file survives redeploys. This script never depends on that: it simply
# refuses to destroy data that is already there.

set -euo pipefail

cd /var/www/html

DB_CONNECTION="${DB_CONNECTION:-sqlite}"
DB_DATABASE="${DB_DATABASE:-database/database.sqlite}"

# ---------------------------------------------------------------------------
# 1. SQLite file: CREATE IF MISSING, otherwise leave it strictly alone.
# ---------------------------------------------------------------------------
if [ "$DB_CONNECTION" = "sqlite" ]; then
    mkdir -p "$(dirname "$DB_DATABASE")"

    if [ ! -f "$DB_DATABASE" ]; then
        touch "$DB_DATABASE"
        echo "[entrypoint] Created a new SQLite database at ${DB_DATABASE}"
    else
        echo "[entrypoint] Existing SQLite database found at ${DB_DATABASE} - preserving it untouched"
    fi
fi

# ---------------------------------------------------------------------------
# 2. Runtime permissions (best-effort; a mounted disk may not allow chown).
# ---------------------------------------------------------------------------
chown -R www-data:www-data storage bootstrap/cache database 2>/dev/null || true

# ---------------------------------------------------------------------------
# 3. Clear any stale compiled config, then migrate ADDITIVELY.
#    NEVER `migrate:fresh`, NEVER `db:wipe`.
# ---------------------------------------------------------------------------
php artisan config:clear >/dev/null 2>&1 || true

echo "[entrypoint] Running migrations (additive, --force)..."
php artisan migrate --force

# ---------------------------------------------------------------------------
# 4. Seed ONLY on a truly fresh database. `db:seed-if-empty` checks for the RBAC
#    permissions and refuses to touch an existing install, so a redeploy NEVER
#    re-seeds live data.
# ---------------------------------------------------------------------------
php artisan db:seed-if-empty --force || true

# ---------------------------------------------------------------------------
# 5. Start PHP-FPM and Nginx in the foreground.
# ---------------------------------------------------------------------------
echo "[entrypoint] Starting php-fpm + nginx..."
php-fpm -D
exec nginx -g "daemon off;"
