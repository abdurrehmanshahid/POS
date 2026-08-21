#!/usr/bin/env bash
#
# One-shot provisioning for the institute POS on an Oracle Cloud Always Free VM.
#
# Target: Ubuntu 24.04 LTS (ARM64, Ampere A1). Native packages throughout —
# nginx, PHP-FPM and MySQL run as ordinary systemd services. There is no Docker
# here and nothing to pull at boot: a container runtime is one more thing that
# can fail at 9am on a Monday for reasons unrelated to the application.
#
# Safe to re-run. Every step checks before it acts, so this doubles as the
# repair tool when somebody has changed something by hand.
#
#   sudo bash deploy/provision.sh
#
# What it will ask for: nothing. What it will print at the end: the database
# password it generated, once. Record it.
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

# Node, for `npm run build`. The Tailwind/Vite bundle is built on the box rather
# than shipped as a pipeline artefact: one code path, nothing to keep in step,
# and on two Ampere cores the build is under two minutes. If that ever becomes
# the slow part of a deploy, build in CI and set BUILD_ASSETS=no.
if ! command -v npm >/dev/null; then
    log "Installing Node 20"
    curl -fsSL https://deb.nodesource.com/setup_20.x | bash - >/dev/null 2>&1
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

# Backups live outside the application directory so a deploy that wipes and
# re-clones the app cannot take the dumps with it.
BACKUP_DIR="/var/backups/institute"
mkdir -p "$BACKUP_DIR"
chown "$APP_USER:$APP_USER" "$BACKUP_DIR"
chmod 750 "$BACKUP_DIR"

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

; Static sizing on a 12GB box with one application. Dynamic scaling exists to
; share a machine between tenants; here it only adds a cold first request.
pm = static
pm.max_children = 12
pm.max_requests = 500

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

SERVER_NAME="${APP_DOMAIN:-_}"

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
# 7. Oracle Cloud's iptables
# ---------------------------------------------------------------------------
# The single most common reason an Oracle VM "isn't reachable" while every
# dashboard says it should be. Their Ubuntu image ships a default INPUT policy
# that drops everything except SSH, *in addition to* the cloud Security List.
# Opening the port in the OCI console alone changes nothing until this runs.
log "Opening ports 80 and 443 in the local firewall"

if command -v netfilter-persistent >/dev/null || apt-get install -y -qq iptables-persistent; then
    for port in 80 443; do
        if ! iptables -C INPUT -p tcp --dport "$port" -j ACCEPT 2>/dev/null; then
            # Inserted at position 6, above the image's catch-all REJECT rule.
            iptables -I INPUT 6 -p tcp --dport "$port" -m state --state NEW,ESTABLISHED -j ACCEPT
        fi
    done
    netfilter-persistent save >/dev/null 2>&1 || true
fi

warn "Also open 80/443 in the OCI console: Networking > VCN > Security Lists > Ingress Rules."
warn "Both layers must allow the port. Neither one alone is enough."

# ---------------------------------------------------------------------------
# 8. Swap
# ---------------------------------------------------------------------------
# Oracle's images ship with none. MySQL plus PHP-FPM plus a composer install
# during a deploy is exactly the spike that gets a process OOM-killed, and the
# one it kills is usually mysqld.
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
# Done
# ---------------------------------------------------------------------------
nginx -t
systemctl restart "php${PHP_VERSION}-fpm" nginx

cat <<SUMMARY

$(log "Provisioning complete")

  Database    ${DB_NAME}
  Username    ${DB_USER}
  Password    ${DB_PASS}
              (also at ${DB_PASS_FILE}, root-only)

  App dir     ${APP_DIR}
  Backups     ${BACKUP_DIR}
  PHP socket  /run/php/php${PHP_VERSION}-institute.sock

Next:
  1. Put the code in ${APP_DIR} and write .env  (see docs/DEPLOYMENT-ORACLE.md §4)
     Include BACKUP_PATH=${BACKUP_DIR} — deploy.sh refuses without it, because
     the default writes dumps inside the application tree instead of here.
  2. bash deploy/deploy.sh
  3. certbot --nginx -d your.domain   ← only after DNS points here
  4. systemctl enable --now institute-queue

Health:  curl -sf http://localhost/up && echo OK
Logs:    journalctl -u institute-scheduler -n 50
         tail -f /var/log/nginx/institute-error.log

SUMMARY
