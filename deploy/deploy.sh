#!/usr/bin/env bash
#
# Deploy a release of the institute POS.
#
# Run by the Azure DevOps pipeline, and by a human in exactly the same way:
#
#   sudo -u institute RELEASE_SHA=<commit> BUILD_ASSETS=no \
#     bash /var/www/institute/deploy/deploy.sh
#
# The order below is the whole point, and it is not negotiable:
#
#   preflight  ->  backup  ->  maintenance on  ->  code  ->  assets
#              ->  migrate ->  caches -> maintenance off -> health -> verdict
#
# PREFLIGHT refuses. Everything that can be known before the site is touched is
# checked before the site is touched: the release SHA exists, the staged build
# exists, the environment is safe, the clock is synchronised, MySQL answers,
# the disk has room. A deploy that was always going to fail should fail while
# the counter is still open, not halfway through with the site in maintenance
# mode and a database that may or may not be part-migrated.
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
# THE DATABASE IS NEVER AUTOMATICALLY ROLLED BACK. Code rollback and database
# rollback are different decisions and this script only ever makes the first
# one. A part-migrated financial database is a thing a person looks at.
#
set -euo pipefail

# ---------------------------------------------------------------------------
# Run from a copy, because §3 rewrites this file while it is executing
# ---------------------------------------------------------------------------
# `git reset --hard` at §3 checks out the release over the working tree, and
# this script is IN that working tree. Bash does not read a script into memory
# up front — it reads it in chunks, tracking a byte offset — so a release that
# changes deploy.sh can have the shell resume at that offset inside different
# content, part-way through a deploy, with the site already in maintenance mode.
# Whether it survives depends on whether git happened to replace the file or
# truncate it in place, which is not a property to bet a money system on.
#
# So the first thing a deploy does is copy itself to a temp file and hand over.
# The copy is outside the working tree, so the checkout cannot touch it.
#
# The copy is unlinked immediately after the handover, not on exit: the running
# shell holds an open descriptor, so the file stays readable until it finishes
# and there is nothing left to clean up if the deploy dies.
#
# Invoked as `bash <path>` rather than executed directly, so a /tmp mounted
# noexec makes no difference.
if [[ -z "${DEPLOY_SELF_COPY:-}" ]]; then
    _self="$(mktemp "${TMPDIR:-/tmp}/institute-deploy.XXXXXXXX")"
    cat "${BASH_SOURCE[0]}" >"$_self"
    export DEPLOY_SELF_COPY="$_self"
    exec bash "$_self" "$@"
fi

rm -f "$DEPLOY_SELF_COPY"

APP_DIR="${APP_DIR:-/var/www/institute}"
PHP="${PHP:-/usr/bin/php8.4}"
HEALTH_URL="${HEALTH_URL:-http://127.0.0.1/up}"
BUILD_ASSETS="${BUILD_ASSETS:-auto}"          # auto | yes | no
BUILD_STAGE_DIR="${BUILD_STAGE_DIR:-/var/www/institute-builds}"
BUILD_STAGE_KEEP="${BUILD_STAGE_KEEP:-3}"
DEPLOY_LOCK="${DEPLOY_LOCK:-/run/institute-deploy.lock}"
MIN_FREE_MB="${MIN_FREE_MB:-2048}"

cd "$APP_DIR"

log()  { printf '\n\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[!]\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31m[x]\033[0m %s\n' "$*" >&2; exit 1; }

