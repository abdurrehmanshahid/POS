#!/usr/bin/env bash
#
# Register this box as the GitHub Actions self-hosted runner that deploys it.
#
#   REG_TOKEN=<token> bash deploy/install-runner.sh
#
# Get REG_TOKEN with (it expires in one hour):
#
#   gh api -X POST repos/abdurrehmanshahid/POS/actions/runners/registration-token --jq .token
#
# ---------------------------------------------------------------------------
# Why this is a script and not a paragraph in a document.
# ---------------------------------------------------------------------------
#
# P2-20 reinstalls this box from bare, twice, on purpose. Every runner install
# done by hand is one that has to be remembered and repeated correctly at 2am,
# and the go-live checklist already records what happens to steps that live only
# in somebody's memory. `provision.sh` earned the same treatment for the same
# reason.
#
# ---------------------------------------------------------------------------
# What this deliberately does NOT do.
# ---------------------------------------------------------------------------
#
# It does not grant the runner any privilege beyond the `institute` account.
# The workflow's deploy step runs `bash deploy/deploy.sh` with no `sudo -u`,
# because the runner IS institute; /etc/sudoers.d/institute-deploy grants three
# specific systemctl commands and not a shell. If a job on this runner is ever
# compromised, it can do what institute can do and no more.
#
# It does not put the runner in institute's home. That home is
# /var/www/institute — the git working tree `deploy.sh` runs `git reset --hard`
# against. A runner work directory inside the tree being deployed is a loop
# waiting to happen, so it lives at /opt/actions-runner.
#
set -euo pipefail

REPO_URL="${REPO_URL:-https://github.com/abdurrehmanshahid/POS}"
RUNNER_DIR="${RUNNER_DIR:-/opt/actions-runner}"
APP_USER="${APP_USER:-institute}"
# Must match `runs-on: [self-hosted, institute-prod]` in .github/workflows/ci.yml.
RUNNER_LABELS="${RUNNER_LABELS:-institute-prod}"
RUNNER_NAME="${RUNNER_NAME:-$(hostname)}"

log()  { printf '\n\033[1;34m==>\033[0m %s\n' "$*"; }
die()  { printf '\n\033[1;31m[x]\033[0m %s\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "Run as root; it creates a systemd unit."
[[ -n "${REG_TOKEN:-}" ]] || die "REG_TOKEN is required. See the header for how to mint one."
id -u "$APP_USER" >/dev/null 2>&1 || die "User ${APP_USER} does not exist. Run provision.sh first."

log "Preparing ${RUNNER_DIR}"
mkdir -p "$RUNNER_DIR"
chown "$APP_USER:$APP_USER" "$RUNNER_DIR"

if [[ ! -f "${RUNNER_DIR}/config.sh" ]]; then
    log "Downloading the runner"
    VERSION="$(curl -fsSL https://api.github.com/repos/actions/runner/releases/latest \
        | grep -o '"tag_name": *"v[^"]*"' | head -1 | sed 's/.*"v\([^"]*\)".*/\1/')"
    [[ -n "$VERSION" ]] || die "Could not determine the latest runner version."

    curl -fsSL -o /tmp/actions-runner.tar.gz \
        "https://github.com/actions/runner/releases/download/v${VERSION}/actions-runner-linux-x64-${VERSION}.tar.gz"
    tar xzf /tmp/actions-runner.tar.gz -C "$RUNNER_DIR"
    rm -f /tmp/actions-runner.tar.gz
    chown -R "$APP_USER:$APP_USER" "$RUNNER_DIR"
    log "Runner ${VERSION} unpacked"
else
    log "Runner package already present, reusing it"
fi

# config.sh and svc.sh both resolve ./bin against the CURRENT directory, not
# their own. Run from anywhere else, config.sh prints ldd errors and svc.sh
# refuses with "Must run from runner root or install is corrupt" — after the
# registration has already succeeded, leaving a runner GitHub knows about and
# nothing on the box running it.
cd "$RUNNER_DIR"

if [[ ! -f "${RUNNER_DIR}/.runner" ]]; then
    log "Registering with ${REPO_URL}"
    # --unattended so it never waits on a prompt; --replace so re-running after a
    # rebuild takes over the old registration instead of accumulating dead
    # runners that jobs can still be queued against.
    sudo -u "$APP_USER" "${RUNNER_DIR}/config.sh" \
        --url "$REPO_URL" \
        --token "$REG_TOKEN" \
        --name "$RUNNER_NAME" \
        --labels "$RUNNER_LABELS" \
        --work _work \
        --unattended \
        --replace
else
    log "Already registered, leaving the existing registration alone"
fi

log "Installing the systemd service"
# The runner ships its own installer, which writes a unit that starts at boot.
# Passing the user is what keeps this off root. svc.sh records the unit it
# wrote in .service and refuses to install twice, so a re-run skips straight
# to start instead of dying here.
if [[ ! -f "${RUNNER_DIR}/.service" ]]; then
    ./svc.sh install "$APP_USER"
else
    log "Service already installed ($(cat "${RUNNER_DIR}/.service")), starting it"
fi
./svc.sh start

sleep 3
log "Status"
"${RUNNER_DIR}/svc.sh" status || true

cat <<'NEXT'

Next, and NOT done by this script:

  1. Confirm the runner shows Idle:
       gh api repos/abdurrehmanshahid/POS/actions/runners --jq '.runners[]|{name,status,labels:[.labels[].name]}'

  2. Flip DEPLOY_ENABLED to 'true' in .github/workflows/ci.yml.
     That is deliberately a commit, reviewed like any other, so enabling
     production deploys has an author and a date.

  3. Check the `production` Environment still restricts deployments to
     protected branches. Required reviewers are a paid feature on private
     repositories; where they are unavailable, the human gate is the pull
     request review into `main`, which branch protection already enforces.

NEXT
