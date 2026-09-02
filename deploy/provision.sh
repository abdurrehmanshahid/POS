#!/usr/bin/env bash
#
# One-shot provisioning for the institute POS on a plain Ubuntu VPS.
#
# Target: Ubuntu 24.04 LTS, x86-64, 4GB RAM / 2 vCPU / 40GB or better. Native
# packages throughout — nginx, PHP-FPM and MySQL run as ordinary systemd
# services. There is no Docker here and nothing to pull at boot: a container
# runtime is one more thing that can fail at 9am on a Monday for reasons
# unrelated to the application.
#
# Nothing here is tied to a provider. It has been rehearsed on AWS Lightsail
# and it wants nothing Lightsail has — no metadata service, no provider SDK,
# no cloud-init hooks — so Hostinger KVM, Contabo, Hetzner or a box under a
# desk are all the same script. What it DOES assume is a CLEAN image: an
# install that already ships nginx, PHP or a control panel (CyberPanel, hPanel,
# a "Laravel" one-click) will fight §1 and §6 for the same ports and config
# paths. Start from bare Ubuntu 24.04.
#
# The one provider-shaped difference is the firewall. Lightsail puts a network
# ACL in front of the instance, so ufw there is a second line of defence; on a
# plain VPS ufw in §7 is the ONLY thing between MySQL and the internet.
#
# This replaces an earlier Oracle Cloud / Ampere A1 target. Nothing here may
# assume ARM64: the packages installed below are all architecture-neutral apt
# packages, and the one third-party installer (NodeSource) selects its own
# architecture. The check in §0 exists so that a future hardcoded download
# cannot quietly reintroduce the assumption.
#
# Safe to re-run. Every step checks before it acts, so this doubles as the
# repair tool when somebody has changed something by hand.
#
#   sudo bash deploy/provision.sh
#
# What it will ask for: nothing. What it will print at the end: the database
# password it generated (once) and the MySQL version (which is the version CI
# has to gate on). Record both.
#
set -euo pipefail

APP_USER="${APP_USER:-institute}"
APP_DIR="${APP_DIR:-/var/www/institute}"
APP_DOMAIN="${APP_DOMAIN:-}"
DB_NAME="${DB_NAME:-institute_pos}"
DB_USER="${DB_USER:-institute_pos}"
VERIFY_DB="${VERIFY_DB:-institute_restore_check}"
PHP_VERSION="8.4"