# ---------------------------------------------------------------------------
# Deployment lock
# ---------------------------------------------------------------------------
# Two production deploys must never interleave. Azure DevOps' production
# Environment has an exclusive lock, and that is the first layer — but it is a
# control in somebody else's system, and it does not cover the human who SSHes
# in and runs this by hand while a pipeline is mid-release. The server is the
# final authority, so the authority lives here.
#
# `flock` on a file descriptor held for the life of the process: the kernel
# releases it on exit, including on kill -9, so there is no stale lock to clear
# and no timeout to tune. Non-blocking, because a deploy that queues behind
# another deploy is a deploy nobody is watching.
#
# provision.sh creates this file (via /etc/tmpfiles.d/institute.conf) owned by
# the app account, because /run itself is root-owned and this script is not.
# Probed in a brace group first, and the `2>/dev/null` is scoped to that group
# for a reason that cost an hour to find: `exec 9>file 2>/dev/null` applies BOTH
# redirections to the current shell, so the stderr silencing is permanent. Every
# subsequent die() writes into /dev/null and the script exits 1 having printed
# nothing at all — a deploy that refuses without saying why, which is worse than
# one that crashes.
#
# `>>` rather than `>`, so probing does not truncate a lock another process is
# holding. flock does not care about the file's contents, only its inode.
if ! { : >>"$DEPLOY_LOCK"; } 2>/dev/null; then
    die "Cannot open the deploy lock at ${DEPLOY_LOCK}.
    provision.sh creates it via /etc/tmpfiles.d/institute.conf. If this box was
    provisioned before that existed, run:  sudo systemd-tmpfiles --create"
fi

exec 9>>"$DEPLOY_LOCK"

# Distinguished from "the lock is held", because the two need opposite actions
# and `flock` exiting non-zero cannot tell them apart. Reporting a missing
# binary as a concurrent deploy sends the operator hunting for a process that
# was never there — and the honest failure, "this box is not provisioned",
# is one they can actually fix.
#
# flock ships in util-linux and is present on every Ubuntu install; this fires
# on a developer's macOS laptop, or a container built from a minimal base.
command -v flock >/dev/null || die "flock is not installed, so this deploy cannot take the concurrency lock.
    Refusing rather than proceeding unlocked: two overlapping releases
    interleaving a git reset, a migration and a cache rebuild is the failure
    this lock exists to prevent, and a deploy is exactly the wrong time to
    discover the guard was absent.
    On Ubuntu:  sudo apt-get install -y util-linux"

flock --nonblock 9 || die "Another deploy is already running (lock held on ${DEPLOY_LOCK}).
    Refusing to start — two overlapping releases would interleave a git reset,
    a migration and a cache rebuild against each other.
    Find it with:  ps -ef | grep -- deploy.sh"

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

# ===========================================================================
# 0. PREFLIGHT — everything that can refuse, refuses here
# ===========================================================================
# Nothing below this banner and above §1 may modify the site. No backup, no
# maintenance mode, no git, no assets, no migration. Reaching §1 means every
# knowable precondition held.

log "Preflight"

# ---------------------------------------------------------------------------
# 0.1 The exact commit, mandatory, with no fallback
# ---------------------------------------------------------------------------
# This used to be `git reset --hard origin/main`, which is a race with a money
# system on the end of it: commit A passes CI and gets approved, someone pushes
# B while the approval sits waiting, the SSH stage runs, and you have deployed
# B — untested, unapproved, and indistinguishable in the pipeline log from the
# release that was actually authorised.
#
# The pipeline passes $(Build.SourceVersion), the exact revision it tested.
# There is deliberately no default: a missing RELEASE_SHA is a broken pipeline,
# and quietly deploying HEAD of main instead is the failure this replaces.
: "${RELEASE_SHA:?RELEASE_SHA is required — the pipeline must pass the approved commit, and there is no fallback to origin/main}"

# ---------------------------------------------------------------------------
# 0.2 Configuration
# ---------------------------------------------------------------------------
[[ -f .env ]] || die ".env is missing. See docs/DEPLOYMENT.md §4."

