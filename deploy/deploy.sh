#!/usr/bin/env bash
#
# Deploy a release of the institute POS.
#
# Run on the server, from the application directory:
#
#   sudo -u institute bash deploy/deploy.sh
#
# The order below is the whole point, and it is not negotiable:
#
#   backup  ->  maintenance on  ->  code  ->  migrate  ->  caches
#           ->  maintenance off ->  health check       ->  verdict
#
# The backup happens BEFORE the migration, every time, with no flag to skip it.
# `docs/DEPLOYMENT.md` has always advised taking one "before every upgrade" and
# in the whole life of this project nobody ever did, because advice that depends
# on a person remembering is not a control. Here it is a step that must succeed
# or the deploy does not start.
#
# On failure the code is rolled back to the commit that was live before, and
# maintenance mode is LEFT ON. That is deliberate: a half-migrated database
# behind a working login screen is worse than a maintenance page, because the
# counter will start taking money against it.
#
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/institute}"
PHP="${PHP:-/usr/bin/php8.4}"
BRANCH="${BRANCH:-main}"
HEALTH_URL="${HEALTH_URL:-http://127.0.0.1/up}"
BUILD_ASSETS="${BUILD_ASSETS:-auto}"   # auto | yes | no

cd "$APP_DIR"

log()  { printf '\n\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[!]\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31m[x]\033[0m %s\n' "$*" >&2; exit 1; }

PREVIOUS="$(git rev-parse HEAD)"

rollback_code() {
    warn "Rolling the code back to ${PREVIOUS:0:8}"
    git reset --hard "$PREVIOUS" >/dev/null
    composer install --no-dev --optimize-autoloader --no-interaction --quiet || true
    $PHP artisan config:cache >/dev/null 2>&1 || true
    $PHP artisan route:cache  >/dev/null 2>&1 || true
    $PHP artisan view:cache   >/dev/null 2>&1 || true
    sudo systemctl reload php8.4-fpm 2>/dev/null || true
}

# ---------------------------------------------------------------------------
# 0. Refuse to deploy something that is not configured
# ---------------------------------------------------------------------------
[[ -f .env ]] || die ".env is missing. See docs/DEPLOYMENT-ORACLE.md §4."

grep -q '^APP_KEY=base64:' .env || die "APP_KEY is not set. Generate it ONCE: php artisan key:generate"

# APP_DEBUG=true in production prints the database password on any error page.
# This is the one misconfiguration that turns a small bug into a breach, so the
# deploy refuses rather than warns.
if grep -qE '^APP_DEBUG=(true|1)' .env; then
    die "APP_DEBUG is true. Refusing to deploy — an error page would leak config and credentials."
fi

if grep -qE '^APP_ENV=local' .env; then
    die "APP_ENV is local. Refusing to deploy — local exposes the passwordless demo logins."
fi

# provision.sh creates and chowns /var/backups/institute, but the application's
# own default is storage/app/backups — inside the tree this script git-resets
# and chmods. Nothing connected the two, so a box provisioned and deployed
# exactly as scripted wrote its dumps somewhere no operator instruction, and no
# off-box copy job, ever looked. Checked here rather than documented, because
# the whole argument of this file is that a control depending on somebody
# remembering is not a control.
BACKUP_PATH="$(sed -n 's/^BACKUP_PATH=//p' .env | tr -d '"'"'"'"' | tail -1)"

if [[ -z "$BACKUP_PATH" ]]; then
    die "BACKUP_PATH is not set in .env. Set it to the directory provision.sh prepared (/var/backups/institute) — otherwise dumps land inside the application tree this script rewrites."
fi

# ---------------------------------------------------------------------------
# 1. Backup, before anything is touched
# ---------------------------------------------------------------------------
log "Taking a pre-deploy backup"

if ! $PHP artisan backup:run; then
    die "Backup failed. Nothing has been changed. Fix the backup before deploying."
fi

