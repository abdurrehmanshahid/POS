# Deploying to Vercel (serverless)

This is a working Vercel deployment for an application that was not designed
for one. Read §4 before you promise anything to the institute: three features
degrade on this platform, and one of them is the fee voucher.

For the cPanel/VPS path, which has none of these caveats, see
[DEPLOYMENT.md](DEPLOYMENT.md).

---

## 1. What is in the repository

| File | Job |
| --- | --- |
| `api/index.php` | The serverless front controller. Relocates everything Laravel writes to `/tmp` before the framework boots. |
| `vercel.json` | Runtime, routing, build command. |
| `.vercelignore` | Keeps tests, docs and student data out of the function bundle. |

`public/index.php` is untouched, so the traditional deploy still works from the
same commit.

## 2. The database

**Vercel has no database.** Sessions, the cache and the queue all live in
MySQL here, so you need a MySQL that is reachable from the public internet
with TLS. Free or cheap options:

| Provider | Notes |
| --- | --- |
| PlanetScale | MySQL 8 compatible, connection pooling, good fit for serverless |
| Aiven | free tier MySQL, EU/Asia regions |
| Railway / Neon | Railway does MySQL; Neon is Postgres only, so not this app |
| AWS RDS | works, but put RDS Proxy in front or you will exhaust connections |

Connection pooling genuinely matters: every Livewire interaction is a full
request, and each concurrent function instance opens its own MySQL connection.

## 3. Environment variables

Set these in **Vercel → Project → Settings → Environment Variables**. Marked
values are non-negotiable.

```env
APP_NAME="Big Binary Tech Institute"
APP_ENV=production                 # REQUIRED: 'local' exposes passwordless demo logins
APP_DEBUG=false                    # REQUIRED: leaks DB credentials otherwise
APP_KEY=base64:…                   # generate ONCE with `php artisan key:generate --show`
APP_URL=https://<your-domain>

DB_CONNECTION=mysql
DB_HOST=…
DB_PORT=3306
DB_DATABASE=…
DB_USERNAME=…
DB_PASSWORD=…
MYSQL_ATTR_SSL_CA=/etc/ssl/certs/ca-certificates.crt   # most hosted MySQL requires TLS

SESSION_DRIVER=database            # REQUIRED: file sessions in /tmp do not survive
SESSION_SECURE_COOKIE=true         # REQUIRED: no default, unset means plain HTTP
SESSION_ENCRYPT=true
CACHE_STORE=database               # REQUIRED: same reason
QUEUE_CONNECTION=database
LOG_CHANNEL=stderr                 # REQUIRED: /tmp logs vanish; stderr reaches Vercel
LOG_LEVEL=error

INSTITUTE_TODAY=                   # REQUIRED BLANK: pinning it freezes every overdue date
INSTITUTE_SELF_SERVICE_RESET=false
INSTITUTE_CONTACT_EMAIL=accounts@bbt.edu.pk
INSTITUTE_CONTACT_PHONE=           # blank omits it from the voucher rather than faking one
SUPERADMIN_PASSWORD=               # REQUIRED BLANK: seeder mints a strong one and prints it
```

`DOMPDF_FONT_DIR` is set by `api/index.php` at runtime. Do not set it here.

> **Never rotate `APP_KEY` after go-live.** It decrypts every stored two-factor
> secret; rotating locks out every enrolled account at once.

## 4. What degrades on this platform

Be straight with the institute about these. None are bugs in the application.

### 4.1 The database backup will fail on any real dataset

Super admin → Backup & export streams a full SQL dump. Vercel buffers the
entire function response before sending it, and caps it. A dump of 433
students with their challans and payments will exceed that.

**Consequence:** the institute's self-service backup does not work. Take
backups from the MySQL provider's own tooling instead, and say so in the
handover.

### 4.2 Large exports may time out

`maxDuration` is set to 60s in `vercel.json`, which requires a Pro plan (Hobby
caps at 10s). The Reports xlsx export builds the whole workbook in memory
before streaming. For a few hundred students it is fine; it is the thing most
likely to hit the ceiling first as the institute grows.

### 4.3 The first request to a cold instance is slow

Blade templates are not pre-compiled, because the build cannot know the
runtime storage path. Each new instance compiles views on first use. Expect a
noticeably slow first page load after idle.

### 4.4 The `vercel-php` runtime is community-maintained

It is not a Vercel product and not covered by their support. If it breaks
against a new PHP version, you are waiting on a volunteer. The application
itself runs unmodified on any ordinary PHP host, so this is reversible — that
is why `public/index.php` was left in place.

## 5. Deploy

```bash
npm i -g vercel
vercel link
vercel --prod
```

The build command in `vercel.json` installs dependencies, builds assets, and
caches config and routes. Views are deliberately not cached — see §4.3.

## 6. First-run setup

Migrations cannot run inside a request. Run them from your machine against the
production database, once:

```bash
DB_CONNECTION=mysql DB_HOST=… DB_DATABASE=… DB_USERNAME=… DB_PASSWORD=… \
  php artisan migrate --force

DB_… php artisan db:seed --class=RolePermissionSeeder --force
DB_… php artisan db:seed --class=SuperAdminSeeder --force
```

**Never run bare `db:seed`.** It is guarded to local and testing, but the
habit is what matters.

`SuperAdminSeeder` prints the owner password once. Record it, sign in at
`/superadmin`, and complete two-factor enrolment immediately.

## 7. Health check

`GET /up` returns 200 when the framework boots. Point an uptime monitor at it.
On this platform it also tells you the function itself is healthy, which is
the first thing to check when something looks wrong.