# Read a key from .env, distinguishing ABSENT from PRESENT-BUT-EMPTY.
#
# That distinction is the whole reason this is a function rather than a grep.
# Several of these keys have a config default that is unsafe in production —
# `TRUSTED_PROXIES` defaults to `*` and `DB_CONNECTION` to `sqlite` — so a
# missing line is not "unset", it is "the dangerous value, silently". Grepping
# for the wrong value would pass a file that never mentions the key at all.
#
# Returns the value, or the literal string __ABSENT__ if there is no such key.
env_value() {
    local key="$1" line
    line="$(grep -E "^[[:space:]]*${key}=" .env | tail -1 || true)"
    [[ -z "$line" ]] && { printf '__ABSENT__'; return; }
    # Strip the key, surrounding quotes, a trailing inline comment and spaces.
    printf '%s' "$line" \
        | sed -E "s/^[[:space:]]*${key}=//" \
        | sed -E 's/[[:space:]]+#.*$//' \
        | sed -E 's/^"(.*)"$/\1/; s/^'"'"'(.*)'"'"'$/\1/' \
        | sed -E 's/[[:space:]]+$//'
}

# Assert an exact value. $3 is the config default that applies when the key is
# absent, so the message can say what you would actually have got.
assert_env() {
    local key="$1" want="$2" why="$3" got
    got="$(env_value "$key")"
    if [[ "$got" == "__ABSENT__" ]]; then
        die "${key} is not set in .env (wanted ${want}).
    ${why}"
    fi
    [[ "$got" == "$want" ]] || die "${key}=${got} in .env, but production requires ${key}=${want}.
    ${why}"
}

ENV_APP_URL="$(env_value APP_URL)"

log "Checking the environment"

# Every row of this table was previously a line in a deployment document. A
# document is read once, by the person who wrote it; a refusal is read at
# midnight by whoever is actually deploying.
assert_env APP_ENV       production "APP_ENV=local exposes the passwordless demo logins and their server-side shortcuts."
assert_env APP_DEBUG     false      "APP_DEBUG=true prints the resolved config, including DB_PASSWORD, on any error page."
assert_env DB_CONNECTION mysql      "The config default is sqlite. Wrong here and the counter silently runs on a file nothing backs up."
assert_env DB_HOST       127.0.0.1  "The app would reach a database that is not the one being dumped hourly."
assert_env DB_TIMEZONE   +00:00     "TIMESTAMP and DATETIME columns disagree without it, and a payment taken near midnight lands in the wrong month's revenue."
assert_env SESSION_DRIVER    database "Sessions on the file driver do not survive a deploy; every signed-in officer is logged out mid-transaction."
assert_env CACHE_STORE       database "The scheduler's withoutOverlapping lock lives in the cache store. On the array driver it is a no-op."
assert_env QUEUE_CONNECTION  database "There is no Redis on this box; any other driver silently drops queued work."
assert_env SESSION_SECURE_COOKIE true "The session cookie would travel in clear. Bootstrap order: deploy once with false, run certbot, set true, deploy again."
assert_env BACKUP_KEEP       168      "BACKUP_KEEP prunes by COUNT, not age. Backups are hourly now, so 14 keeps FOURTEEN HOURS while every check stays green."

# APP_KEY: present, non-empty, and actually a key.
APP_KEY_VALUE="$(env_value APP_KEY)"
[[ "$APP_KEY_VALUE" == "__ABSENT__" || -z "$APP_KEY_VALUE" ]] && die "APP_KEY is empty.
    Generate it ONCE:  php artisan key:generate
    Then store it off-box immediately (docs/PRODUCTION-EMERGENCY.md). It decrypts
    every stored TOTP secret; a database restore without it locks out every
    enrolled account."
[[ "$APP_KEY_VALUE" == base64:* ]] || die "APP_KEY is set but is not a base64: key. Laravel cannot decrypt anything with it."

