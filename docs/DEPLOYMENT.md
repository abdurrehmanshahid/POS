# Deploying to cPanel shared hosting

Target: MySQL 8 / MariaDB 10.6+, PHP 8.4+, no shell access, no Redis, no daemons.
Everything below works within those limits, nothing here needs root, `exec()`,
or a background worker.

---

## 1. Before you upload

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build          # produces public/build/
```

Build assets **locally**. Shared hosts rarely have Node, and you do not want to
find that out during a deploy.

## 2. Directory layout

cPanel serves from `public_html`, but Laravel's document root must be `public/`.
Two options, the first is much safer:

**Preferred, app outside the web root:**

```
/home/bbtedu/
├── pos/                 ← the whole Laravel app
│   ├── app/  config/  database/  storage/  vendor/
│   └── public/          ← contents copied to public_html
└── public_html/         ← index.php + build/ + assets/
```

Then edit `public_html/index.php` so both paths point up one level:

```php
require __DIR__.'/../pos/vendor/autoload.php';
$app = require_once __DIR__.'/../pos/bootstrap/app.php';
```

This keeps `.env`, `storage/` and `database/` unreachable over HTTP even if PHP
stops executing. If you instead upload everything into `public_html`, a single
server misconfiguration exposes your `.env`, which contains `APP_KEY`, and
`APP_KEY` decrypts every stored two-factor secret.

## 3. Database

In cPanel → **MySQL® Databases**, create a database and a user, and grant the
user all privileges. Names get your account prefix automatically.

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=bbtedu_pos
DB_USERNAME=bbtedu_posuser
DB_PASSWORD=…
```

## 4. Environment

Copy `.env.example` to `.env` and set at minimum:

```env
APP_ENV=production
APP_DEBUG=false              # non-negotiable: debug pages leak config and queries
APP_URL=https://pos.bbt.edu.pk   # see "The address in links" below — this is load-bearing

TRUSTED_PROXIES=127.0.0.1    # the nginx in front of PHP. See below before changing.

SESSION_SECURE_COOKIE=true   # HTTPS only
SESSION_ENCRYPT=true

INSTITUTE_TODAY=             # MUST be blank in production, or "overdue" freezes
INSTITUTE_SELF_SERVICE_RESET=false
```

### The address in links

`APP_URL` is not decoration. The **password-reset link** emailed to staff is
built from it rather than from the hostname the caller claims in the `Host:`
header. Without that, an attacker submits a real member of staff's address on
the public forgot-password form while claiming a hostname of their own, and that
person receives a genuine email from this institute carrying a valid reset token
pointing at the attacker's server — a working credential, delivered by us. Set
`APP_URL` to the exact `https://` address staff use.

The pinning stops at that one link, deliberately. Links *inside* a page still
follow whatever host the browser is actually on, so the app keeps working when
reached at a LAN IP or a second internal name — pinning those too would bounce
staff to `APP_URL`, onto a host their session cookie does not match, and loop
them on the login screen. A wrong `APP_URL` therefore costs you reset emails,
not the site.

### Which proxy to believe

nginx terminates TLS and talks to PHP over plain HTTP, so PHP sees `http` and
would generate `http://` links on an `https://` page — the browser then blocks
the stylesheet, the compiled JS and the fee-voucher viewer as mixed content.
`X-Forwarded-Proto` carries the truth, and `TRUSTED_PROXIES` says whose word to
take for it.

Set it to nginx's own address (`127.0.0.1` when it is on the same box). The
default of `*` believes whoever is speaking, which is right on a platform that
is the only way in and wrong here, where the app may also answer on its own
port. Two consequences if you leave it: the 20-failures-per-IP spray brake in
`LoginThrottle` can be stepped around by rotating a forged `X-Forwarded-For`,
and every audit row records whatever address the caller claimed.

One nginx gotcha, because the usual recipe gets it wrong:

```nginx
proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;  # APPENDS — forged entry survives
proxy_set_header X-Forwarded-For $remote_addr;                # overwrites — use this
proxy_set_header X-Forwarded-Proto $scheme;
```

`TRUSTED_PROXIES` is read through `config:cache`, so changing it takes effect
after `php artisan config:cache`, not on the next request.

Generate a key, this is what encrypts sessions and every stored 2FA secret:

```bash
php artisan key:generate
```

> **Never rotate `APP_KEY` on a live install.** Every encrypted two-factor
> secret becomes undecryptable, locking out every enrolled account at once.

## 5. Install

Via cPanel's **Terminal** if available, otherwise Cron → run once:

```bash
php artisan migrate --force
php artisan db:seed --force        # roles, super admin, demo data
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

The seeder prints the super admin password **once**. Record it, then sign in at
`/superadmin` and complete the forced two-factor enrolment immediately.

To skip the demo institute data, run `php artisan db:seed --class=RolePermissionSeeder --force`
followed by `--class=SuperAdminSeeder`.

## 6. Permissions

```bash
chmod -R 775 storage bootstrap/cache
```

## 7. Scheduled tasks

cPanel → **Cron Jobs**, once per minute:

```
* * * * * /usr/local/bin/php /home/bbtedu/pos/artisan schedule:run >> /dev/null 2>&1
```

Confirm the PHP binary path in cPanel → **Select PHP Version** first; the
default `php` on the PATH is often an older major version than the one serving
your site.

## 8. After every deploy

```bash
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

Config caching means **`.env` changes do nothing until you re-cache**. If a
setting appears to be ignored, this is almost always why.

---

## Backups

Super admin → **Backup & export** streams a full `.sql` dump generated in pure
PHP, no `mysqldump` binary, no shell. Restore through phpMyAdmin → Import.

Take one **before every upgrade**, and keep them off this server: a dump sitting
in the same hosting account is lost with the account. Each download is itself
audited, since the file contains every password hash and encrypted 2FA secret in
one place.

## Email (optional)

Only needed if you enable self-service password reset. Free options:

| Provider | Host | Port | Notes |
| --- | --- | --- | --- |
| Gmail / Workspace | `smtp.gmail.com` | 587 | needs an App Password, ~500/day |
| Brevo | `smtp-relay.brevo.com` | 587 | 300/day free |
| cPanel mail | `localhost` | 465 | no signup; poorer deliverability |

Then, and only then:

```env
INSTITUTE_SELF_SERVICE_RESET=true
```

Until it is on, administrators issue one-time temporary passwords from the Staff
screen, see `docs/SECURITY.md` §2.1 for why the flag exists.

## Health check

`GET /up` returns 200 when the framework boots, point cPanel or an external
uptime monitor at it.