# ---------------------------------------------------------------------------
# 2. Maintenance mode
# ---------------------------------------------------------------------------
# The secret lets whoever is deploying reach the site through the maintenance
# page to smoke-test before the counter does.
SECRET="deploy-$(date +%s)"
log "Maintenance mode on (bypass: /${SECRET})"
$PHP artisan down --secret="$SECRET" >/dev/null 2>&1 || true

finish_failed() {
    warn "Deploy failed. Maintenance mode is LEFT ON deliberately."
    warn "The database may be part-migrated. Check, and if needed restore the dump taken above:"
    warn "  gunzip -c ${BACKUP_PATH}/<newest>.sql.gz | mysql -u institute_pos -p institute_pos"
    warn "Bring the site back only when you are satisfied:  php artisan up"
}
trap 'finish_failed' ERR

# ---------------------------------------------------------------------------
# 3. Code
# ---------------------------------------------------------------------------
log "Fetching ${BRANCH}"
git fetch --quiet origin "$BRANCH"
git reset --hard "origin/${BRANCH}" --quiet
TARGET="$(git rev-parse HEAD)"

log "Installing PHP dependencies"
composer install --no-dev --optimize-autoloader --no-interaction --quiet

# Assets: built here only if the repository does not already carry a build.
# Building in CI and shipping public/build is preferable — npm on a 2-core ARM
# box is the slowest step in this script by a wide margin.
if [[ "$BUILD_ASSETS" == "yes" ]] || { [[ "$BUILD_ASSETS" == "auto" ]] && [[ ! -d public/build ]]; }; then
    log "Building frontend assets"
    command -v npm >/dev/null || die "npm is not installed and public/build is absent."
    npm ci --silent
    npm run build --silent
else
    log "Using the committed asset build"
fi

# ---------------------------------------------------------------------------
# 4. Migrate
# ---------------------------------------------------------------------------
log "Running migrations"
$PHP artisan migrate --force

# ---------------------------------------------------------------------------
# 5. Caches
# ---------------------------------------------------------------------------
# config:cache means .env is no longer read at runtime. Anything changed in
# .env since the last deploy takes effect HERE and nowhere earlier — this is
# the single most common cause of "I changed the setting and nothing happened".
log "Rebuilding caches"
$PHP artisan config:cache >/dev/null
$PHP artisan route:cache  >/dev/null
$PHP artisan view:cache   >/dev/null

chmod -R 775 storage bootstrap/cache

log "Reloading PHP-FPM and restarting the queue"
sudo systemctl reload php8.4-fpm
sudo systemctl restart institute-queue 2>/dev/null || warn "institute-queue is not enabled yet."

# ---------------------------------------------------------------------------
# 6. Back up, then prove it actually works
# ---------------------------------------------------------------------------
$PHP artisan up >/dev/null

log "Health check"
sleep 2

HEALTHY=0
for attempt in 1 2 3 4 5; do
    if curl -sf --max-time 10 "$HEALTH_URL" >/dev/null; then
        HEALTHY=1
        break
    fi
    sleep 3
done

trap - ERR

if [[ $HEALTHY -ne 1 ]]; then
    warn "The application did not answer ${HEALTH_URL} after five attempts."
    $PHP artisan down >/dev/null 2>&1 || true
    rollback_code
    $PHP artisan up >/dev/null 2>&1 || true

    if curl -sf --max-time 10 "$HEALTH_URL" >/dev/null; then
        die "Deploy failed and the previous version is back up. The database was NOT rolled back — check migrations."
    fi

    finish_failed
    die "Deploy failed AND the rollback did not come up. The site is down; restore from the dump."
fi

log "Deployed ${PREVIOUS:0:8} -> ${TARGET:0:8}, healthy."

# A deploy is a change to the thing that holds the money, so it belongs in the
# same log as every other such change.
$PHP artisan tinker --execute="\App\Services\Audit::record('Deployment completed', null, ['subject_label' => '${TARGET:0:8}', 'context' => ['from' => '${PREVIOUS:0:8}', 'to' => '${TARGET}']]);" >/dev/null 2>&1 \
    || warn "Deployed, but the audit entry could not be written."