# APP_URL must be the real https address: the password-reset link is built from
# it rather than from the Host header the caller claims, which is what stops an
# attacker aiming a genuine reset token at their own server.
[[ "$ENV_APP_URL" == https://* ]] || die "APP_URL=${ENV_APP_URL} — production requires an https:// URL.
    Password-reset links are built from this value."

# BACKUP_PATH: provision.sh creates and chowns /var/backups/institute, but the
# application's own default is storage/app/backups — inside the tree this script
# git-resets and chmods. Nothing connected the two, so a box provisioned and
# deployed exactly as scripted wrote its dumps somewhere no operator
# instruction, and no off-box copy job, ever looked.
BACKUP_PATH="$(env_value BACKUP_PATH)"
[[ "$BACKUP_PATH" == "__ABSENT__" || -z "$BACKUP_PATH" ]] && die "BACKUP_PATH is not set in .env.
    Set it to the directory provision.sh prepared (/var/backups/institute) — the
    fallback writes dumps inside the application tree this script rewrites."
[[ -d "$BACKUP_PATH" ]] || die "BACKUP_PATH=${BACKUP_PATH} does not exist. provision.sh creates it."
[[ -w "$BACKUP_PATH" ]] || die "BACKUP_PATH=${BACKUP_PATH} is not writable by $(id -un).
    The mandatory pre-deploy dump would fail and this deploy would refuse anyway."

# TRUSTED_PROXIES: `*` believes whoever is speaking, so a forged
# X-Forwarded-For steps around the per-IP login brake. That is correct only on a
# platform whose edge is the sole way in; this box is directly reachable.
# Absent means `*`, because that is the config default — hence env_value's
# __ABSENT__ rather than a bare grep.
TRUSTED_PROXIES="$(env_value TRUSTED_PROXIES)"
if [[ "$TRUSTED_PROXIES" == "__ABSENT__" ]]; then
    die "TRUSTED_PROXIES is not set in .env, and config/app.php defaults it to '*'.
    That believes any caller's X-Forwarded-For, which steps around the per-IP
    login brake. Set TRUSTED_PROXIES=127.0.0.1 — nginx is on this box."
fi
[[ "$TRUSTED_PROXIES" == "*" || "$TRUSTED_PROXIES" == "**" ]] && die "TRUSTED_PROXIES=${TRUSTED_PROXIES} trusts every caller's X-Forwarded-For header.
    A forged header then steps around the per-IP login brake. Use 127.0.0.1."

# INSTITUTE_TODAY pins "today". Set, every overdue date and every this-month
# figure freezes on that date and stays frozen — quietly, and looking correct.
INSTITUTE_TODAY="$(env_value INSTITUTE_TODAY)"
if [[ "$INSTITUTE_TODAY" != "__ABSENT__" && -n "$INSTITUTE_TODAY" ]]; then
    die "INSTITUTE_TODAY=${INSTITUTE_TODAY} pins the application's idea of 'today'.
    Every overdue date and every this-month figure would freeze on it. It exists
    to reproduce the prototype's demo figures and must be BLANK in production."
fi

# ---------------------------------------------------------------------------
# 0.3 The commit must actually exist, before anything is touched
# ---------------------------------------------------------------------------
log "Fetching and verifying ${RELEASE_SHA:0:8}"

# EVERY remote, not just `origin`, and a failure to reach one is not fatal.
#
# This box has two: `origin` on GitHub and `local` on /srv/pos.git, the bare
# repo a release is pushed to over SSH. Fetching only `origin` meant a commit
# pushed to `local` — which is how this box is actually released — was reported
# as "not found after fetching origin", a message that sends the operator
# looking for a push that had already happened.
#
# Tolerating an unreachable remote matters just as much. `git fetch origin`
# under `set -e` aborted the whole deploy when GitHub was unreachable, even
# though the commit was sitting in `local` and nothing about the release needed
# GitHub at all. A remote that cannot be reached is now a warning, and only the
# commit still being absent afterwards is fatal.
_fetched=""
_unreachable=""

for _remote in $(git remote); do
    if git fetch --prune --quiet "$_remote" 2>/dev/null; then
        _fetched="${_fetched} ${_remote}"
    else
        _unreachable="${_unreachable} ${_remote}"
        warn "Could not fetch the remote '${_remote}'; carrying on with the others."
    fi
done

[[ -n "${_fetched}" || -z "${_unreachable}" ]] \
    || die "No remote could be reached (tried:${_unreachable}).
    Nothing has been touched. Check the network and the deploy key."

git cat-file -e "${RELEASE_SHA}^{commit}" 2>/dev/null \
    || die "Release SHA ${RELEASE_SHA} was not found in any remote.
    Fetched:${_fetched:- none}. Unreachable:${_unreachable:- none}.
    Nothing has been touched. Either the commit was never pushed, or the value
    is a typo. A bad SHA must refuse here rather than after the site is already
    in maintenance mode.
    Push it with:  git push ssh://<this box>/srv/pos.git <branch>"

# ---------------------------------------------------------------------------
# 0.4 There must be a way to get compiled assets, and it is decided HERE
# ---------------------------------------------------------------------------
# CI builds public/build and the pipeline copies it to
# /var/www/institute-builds/<sha>/. The manifest is Vite's own output and is
# what Laravel's @vite directive reads, so its absence means the staged
# directory is empty, partial, or the wrong shape.
#
# The DECISION is made here rather than at §3b, and §3b only carries it out.
# That is the whole preflight promise: a deploy that cannot produce assets must
# refuse while the counter is still open, not stop halfway with the site in
# maintenance mode. It used to decide at §3b, and the failure was exactly that —
# `auto` on a box with an existing public/build and no staged build fell into
# the "activate the staged build" branch and died there, with the site already
# down and the code already checked out.
#
# ASSET_PLAN is one of:
#   staged  a build for this exact commit is waiting; copy it in
#   keep    public/build is already correct, because NOTHING that compiles into
#           it differs between the running commit and the release
#   build   compile on the box with npm
STAGED_BUILD="${BUILD_STAGE_DIR}/${RELEASE_SHA}"

# The paths whose contents end up in public/build. If none of them differs
# between what is running and what is being released, the build that is already
# on disk is the build this release wants — not "probably", but by construction,
# because Vite's output is a function of exactly these inputs.
#
# public/ is included whole and deliberately over-broadly: public/build itself
# is gitignored and therefore not in the tree, so this compares the static files
# served beside it and never the artefact being reasoned about.
frontend_unchanged() {
    local from="$1" to="$2"

    [[ -n "$from" ]] || return 1

    git diff --quiet "$from" "$to" -- \
        resources public package.json package-lock.json \
        vite.config.js tailwind.config.js postcss.config.js 2>/dev/null
}

if [[ "$BUILD_ASSETS" == "yes" ]]; then
    # An explicit instruction to compile, which outranks anything on disk.
    ASSET_PLAN=build
elif [[ -f "${STAGED_BUILD}/manifest.json" ]]; then
    # A build made for this exact commit is the best answer there is.
    ASSET_PLAN=staged
elif [[ "$BUILD_ASSETS" == "no" ]]; then
    die "No staged build for ${RELEASE_SHA:0:8}.
    Expected:  ${STAGED_BUILD}/manifest.json
    BUILD_ASSETS=no means this box does not compile assets; the pipeline stages
    them. Either the Build stage did not run, the artifact did not copy, or the
    SHA does not match the one that was built.
    Staged builds present: $(ls -1 "$BUILD_STAGE_DIR" 2>/dev/null | tr '\n' ' ' || echo none)"
elif [[ -f public/build/manifest.json ]] && frontend_unchanged "$PREVIOUS" "$RELEASE_SHA"; then
    # Nothing that compiles into public/build changed, so what is on disk is
    # already right. Chosen ABOVE compiling, not below it: a PHP-only release
    # should not need the npm registry to be reachable, and re-running a build
    # to produce the bytes already sitting there is a network dependency and two
    # minutes bought for nothing.
    ASSET_PLAN=keep
elif command -v npm >/dev/null; then
    ASSET_PLAN=build
else
    die "There is no way to get frontend assets for ${RELEASE_SHA:0:8}.
    No staged build at ${STAGED_BUILD}, npm is not installed, and the frontend
    HAS changed since ${PREVIOUS:0:8}, so the build already in public/build
    belongs to the old release and must not be served with the new code.
    Either stage a build, or install npm on this box."
fi

if [[ "$ASSET_PLAN" == "build" ]]; then
    command -v npm >/dev/null || die "BUILD_ASSETS=${BUILD_ASSETS} needs npm, which is not installed."
fi

log "Assets: ${ASSET_PLAN}"

# ---------------------------------------------------------------------------
# 0.5 The clock — two-factor authentication depends on it
# ---------------------------------------------------------------------------
# Not housekeeping. TOTP codes are computed from this clock, so drift locks out
# every enrolled account simultaneously, /superadmin included, with no way back
# in through the application. Refusing to deploy onto a drifting box is cheaper
# than discovering it when nobody can sign in.
if command -v timedatectl >/dev/null; then
    timedatectl show --property=NTPSynchronized --value 2>/dev/null | grep -q '^yes$' \
        || die "The system clock is NOT synchronised (timedatectl: NTPSynchronized=no).
    Two-factor authentication is computed from it, so drift locks every enrolled
    account out at once. Fix time sync first:
      sudo timedatectl set-ntp true && systemctl restart systemd-timesyncd"
fi

# ---------------------------------------------------------------------------
# 0.6 Disk
# ---------------------------------------------------------------------------
# A full disk during a deploy looks like a hundred unrelated bugs, and the first
# thing it breaks is the mandatory backup — which fails closed, so the deploy
# stops anyway, just later and less clearly.
FREE_MB="$(df -Pm "$APP_DIR" | awk 'NR==2 {print $4}')"
[[ "${FREE_MB:-0}" -ge "$MIN_FREE_MB" ]] || die "Only ${FREE_MB}MB free on the volume holding ${APP_DIR}; ${MIN_FREE_MB}MB required.
    The pre-deploy dump, composer's cache and the new asset build all need room.
    Free space before deploying:  df -h ; du -sh ${BACKUP_PATH}"

# ---------------------------------------------------------------------------
# 0.7 The runtime, as the application will actually see it
# ---------------------------------------------------------------------------
# The checks above read .env with shell tools. This one asks Laravel, which is
# the only thing that can answer "can you reach MySQL", "is storage writable"
# and "does the effective config agree" — and answers them using the same
# config resolution the application itself uses.
#
# `config:clear` first, so it reads .env rather than the cache left by the
# PREVIOUS release. The cache is rebuilt in §5 as part of the normal flow; if
# this preflight refuses, it is rebuilt on the way out so the running site is
# not left uncached.
[[ -x "$PHP" ]] || die "PHP is not at ${PHP}. Production is pinned to 8.4 (sudoers names php8.4-fpm)."

PHP_MM="$($PHP -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
[[ "$PHP_MM" == "8.4" ]] || die "PHP ${PHP_MM} at ${PHP}, but production is pinned to 8.4.
    /etc/sudoers.d/institute-deploy grants reload of php8.4-fpm by name, so a
    different runtime ends every deploy in a failed reload."

$PHP artisan config:clear >/dev/null 2>&1 || true

if ! $PHP artisan deploy:preflight; then
    warn "Restoring the previous config cache — nothing was deployed."
    $PHP artisan config:cache >/dev/null 2>&1 || true
    die "Preflight failed. Nothing has been touched."
fi

log "Preflight passed. ${PREVIOUS:0:8} -> ${RELEASE_SHA:0:8}"

# ===========================================================================
# 1. Backup, before anything is touched
# ===========================================================================
# `backup:run` takes /run/institute-backup.lock itself, which is the same lock
# the hourly scheduled dump takes. That is why the lock lives in the command
# rather than in this script: it has to cover every invocation path, and there
# are three (scheduler, deploy, a human at a prompt).
log "Taking a pre-deploy backup"

if ! $PHP artisan backup:run; then
    die "Backup failed. Nothing has been changed. Fix the backup before deploying."
fi

# ===========================================================================
# 2. Maintenance mode
# ===========================================================================
# The secret lets whoever is deploying reach the site through the maintenance
# page to smoke-test before the counter does.
SECRET="deploy-$(date +%s)"
log "Maintenance mode on (bypass: /${SECRET})"
# --render, or `artisan down` looks for a view literally named `503`, fails to
# find one, logs "View [503] not found" and falls back to the framework's
# unbranded default page. See resources/views/errors/503.blade.php for why that
# view has to be standalone HTML rather than using a layout.
$PHP artisan down --secret="$SECRET" --render="errors::503" >/dev/null 2>&1 || true

finish_failed() {
    warn "Deploy failed. Maintenance mode is LEFT ON deliberately."
    warn "The database may be part-migrated. It has NOT been rolled back, and will not be."
    warn "Check, and if needed restore the dump taken above:"
    warn "  gunzip -c ${BACKUP_PATH}/<newest>.sql.gz | mysql -u institute_pos -p institute_pos"
    warn "Bring the site back only when you are satisfied:  php artisan up"
}
trap 'finish_failed' ERR

# ===========================================================================
# 3. Code
# ===========================================================================
log "Checking out ${RELEASE_SHA:0:8}"
git reset --hard "$RELEASE_SHA" --quiet
TARGET="$(git rev-parse HEAD)"

log "Installing PHP dependencies"
composer install --no-dev --optimize-autoloader --no-interaction --quiet

# ---------------------------------------------------------------------------
# 3b. Assets
# ---------------------------------------------------------------------------
# Activated HERE — after maintenance mode is on and after the git reset — and
# never before. Copying a new build over a live site still running old code
# gives a window with a manifest that names files the running Blade templates do
# not reference and vice versa. Content-hashed filenames only *usually* save
# you, and "usually" is not a property you want on the fee voucher.
#
# Replaced rather than merged: a stale chunk left behind from an earlier release
# is a file the manifest no longer names and nothing ever cleans up.
#
# ASSET_PLAN was decided in §0.4, while refusing was still free. This block
# only carries it out.
case "$ASSET_PLAN" in
    staged)
        log "Activating the staged build for ${RELEASE_SHA:0:8}"
        [[ -f "${STAGED_BUILD}/manifest.json" ]] \
            || die "Staged build vanished between preflight and now: ${STAGED_BUILD}"

        rm -rf public/build.incoming public/build.previous
        cp -a "$STAGED_BUILD" public/build.incoming
        [[ -d public/build ]] && mv public/build public/build.previous
        mv public/build.incoming public/build
        rm -rf public/build.previous
        ;;

    keep)
        # Said out loud rather than passed over in silence. "Assets unchanged"
        # is a claim about this release, and an operator reading the log should
        # see it made — not have to infer it from a step that never appeared.
        log "Keeping the existing build: nothing that compiles into it changed since ${PREVIOUS:0:8}"
        [[ -f public/build/manifest.json ]] \
            || die "public/build/manifest.json vanished between preflight and now."
        ;;

    build)
        log "Building frontend assets on the box"
        npm ci --silent
        npm run build --silent
        [[ -f public/build/manifest.json ]] \
            || die "The build finished but produced no public/build/manifest.json."
        ;;

    *)
        die "Internal error: ASSET_PLAN is '${ASSET_PLAN:-unset}'."
        ;;