log()  { printf '\n\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[!]\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31m[x]\033[0m %s\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "Run with sudo."

# ---------------------------------------------------------------------------
# 0. Where are we, actually
# ---------------------------------------------------------------------------
# Stated rather than assumed. The previous target was Ampere A1 (arm64) and the
# current one is not, so an installer that silently fetches the wrong binary is
# a live risk rather than a hypothetical one. Anything added below that
# downloads a release asset must select on this value, not on a literal.
ARCH="$(dpkg --print-architecture)"
log "Architecture: ${ARCH}"

if [[ "$ARCH" != "amd64" ]]; then
    warn "This script is written and rehearsed for amd64 (x86-64); found ${ARCH}."
    warn "Every apt package below is architecture-neutral, so it will most likely work,"
    warn "but nothing here has been proved on ${ARCH}. Proceed knowingly."
fi

grep -q "24.04" /etc/os-release || warn "Not Ubuntu 24.04. The PHP, MySQL and systemd assumptions below are written for it."

# ---------------------------------------------------------------------------
# 1. PHP 8.4
# ---------------------------------------------------------------------------
# Ubuntu 24.04 ships PHP 8.3, and this application cannot run on it: Laravel 13
# pulls in Symfony 8, and twenty of its components declare `php >=8.4.1`. That
# is a hard floor, not a preference — composer install refuses outright. The
# ondrej PPA is the standard source for a newer PHP on Ubuntu and is what the
# CI pipeline already uses, so production and CI agree on the runtime.
log "Installing PHP ${PHP_VERSION} and extensions"
export DEBIAN_FRONTEND=noninteractive

apt-get update -qq
apt-get install -y -qq software-properties-common curl unzip git ca-certificates

if ! grep -rq "ondrej/php" /etc/apt/sources.list.d/ 2>/dev/null; then
    add-apt-repository -y ppa:ondrej/php
    apt-get update -qq
fi

# Extensions chosen deliberately, not left to the distro default:
#   gd + mbstring  — dompdf typesetting the fee voucher and the receipt
#   zip + xml      — phpspreadsheet reading the roll and writing exports
#   mysql          — the only production driver
#   bcmath         — money arithmetic that must not go through floats
#   intl           — date and number formatting
apt-get install -y -qq \
    "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-cli" "php${PHP_VERSION}-mysql" \
    "php${PHP_VERSION}-mbstring" "php${PHP_VERSION}-xml" "php${PHP_VERSION}-curl" \
    "php${PHP_VERSION}-zip" "php${PHP_VERSION}-gd" "php${PHP_VERSION}-bcmath" \
    "php${PHP_VERSION}-intl"

# One dompdf render peaks near 56MB (see phpunit.xml). 256M leaves room for a
# voucher and a spreadsheet export in the same process without the "premature
# end of script" that a 128M default produces under load.
PHP_INI="/etc/php/${PHP_VERSION}/fpm/php.ini"
sed -i 's/^memory_limit = .*/memory_limit = 256M/' "$PHP_INI"
sed -i 's/^upload_max_filesize = .*/upload_max_filesize = 16M/' "$PHP_INI"
sed -i 's/^post_max_size = .*/post_max_size = 16M/' "$PHP_INI"
# expose_php off: the response header otherwise advertises the exact PHP build
# to anyone scanning for a version with a known hole.
sed -i 's/^;\?expose_php = .*/expose_php = Off/' "$PHP_INI"

# ---------------------------------------------------------------------------
# 2. Composer
# ---------------------------------------------------------------------------
if ! command -v composer >/dev/null; then
    log "Installing Composer"
    curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php
    php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet
    rm -f /tmp/composer-setup.php
fi

# Node 24, kept only as a fallback.
#
# Production no longer builds assets: CI builds them, the pipeline stages them
# under /var/www/institute-builds/<sha>, and deploy.sh runs with BUILD_ASSETS=no.
# Node stays on the box for the one case where a human has to release without a
# pipeline — `BUILD_ASSETS=yes bash deploy/deploy.sh` — because discovering npm
# is absent during that particular emergency is not the moment for it.
#
# 24, not 20: Node 20 reached end of life on 30 April 2026, and Vite 8 requires
# ^20.19.0 || >=22.12.0. 24 is the Active LTS line and satisfies both. The
# NodeSource setup script selects its own architecture, so there is nothing
# arch-specific to pin here.
#
# Removing Node from production entirely is on the week-two list, once the
# immutable artifact removes the fallback's reason to exist.
NODE_MAJOR=24

if ! command -v node >/dev/null || [[ "$(node -v | sed 's/^v\([0-9]*\).*/\1/')" -lt "$NODE_MAJOR" ]]; then
    log "Installing Node ${NODE_MAJOR}"
    curl -fsSL "https://deb.nodesource.com/setup_${NODE_MAJOR}.x" | bash - >/dev/null 2>&1
    apt-get install -y -qq nodejs
fi

# ---------------------------------------------------------------------------
# 3. MySQL 8
# ---------------------------------------------------------------------------
# On the same box as the application, deliberately. A hosted MySQL on a free
# tier adds a network hop to every Livewire round trip (and this UI makes one
# per interaction), a second provider that can withdraw its free tier, and a
# connection cap. Local means a unix socket, no TLS to misconfigure, and
# mysqldump available for the restore drill.
log "Installing MySQL 8"
apt-get install -y -qq mysql-server

systemctl enable --now mysql

# MySQL must not listen on anything but loopback. Ubuntu's package already
# defaults to 127.0.0.1, but this is the assertion that stops a hand-edit or a
# future package change from quietly exposing the money database to the
# internet. The Lightsail firewall does not forward 3306 either; this is the
# second of the two layers, and neither one alone is a control.
MYSQL_BIND_CONF="/etc/mysql/mysql.conf.d/zz-institute-bind.cnf"
if [[ ! -f "$MYSQL_BIND_CONF" ]]; then
    log "Pinning MySQL to loopback"
    cat > "$MYSQL_BIND_CONF" <<'BIND'
# Loopback only. The application is on this box; nothing else may connect.
# Deliberately NOT a place to tune innodb_buffer_pool_size — see docs/DEPLOYMENT.md §7.3.
[mysqld]
bind-address = 127.0.0.1
mysqlx = 0
BIND
    systemctl restart mysql
fi

if ss -lntp 2>/dev/null | grep -qE ':3306\s' && ! ss -lntp 2>/dev/null | grep -qE '127\.0\.0\.1:3306'; then
    die "MySQL is listening on a non-loopback address. Refusing to continue — fix ${MYSQL_BIND_CONF} first."
fi

DB_PASS_FILE="/root/.institute-db-password"

if [[ -f "$DB_PASS_FILE" ]]; then
    DB_PASS="$(cat "$DB_PASS_FILE")"
    log "Reusing the existing database password from ${DB_PASS_FILE}"
else
    DB_PASS="$(openssl rand -base64 30 | tr -d '/+=' | head -c 32)"
    printf '%s' "$DB_PASS" > "$DB_PASS_FILE"
    chmod 600 "$DB_PASS_FILE"
fi

# utf8mb4 throughout: student names in the roll are not all ASCII, and a
# latin1 column silently mangles them at import rather than failing.
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';

GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';

-- The restore drill (backup:verify) creates and drops this database on every
-- run. Granting on the name pattern rather than globally means a compromised
-- app credential still cannot touch any other database on the box.
GRANT ALL PRIVILEGES ON \`${VERIFY_DB}\`.* TO '${DB_USER}'@'localhost';

FLUSH PRIVILEGES;
SQL

# ---------------------------------------------------------------------------
# 4. Application user and directory
# ---------------------------------------------------------------------------
log "Preparing ${APP_DIR}"

if ! id -u "$APP_USER" >/dev/null 2>&1; then
    adduser --system --group --home "$APP_DIR" --shell /bin/bash "$APP_USER"
fi

mkdir -p "$APP_DIR"
chown -R "$APP_USER:$APP_USER" "$APP_DIR"

# nginx runs as www-data and must be able to TRAVERSE into $APP_DIR, which is
# mode 0750 and owned by $APP_USER. Without this the web server cannot resolve
# $realpath_root in the vhost below, hands PHP-FPM a path that does not exist,
# and every request answers 404 with FPM's misleading "File not found." — the
# error blames the interpreter for the web server's lack of permission.
#
# Group membership, deliberately, rather than `chmod 0755 $APP_DIR`. This
# directory holds .env: the APP_KEY that decrypts every stored TOTP secret, and
# the database password. Adding one account to the group grants exactly that
# account traverse; 0755 grants it to every process on the box.
#
# Found on the 2026-08-30 rehearsal (checklist NGINX-01). P2-05 checked the mode
# was 0750 and passed, because it verified what the app account needs and never
# asked whether the web server could get through. Checklist P2-05a now does.
usermod -aG "$APP_USER" www-data

# Backups live outside the application directory so a deploy that wipes and
# re-clones the app cannot take the dumps with it. This must match BACKUP_PATH
# in .env — deploy.sh refuses to run if it does not.
BACKUP_DIR="/var/backups/institute"
mkdir -p "$BACKUP_DIR"
chown "$APP_USER:$APP_USER" "$BACKUP_DIR"
chmod 750 "$BACKUP_DIR"

# Where the pipeline stages a release's compiled Vite assets, one directory per
# commit SHA, before deploy.sh activates them inside maintenance mode.
#
# NOT /tmp. systemd-tmpfiles cleans /tmp on a schedule and on boot, and a build
# that silently evaporates between the push and the deploy is exactly the
# failure you do not want to be quiet — the deploy would refuse, correctly, but
# for a reason nobody could reproduce afterwards.
#
# 0750 and owned by the app account: the pipeline writes here as this user and
# nothing else on the box has any business reading a release before it ships.
BUILD_STAGE_DIR="/var/www/institute-builds"
mkdir -p "$BUILD_STAGE_DIR"
chown "$APP_USER:$APP_USER" "$BUILD_STAGE_DIR"
chmod 750 "$BUILD_STAGE_DIR"

# ---------------------------------------------------------------------------
# 5. PHP-FPM pool
# ---------------------------------------------------------------------------
log "Configuring the PHP-FPM pool"

cat > "/etc/php/${PHP_VERSION}/fpm/pool.d/institute.conf" <<POOL
[institute]
user = ${APP_USER}
group = ${APP_USER}
listen = /run/php/php${PHP_VERSION}-institute.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

; Sized for this box and this workload: 4GB of RAM and nine staff accounts.
;
; The previous setting was `pm = static` with 12 children, carried over from a
; 12GB Ampere target. `static` keeps every child resident permanently, so that
; was 12 x 256M = 3GB of PHP reserved on a 4GB machine whether anybody was
; using the counter or not, leaving MySQL to fight the page cache for what was
; left. On a nine-person counter most of those processes were idle all day.
;
; `ondemand` starts a child when a request needs one and reaps it after
; process_idle_timeout. The cost is a fork on the first request after a quiet
; spell, which is microseconds against a Livewire round trip. The benefit is
; that idle costs nothing, which is the shape of this workload: bursts at
; enrolment time, long quiet stretches otherwise.
;
; 6 children x 256M = 1.5GB worst case, against 4GB with 2GB of swap behind it.
; Nine staff cannot generate more than six concurrent PHP requests in practice;
; if they ever do, requests queue in the socket backlog rather than the box
; going to swap, which is the failure mode you want.
pm = ondemand
pm.max_children = 6
pm.process_idle_timeout = 10s
pm.max_requests = 500

; 256M, unchanged, and deliberately not reduced alongside the worker count.
; One dompdf render of the fee voucher peaks near 56MB and a phpspreadsheet
; export in the same process adds to it. Fewer workers is a concurrency
; decision; per-request headroom is a correctness one, and they are unrelated.
php_admin_value[memory_limit] = 256M

; Slow requests land in the log with a stack trace rather than only as a user
; saying "the receipt screen hangs sometimes".
slowlog = /var/log/php-fpm-institute-slow.log
request_slowlog_timeout = 10s

php_admin_value[error_log] = /var/log/php-fpm-institute-error.log
php_admin_flag[log_errors] = on
POOL

# ---------------------------------------------------------------------------
# 6. nginx
# ---------------------------------------------------------------------------
log "Configuring nginx"
apt-get install -y -qq nginx

# Pass APP_DOMAIN so certbot can find a server block to install into. With the
# `_` catch-all default, `certbot --nginx` obtains a certificate and then fails
# with "Could not automatically find a matching server block" — the certificate
# is issued (spending a rate-limit attempt) but the site stays on plain HTTP.
# See checklist CERT-01.
#
#     APP_DOMAIN=pos.bigbinaryerp.com ./provision.sh
SERVER_NAME="${APP_DOMAIN:-_}"

if [ "$SERVER_NAME" = "_" ]; then
    warn "APP_DOMAIN is not set, so the vhost gets the '_' catch-all. certbot --nginx will NOT be able to install a certificate until server_name names the real host. See checklist CERT-01."
fi

cat > /etc/nginx/sites-available/institute <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name ${SERVER_NAME};

    root ${APP_DIR}/public;
    index index.php;

    charset utf-8;
    client_max_body_size 16M;

    # The app pins TRUSTED_PROXIES to 127.0.0.1 and reads these to know it is
    # behind TLS. \$remote_addr, NOT \$proxy_add_x_forwarded_for: the latter
    # APPENDS to whatever the caller claimed, so a forged entry survives and the
    # per-IP login brake can be stepped around by rotating it.
    proxy_set_header X-Forwarded-For \$remote_addr;
    proxy_set_header X-Forwarded-Proto \$scheme;

    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        fastcgi_pass unix:/run/php/php${PHP_VERSION}-institute.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT \$realpath_root;
        include fastcgi_params;

        # A fee voucher or a report export legitimately takes longer than the
        # 60s default; a clerk seeing a 504 mid-print will simply print again.
        fastcgi_read_timeout 120;
    }

    # Compiled assets are content-hashed by Vite, so they can be cached hard.
    location ^~ /build/ {
        expires 1y;
        access_log off;
        add_header Cache-Control "public, immutable";
    }

    # Nothing below is ever a legitimate request against a Laravel public root.
    location ~ /\.(?!well-known).* { deny all; }
    location ~ \.(env|sql|gz|sqlite)\$ { deny all; }

    access_log /var/log/nginx/institute-access.log;
    error_log  /var/log/nginx/institute-error.log;
}
NGINX

ln -sf /etc/nginx/sites-available/institute /etc/nginx/sites-enabled/institute
rm -f /etc/nginx/sites-enabled/default

# ---------------------------------------------------------------------------
# 7. Local firewall, as defence in depth only
# ---------------------------------------------------------------------------
# Lightsail's external firewall is the real control and it lives in the console,
# not here. This block exists because the previous target (Oracle) shipped an
# image whose INPUT chain dropped everything except SSH, so opening a port in
# the cloud console alone changed nothing. Lightsail's image does not do that.
#
# So the block is kept — a second layer costs nothing and one day the image may
# change again — but it is now:
#
#   * conditional: it only inserts ACCEPT rules, never a policy, never a DROP;
#   * position-safe: the old code inserted at index 6, which is an error
#     ("Index of insertion too big") on the empty ruleset Lightsail actually
#     ships, and `set -e` would have aborted provisioning right here;
#   * incapable of locking out SSH, because it adds nothing that could.
#
# If the chain has a terminal DROP/REJECT we insert above it; otherwise we
# append. Either way the result is additive.
log "Allowing ports 80 and 443 in the local firewall (defence in depth)"

if ! command -v iptables >/dev/null; then
    warn "iptables is not present. Skipping the local firewall layer; Lightsail's console firewall is the control."
else
    for port in 80 443; do
        if iptables -C INPUT -p tcp --dport "$port" -j ACCEPT 2>/dev/null; then
            continue
        fi

        # Line number of the first terminal rule, if there is one. `grep -n` on
        # the numbered listing rather than parsing --list-rules, because we need
        # the index iptables itself would use for -I.
        TERMINAL_AT="$(iptables -L INPUT --line-numbers -n 2>/dev/null \
            | awk '$2 == "DROP" || $2 == "REJECT" { print $1; exit }')"

        if [[ -n "$TERMINAL_AT" ]]; then
            iptables -I INPUT "$TERMINAL_AT" -p tcp --dport "$port" -m conntrack --ctstate NEW,ESTABLISHED -j ACCEPT
        else
            # Empty or all-ACCEPT chain — Lightsail's default. Appending is
            # correct and, on a chain with an ACCEPT policy, a no-op in effect.
            iptables -A INPUT -p tcp --dport "$port" -m conntrack --ctstate NEW,ESTABLISHED -j ACCEPT
        fi
    done

    # Persist only if the package is already there. Installing
    # iptables-persistent non-interactively freezes the CURRENT ruleset as the
    # boot ruleset, and on a box where somebody is mid-way through a manual
    # change that is a way to persist a lockout. Left to the operator.
    if command -v netfilter-persistent >/dev/null; then
        netfilter-persistent save >/dev/null 2>&1 || warn "Could not persist iptables rules; they will not survive a reboot."
    else
        warn "iptables-persistent is not installed, so these rules are not saved across reboots."
        warn "That is acceptable: Lightsail's console firewall is the control, and it is stateful."
    fi
fi

warn "The REAL firewall is the Lightsail console: Instance > Networking > IPv4 Firewall."
warn "  22/tcp  -> your admin IP only    80/tcp -> anywhere    443/tcp -> anywhere"
warn "IPv4 and IPv6 are SEPARATE rule sets in Lightsail. Configure both, or disable IPv6"
warn "on the instance (Networking > IPv6 > Disable), which is what docs/DEPLOYMENT.md recommends."
warn "Never open 3306. MySQL is pinned to loopback above and nothing outside this box may reach it."

# ---------------------------------------------------------------------------
# 8. Swap
# ---------------------------------------------------------------------------
# Lightsail's Ubuntu image ships with none, and 4GB is not a lot of headroom.
# MySQL plus PHP-FPM plus a composer install during a deploy is exactly the
# spike that gets a process OOM-killed, and the one the kernel picks is usually
# mysqld — the single process on this box whose death costs money.
#
# 2G is insurance against the spike, not a substitute for RAM. If the box is
# swapping steadily rather than during a deploy, that is a signal to look at
# pm.max_children, not to add more swap.
if ! swapon --show | grep -q .; then
    log "Creating a 2G swap file"
    fallocate -l 2G /swapfile
    chmod 600 /swapfile
    mkswap /swapfile >/dev/null
    swapon /swapfile
    grep -q '/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

# ---------------------------------------------------------------------------
# 9. Scheduler and queue as systemd units
# ---------------------------------------------------------------------------
# systemd rather than a crontab entry, because `systemctl status` and
# `journalctl -u` answer "is it running, and what did it say" — a cron line
# answers neither, and a scheduler that silently stopped is how the nightly
# backup quietly stops happening.
log "Installing the scheduler and queue services"

cat > /etc/systemd/system/institute-scheduler.service <<UNIT
[Unit]
Description=Institute POS scheduler (one tick)
After=network.target mysql.service

[Service]
Type=oneshot
User=${APP_USER}
WorkingDirectory=${APP_DIR}
ExecStart=/usr/bin/php${PHP_VERSION} artisan schedule:run
UNIT

cat > /etc/systemd/system/institute-scheduler.timer <<UNIT
[Unit]
Description=Run the Institute POS scheduler every minute

[Timer]
OnCalendar=*:0/1
AccuracySec=10s
Persistent=true

[Install]
WantedBy=timers.target
UNIT

cat > /etc/systemd/system/institute-queue.service <<UNIT
[Unit]
Description=Institute POS queue worker
After=network.target mysql.service

[Service]
User=${APP_USER}
WorkingDirectory=${APP_DIR}
# --tries=3 then it goes to failed_jobs rather than retrying forever. --max-time
# recycles the process hourly so a slow leak cannot accumulate across weeks.
ExecStart=/usr/bin/php${PHP_VERSION} artisan queue:work --tries=3 --max-time=3600 --sleep=3
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT

# ---------------------------------------------------------------------------
# 9b. Lock files
# ---------------------------------------------------------------------------
# deploy.sh takes /run/institute-deploy.lock and backup:run takes
# /run/institute-backup.lock, and both run as the unprivileged app account. /run
# is root-owned, so neither could create its own lock file — the deploy would
# die on the very first line with a permission error.
#
# tmpfiles.d rather than a plain `touch`, because /run is a tmpfs: a file
# created here by hand disappears at the next reboot and the first deploy after
# that reboot fails for a reason nobody connects to the reboot.
log "Registering the deploy and backup lock files"

cat > /etc/tmpfiles.d/institute.conf <<TMPFILES
# type path                            mode user       group      age argument
f /run/institute-deploy.lock 0644 ${APP_USER} ${APP_USER} - -
f /run/institute-backup.lock 0644 ${APP_USER} ${APP_USER} - -
TMPFILES

systemd-tmpfiles --create /etc/tmpfiles.d/institute.conf

systemctl daemon-reload
systemctl enable --now institute-scheduler.timer

# deploy.sh runs as the service account and has to reload PHP-FPM and restart
# the queue at the end of a release. Rather than give that account general sudo,
# grant exactly those three commands and nothing else — a deploy should not be
# able to do anything a deploy does not need.
cat > /etc/sudoers.d/institute-deploy <<SUDO
${APP_USER} ALL=(root) NOPASSWD: /usr/bin/systemctl reload php${PHP_VERSION}-fpm
${APP_USER} ALL=(root) NOPASSWD: /usr/bin/systemctl restart institute-queue
${APP_USER} ALL=(root) NOPASSWD: /usr/bin/systemctl status institute-queue
SUDO
chmod 440 /etc/sudoers.d/institute-deploy
visudo -c -f /etc/sudoers.d/institute-deploy >/dev/null || die "Generated an invalid sudoers file."

# ---------------------------------------------------------------------------
# 10. Unattended security upgrades
# ---------------------------------------------------------------------------
# "No surprises" cuts both ways: an unpatched box is a surprise too. Security
# updates only — not release upgrades, which is what breaks machines overnight.
log "Enabling unattended security upgrades"
apt-get install -y -qq unattended-upgrades
cat > /etc/apt/apt.conf.d/20auto-upgrades <<'CONF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
CONF

# ---------------------------------------------------------------------------
# 10b. Time synchronisation — this one is not housekeeping
# ---------------------------------------------------------------------------
# TOTP codes are a function of the clock. If this box drifts more than about
# thirty seconds, every enrolled account stops being able to log in at the same
# moment — including the only /superadmin account, which is also the account you
# would need in order to fix anything. There is no recovery path from inside the
# application; you would be SSHing in to reset the clock while nine staff stand
# at a counter that will not open.
#
# So this fails the provision rather than warning. A box whose clock is not
# synchronised is not provisioned.
#
# The server clock stays UTC. Storage is UTC everywhere by design; the
# application localises for display and routes/console.php schedules against
# config('institute.timezone'). Setting the box to Asia/Karachi would
# re-interpret money history and is exactly what must not happen.
log "Ensuring the system clock is synchronised"

apt-get install -y -qq systemd-timesyncd >/dev/null 2>&1 || true
timedatectl set-timezone UTC
timedatectl set-ntp true 2>/dev/null || true
systemctl enable --now systemd-timesyncd >/dev/null 2>&1 || true

# timesyncd needs a moment to complete its first exchange on a fresh instance.
for attempt in 1 2 3 4 5 6 7 8 9 10; do
    if timedatectl show --property=NTPSynchronized --value 2>/dev/null | grep -q '^yes$'; then
        break
    fi
    sleep 3
done

if ! timedatectl show --property=NTPSynchronized --value 2>/dev/null | grep -q '^yes$'; then
    timedatectl status || true
    die "System clock is NOT synchronised (timedatectl: NTPSynchronized=no).
    Two-factor authentication is computed from this clock, so drift locks every
    enrolled account — including /superadmin — out simultaneously, with no way
    back in through the application. Fix time sync before provisioning further:
      systemctl status systemd-timesyncd
      journalctl -u systemd-timesyncd -n 50"
fi

log "Clock: $(timedatectl show --property=TimeUSec --value 2>/dev/null || date -u) (UTC, synchronised)"

# ---------------------------------------------------------------------------
# 11. Log rotation
# ---------------------------------------------------------------------------
cat > /etc/logrotate.d/institute <<ROTATE
${APP_DIR}/storage/logs/*.log /var/log/php-fpm-institute-*.log {
    daily
    rotate 30
    missingok
    notifempty
    compress
    delaycompress
    copytruncate
    su ${APP_USER} ${APP_USER}
}
ROTATE

# ---------------------------------------------------------------------------
# 12. fail2ban
# ---------------------------------------------------------------------------
log "Installing fail2ban for SSH"
apt-get install -y -qq fail2ban
systemctl enable --now fail2ban >/dev/null 2>&1 || true

# ---------------------------------------------------------------------------
# 13. certbot
# ---------------------------------------------------------------------------
# Installed here, run later. `certbot --nginx` needs DNS already pointing at
# this instance, which is a human step that happens after provisioning, so this
# only puts the tool and its renewal timer in place. Installing it now means the
# TLS step is one command with nothing to fetch, at the point in the evening
# when fetching things is least welcome.
log "Installing certbot"
apt-get install -y -qq certbot python3-certbot-nginx
systemctl enable --now certbot.timer >/dev/null 2>&1 || true

# ---------------------------------------------------------------------------
# Done
# ---------------------------------------------------------------------------
nginx -t
systemctl restart "php${PHP_VERSION}-fpm" nginx

# The MySQL version is not trivia. Ubuntu 24.04's `mysql-server` metapackage
# resolves to the 8.0.x line, NOT 8.4, and the CI leg that gates production has
# to be the version production actually runs. Printed here so the operator can
# copy it into docs/DEPLOYMENT.md §7.3 rather than assuming.
MYSQL_VERSION="$(mysql --version 2>/dev/null || echo 'unknown')"

cat <<SUMMARY

$(log "Provisioning complete")

  Database    ${DB_NAME}
  Username    ${DB_USER}
  Password    ${DB_PASS}
              (also at ${DB_PASS_FILE}, root-only)

  MySQL       ${MYSQL_VERSION}
              ^ THIS is the version the required CI leg must match. Ubuntu
                24.04 ships the 8.0.x line; do not "upgrade production to 8.4
                so CI matches". Record it in docs/DEPLOYMENT.md §7.3.

  App dir     ${APP_DIR}
  Backups     ${BACKUP_DIR}
  Build stage ${BUILD_STAGE_DIR}
  PHP socket  /run/php/php${PHP_VERSION}-institute.sock
  Architecture ${ARCH}
  Clock       UTC, NTP-synchronised (2FA depends on this)

Record off-box, in a password manager, BEFORE going further — see
docs/PRODUCTION-EMERGENCY.md. A database dump without APP_KEY is not a backup:
APP_KEY decrypts every stored TOTP secret, and without it a perfect restore
locks out every enrolled account.

Next:
  1. Put the code in ${APP_DIR} and write .env
     (docs/DEPLOYMENT.md §4, and .env.production.example in the repo)
     BACKUP_PATH=${BACKUP_DIR}   — deploy.sh refuses without it
     BACKUP_KEEP=168             — backups are HOURLY now; 14 would keep 14 hours
  2. artisan migrate --force, then ONLY these seeders, by name:
       db:seed --class=RolePermissionSeeder --force
       db:seed --class=SuperAdminSeeder --force
     NEVER bare db:seed — DemoDataSeeder invents students and fake revenue.
  3. certbot --nginx -d your.domain   ← only after DNS points here
  4. Set SESSION_SECURE_COOKIE=true, then deploy again
  5. systemctl enable --now institute-queue
  6. Register this box as the Azure DevOps 'production' Environment VM resource

Health:  curl -sf http://localhost/up    && echo "up OK"
         curl -sf http://localhost/ready && echo "ready OK"
Logs:    journalctl -u institute-scheduler -n 50
         tail -f /var/log/nginx/institute-error.log

SUMMARY