esac

# ===========================================================================
# 4. Migrate
# ===========================================================================
log "Running migrations"
$PHP artisan migrate --force

# ===========================================================================
# 5. Caches
# ===========================================================================
# config:cache means .env is no longer read at runtime. Anything changed in
# .env since the last deploy takes effect HERE and nowhere earlier — this is
# the single most common cause of "I changed the setting and nothing happened".
log "Rebuilding caches"
$PHP artisan config:cache >/dev/null
$PHP artisan route:cache  >/dev/null
$PHP artisan view:cache   >/dev/null

chmod -R 775 storage bootstrap/cache

# The same assertions again, now against the CACHED config — which is what the
# application will actually serve from. §0.7 proved .env was safe; this proves
# the cache built from it is the same thing. They can differ: a value that
# fails to parse, a stale cache that did not rebuild, an env() call resolved at
# build time. Cheap, and it runs while the site is still in maintenance mode,
# so a failure here stops before the counter sees anything.
log "Re-checking the effective configuration"
$PHP artisan deploy:preflight --effective

log "Reloading PHP-FPM and restarting the queue"
sudo systemctl reload php8.4-fpm
sudo systemctl restart institute-queue 2>/dev/null || warn "institute-queue is not enabled yet."

# ===========================================================================
# 6. Back up, then prove it actually works
# ===========================================================================
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

# The localhost /up check above proves PHP-FPM answered. It cannot prove DNS
# resolves, the firewall lets anyone in, TLS is valid, or that MySQL is
# reachable — every one of which can be broken while /up returns 200.
#
# /ready is the application's own readiness route: it runs SELECT 1 and checks
# the storage paths, and returns a bare 200 or a bare 503. Fetched over the
# PUBLIC https URL, from this box, so it traverses the same path a member of
# staff does.
READY_URL="${READY_URL:-${ENV_APP_URL%/}/ready}"

log "Public readiness check: ${READY_URL}"

READY=0
for attempt in 1 2 3 4 5; do
    if curl -sf --max-time 15 "$READY_URL" >/dev/null; then
        READY=1
        break
    fi
    sleep 3
done

if [[ $READY -ne 1 ]]; then
    # Deliberately NOT a rollback. /up passed, so the application is serving:
    # what failed is DNS, TLS, the firewall or the database, none of which the
    # previous commit would fix and all of which need a person. Maintenance mode
    # is left OFF because the site is, as far as this box can tell, working.
    warn "The site did not answer ${READY_URL} publicly, although it answers ${HEALTH_URL} locally."
    warn "The code is deployed and serving. What is broken is outside the application:"
    warn "  DNS        dig +short \$(echo ${READY_URL} | awk -F/ '{print \$3}')"
    warn "  TLS        curl -vI ${READY_URL} 2>&1 | grep -i 'certificate\|SSL'"
    # On a plain VPS ufw is the ONLY firewall — there is no provider-side
    # network ACL in front of it to catch a mistake here, which is why
    # provision.sh §7 configures it rather than treating it as optional.
    warn "  Firewall   sudo ufw status verbose   (and the provider's own panel, if it has one)"
    warn "  Database   ${PHP} artisan tinker --execute='DB::select(\"select 1\");'"
    die "Deployed, but not reachable from outside. Investigate before telling the counter it is up."
fi

# ---------------------------------------------------------------------------
# 7. Prune the staging area
# ---------------------------------------------------------------------------
# Only after success, and never the SHA just activated. Three is enough to roll
# back by hand to either of the last two releases without a pipeline run.
if [[ -d "$BUILD_STAGE_DIR" ]]; then
    mapfile -t STALE < <(
        find "$BUILD_STAGE_DIR" -maxdepth 1 -mindepth 1 -type d -printf '%T@ %p\n' 2>/dev/null \
            | sort -rn | tail -n +$((BUILD_STAGE_KEEP + 1)) | cut -d' ' -f2-
    )
    for dir in "${STALE[@]:-}"; do
        [[ -z "$dir" ]] && continue
        [[ "$dir" == "$STAGED_BUILD" ]] && continue    # never the live one
        rm -rf "$dir"
        log "Pruned stale staged build $(basename "$dir")"
    done
fi

log "Deployed ${PREVIOUS:0:8} -> ${TARGET:0:8}, healthy locally and publicly."

# A deploy is a change to the thing that holds the money, so it belongs in the
# same log as every other such change.
$PHP artisan tinker --execute="\App\Services\Audit::record('Deployment completed', null, ['subject_label' => '${TARGET:0:8}', 'context' => ['from' => '${PREVIOUS:0:8}', 'to' => '${TARGET}']]);" >/dev/null 2>&1 \
    || warn "Deployed, but the audit entry could not be written."
