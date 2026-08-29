# Deploying the Institute POS to AWS Lightsail

> **Reading this for a non-Lightsail VPS (Hostinger KVM, Hetzner, Contabo)?**
>
> Everything that touches the *application* is provider-neutral and needs no
> change: `deploy/provision.sh`, `deploy/deploy.sh`, the pipeline, the backup
> story (rclone to Cloudflare R2), TLS via certbot, and the agent registration
> in §"Register the box as a VM resource" below. Nothing in this system calls an
> AWS API or reads an instance metadata service.
>
> Three things in THIS document are Lightsail-shaped and do not carry over:
>
> 1. **Creating the instance** (§1 below) — use the provider's own flow, but
>    start from a **bare Ubuntu 24.04 image**. A one-click "Laravel", CyberPanel
>    or hPanel image already ships nginx/PHP/MySQL and will fight `provision.sh`
>    for the same ports and config paths.
> 2. **The firewall.** Lightsail puts a network ACL in front of the instance and
>    this document treats it as the real control, with `provision.sh` §7 as
>    defence in depth. On a plain VPS that inverts: there may be no external
>    layer at all, so the host firewall becomes the only one. MySQL is pinned to
>    loopback by `provision.sh` §3 regardless, so the database is not exposed
>    either way — but SSH exposure and the `ufw`/iptables story need a decision
>    before go-live rather than after.
> 3. **Static IP and snapshots** — the provider's equivalents differ. Snapshots
>    are not a substitute for the R2 backup either way: a weekly snapshot on a
>    fee-collection system means up to seven days of payments lost.

Target: **Ubuntu 24.04 LTS, x86, Asia Pacific (Mumbai)**, the **$24/month**
bundle — 4 GB RAM, 2 vCPU, 80 GB SSD. Everything runs as an ordinary systemd
service: nginx, PHP-FPM and MySQL, installed from packages. **There is no Docker
anywhere in this deployment.** A container runtime is one more moving part that
can fail at 9am on a Monday for reasons that have nothing to do with the
application.

This file, `deploy/provision.sh`, `deploy/deploy.sh` and the two CI definitions
are the only authoritative sources. `docs/tech-stack.md` is a stub pointing
here; it described a system that was never built.

---

## 1. What you are building

```text
                    ┌─────────────────────────────────────────┐
   browser ──443──► │  nginx  ──socket──►  PHP-FPM (pool)     │
                    │    │                     │              │
                    │    │                 MySQL 8 (localhost)│
                    │    ├── /up    liveness   │              │
                    │    └── /ready readiness  │              │
                    │                    /var/backups/institute│
                    │  systemd: scheduler timer, queue worker │
                    └─────────────────────────────────────────┘
                         one instance, no other providers
```

One box, one database, no external services. That is not a compromise — it is
the right shape for this workload. The UI is Livewire, so every interaction is a
server round trip; putting the database on another provider would add a network
hop to each one.

**Sizing.** 2 vCPU / 4 GB for 451 students and nine staff accounts is
comfortable. MySQL will use around 400 MB; PHP-FPM is configured `ondemand` with
at most 6 children at 256 MB each, so the worst case is roughly 1.5 GB of PHP
against 4 GB of RAM, plus 2 GB of swap that `provision.sh` creates.

**Not ARM.** An earlier plan targeted Oracle's Ampere A1. The scripts must not
assume `arm64`; this instance is x86-64.

---

## 2. Creating the instance

### 2.1 Region

**Asia Pacific (Mumbai), `ap-south-1`.** Every Livewire interaction is a full
server round trip — typing in a search box, opening a dropdown, adding a payment
line. A US or European region puts ~250 ms on each of those and the counter will
feel broken while nothing is actually wrong.

Unlike the Oracle plan this replaces, **the region is not permanent**. A
Lightsail snapshot can be exported and an instance rebuilt in another region, so
this is a performance decision, not an irreversible one.

### 2.2 The instance

1. **Lightsail console → Create instance**
2. Region: **Asia Pacific (Mumbai)**, any availability zone
3. Platform **Linux/Unix**, blueprint **OS Only → Ubuntu 24.04 LTS**
4. Bundle: **$24/month** — 4 GB RAM, 2 vCPU, 80 GB SSD. Confirm it is the
   **Dual-stack / IPv4-capable** plan, not IPv6-only.
5. Add your **SSH public key** (`~/.ssh/id_ed25519.pub`, or generate one with
   `ssh-keygen -t ed25519`). Keep the private key — it is also the deployment
   credential, and it belongs in the off-box secret store (§11).
6. Create.

### 2.3 Static IP

**Networking → Create static IP → attach to the instance.**

A static IP is **free while it is attached** to a running instance, and is
charged only if you leave one allocated and detached. It is reassignable: if the
instance is ever rebuilt from a snapshot, move the same IP to the replacement
and DNS does not have to change or propagate.

Point `pos.bigbinaryerp.com` (an `A` record) at it before running certbot in §6.

### 2.4 Firewall — the part with the trap

Lightsail has **no VCN, no subnets and no route tables**. There is nothing to
build before the instance and nothing that is public or private for life. The
firewall is a per-instance rule list in the console.

**Instance → Networking → IPv4 Firewall:**

| Application | Protocol | Port | Restricted to |
| --- | --- | --- | --- |
| SSH | TCP | 22 | **your admin IP only** |
| HTTP | TCP | 80 | Anywhere `0.0.0.0/0` |
| HTTPS | TCP | 443 | Anywhere `0.0.0.0/0` |

> **IPv4 and IPv6 are two separate rule sets in Lightsail.** Editing the IPv4
> list does not touch the IPv6 list, and vice versa. A rule you added once is
> half-applied, and which half depends on how the visitor reached you — which is
> exactly the kind of intermittent that costs an afternoon.
>
> Pick one and be deliberate about it:
>
> - **Recommended:** disable IPv6 on the instance (**Networking → IPv6 →
>   Disable**). One address family, one rule set, one thing to reason about.
>   `provision.sh` does not depend on IPv6.
> - Or configure **both** rule sets identically, including the SSH restriction.

Port 80 must stay open even after TLS is live: certbot's HTTP-01 challenge
renews on it every 60 days.

`provision.sh` also keeps a local iptables allow for 80/443 as defence in depth.
Lightsail's image, unlike Oracle's, does **not** ship a drop-all INPUT policy, so
that block is conditional and is a no-op on a clean instance.

---

## 3. Provision

```bash
ssh ubuntu@<static-ip>
git clone <this-repo> /tmp/pos && cd /tmp/pos
sudo bash deploy/provision.sh
```

Idempotent — safe to re-run, and it doubles as the repair tool after somebody
changes something by hand. It installs PHP 8.4, MySQL 8, nginx, Node 24,
Composer, fail2ban and unattended security upgrades; creates the database and a
scoped user; writes the FPM pool, the nginx site, the systemd units and log
rotation; adds swap; creates `/var/backups/institute` and
`/var/www/institute-builds`; asserts the clock is synchronised; and opens
ports 80/443 locally.

It prints the generated database password **once**. Record it (§11).

It also prints `mysql --version` at the end. **Record that too** — Ubuntu
24.04's `mysql-server` metapackage resolves to the **8.0.x** line, not 8.4, and
that is the version CI's required leg has to match (§8).

### PHP 8.4 is a hard floor, not a preference

Ubuntu 24.04 ships PHP 8.3 and this application cannot run on it. Laravel 13
pulls in Symfony 8, and twenty of its components declare `php >=8.4.1` —
`composer install` refuses outright. That is why provisioning adds the `ondrej`
PPA, and it is why free shared hosting is not an option: most of it caps at 8.3.

**Production stays on PHP 8.4.** `/etc/sudoers.d/institute-deploy` grants the
deploy account exactly three commands, two of which name `php8.4-fpm` literally.
Upgrading the box to 8.5 because CI passes there ends every subsequent deploy in
a failed reload.

### The clock

`provision.sh` enables `systemd-timesyncd` and **fails loudly** if
`timedatectl` does not report `System clock synchronized: yes`. This is not
housekeeping: TOTP codes are computed from the clock, so drift locks every
enrolled account — including the only `/superadmin` account — out at once.

---

## 4. Configure

```bash
sudo -u institute git clone <this-repo> /var/www/institute
cd /var/www/institute
sudo -u institute cp .env.production.example .env   # NOT .env.example: the local
                                                    # template ships APP_ENV=local,
                                                    # APP_DEBUG=true, BACKUP_KEEP=14
sudo -u institute php8.4 artisan key:generate
sudo -u institute nano .env
```

```env
APP_NAME="Big Binary Tech Institute"
APP_ENV=production          # REQUIRED: 'local' exposes the passwordless demo logins
APP_DEBUG=false             # REQUIRED: an error page otherwise prints the DB password
APP_KEY=base64:…            # generated above, ONCE
APP_URL=https://pos.bigbinaryerp.com   # REQUIRED https://

TRUSTED_PROXIES=127.0.0.1   # nginx is on this box. NEVER '*' — see below

DB_CONNECTION=mysql         # the config default is sqlite
DB_HOST=127.0.0.1
DB_DATABASE=institute_pos
DB_USERNAME=institute_pos
DB_PASSWORD=…               # printed by provision.sh
DB_TIMEZONE=+00:00          # REQUIRED

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true  # REQUIRED once HTTPS is on — see the ordering note
SESSION_ENCRYPT=true
CACHE_STORE=database
QUEUE_CONNECTION=database

BACKUP_PATH=/var/backups/institute
BACKUP_KEEP=168             # hourly dumps: 168 = seven days. NOT 14.
BACKUP_RCLONE_REMOTE=       # e.g. r2:institute-backups — see §7

INSTITUTE_TODAY=            # REQUIRED BLANK, or every "overdue" date freezes
INSTITUTE_CODE_PREFIX=BBT-  # decide ONCE, before the first voucher is issued
INSTITUTE_SELF_SERVICE_RESET=false
SUPERADMIN_PASSWORD=        # REQUIRED BLANK: the seeder mints one and prints it
```

Every line marked REQUIRED above is checked by `deploy.sh` before it does
anything, and the deploy refuses rather than warns. The table in §4.1 is the
same list with the failure each one prevents.

> **Never rotate `APP_KEY` after go-live.** It decrypts every stored two-factor
> secret; rotating it locks out every enrolled account at once.

> **`SESSION_SECURE_COOKIE` ordering.** Set it `false` for the very first deploy,
> run certbot (§6), then set it `true` and deploy again. A secure cookie over
> plain HTTP is never sent, so nobody can log in — including you, to fix it.

### 4.1 What each assertion prevents

`deploy.sh` reads these from `.env` directly, not from the cached config: it runs
`config:cache` itself, after which `.env` is no longer read at runtime.

| Assertion | Failure it prevents |
| --- | --- |
| `APP_ENV=production` | `local` exposes passwordless demo logins |
| `APP_DEBUG=false` | error pages print config and the DB password |
| `APP_KEY` non-empty | the app cannot decrypt anything, 2FA included |
| `DB_CONNECTION=mysql` | the config default is `sqlite` — you silently run on a file |
| `DB_HOST=127.0.0.1` | the app reaches a database that is not the one being backed up |
| `DB_TIMEZONE=+00:00` | `TIMESTAMP` and `DATETIME` columns disagree; payments near midnight land in the wrong month's revenue |
| `APP_URL` starts `https://` | password-reset links are built wrong |
| `BACKUP_PATH` non-empty | dumps fall back inside the tree the deploy git-resets |
| `TRUSTED_PROXIES` is not `*` | a forged `X-Forwarded-For` steps around the per-IP login brake |
| `INSTITUTE_TODAY` empty | "today" freezes; every overdue date and this-month figure stops moving |
| `SESSION_SECURE_COOKIE=true` | the session cookie travels in clear — asserted only once TLS is live |

### 4.2 The nginx `X-Forwarded-For` trap

The site config `provision.sh` writes uses `$remote_addr`, **not**
`$proxy_add_x_forwarded_for`. The latter *appends* to whatever the caller
claimed, so a forged entry survives to the application and the per-IP login
brake can be stepped around by rotating the header. With `TRUSTED_PROXIES`
pinned to `127.0.0.1` and nginx overwriting rather than appending, the address
the app throttles on is the one nginx actually saw.

---

## 5. First deploy

```bash
cd /var/www/institute
sudo -u institute php8.4 artisan migrate --force
sudo -u institute php8.4 artisan db:seed --class=RolePermissionSeeder --force
sudo -u institute php8.4 artisan db:seed --class=SuperAdminSeeder --force
sudo systemctl enable --now institute-queue
```

**Never run bare `php artisan db:seed` in production.** `DemoDataSeeder` creates
fictional students, fake revenue and three logins whose passwords are in this
repository. Only the two classes named above, by name.

`SuperAdminSeeder` prints the owner password once. Record it (§11), sign in at
`/superadmin`, and complete 2FA enrolment immediately.

Then, for every release after this one, the pipeline runs:

```bash
sudo -u institute RELEASE_SHA=<commit> BUILD_ASSETS=no \
  bash /var/www/institute/deploy/deploy.sh
```

`RELEASE_SHA` is **mandatory and has no fallback**. The script used to
`git reset --hard origin/main`, which is a race: if commit A passes CI and gets
approved and someone pushes B before the SSH stage runs, you deploy B. The
pipeline passes the approved commit and the script refuses without one.

The order is fixed and there is no flag to skip any of it:

```text
preflight → backup → maintenance on → code → assets → migrate → caches
          → maintenance off → health check (local + public) → verdict
```

Everything that can refuse — the SHA, the eleven `.env` assertions, the staged
build — refuses in **preflight, before the backup**, so a typo or a missing
build never surfaces halfway through with the site already in maintenance mode.

The backup happens **before** the migration, every time. If the backup fails the
deploy does not start. If the health check fails the code is rolled back and
maintenance mode is left **on** — a half-migrated database behind a working
login screen is worse than a maintenance page, because the counter will start
taking money against it. **The database is never auto-rolled-back.**

Two overlapping pipeline runs cannot interleave: the whole script holds a
non-blocking `flock` on `/run/institute-deploy.lock` and the second one exits
immediately, saying so.

---

## 6. HTTPS

Only after DNS points at the static IP:

```bash
sudo certbot --nginx -d pos.bigbinaryerp.com
```

Renewal installs its own systemd timer. Verify it once:

```bash
sudo certbot renew --dry-run
```

Then set `SESSION_SECURE_COOKIE=true` in `.env` and deploy again.

Certbot renews on a 90-day cycle that nobody will be watching in November. Turn
**TLS expiry alerting on** in the external monitor (§9).

---

## 7. Backups and recovery

Two commands, both on the scheduler:

| When | Command | What it proves |
| --- | --- | --- |
| **Hourly** | `backup:run` | A dump exists |
| Sunday 03:00 | `backup:verify` | The dump can actually be **restored** |

**Hourly, not nightly.** The worst case is what the institute loses, and at
nightly that was up to twenty-four hours of counter transactions. Hourly moves
it to one, using the mechanism already proved every Sunday — no second restore
methodology and no binlog PITR to learn under pressure.

`backup:run` writes a gzipped, restore-ready dump to `/var/backups/institute`
and records the event in the institute's own audit log. It **fails loudly** — a
dump below 1 KB is treated as a failure and deleted, because a scheduled job that
goes green while writing nothing is the exact disaster the command exists to
prevent.

Beside each dump it writes a `.json` manifest of row counts taken *before* the
dump. That manifest is what makes the weekly drill meaningful: comparing a
restored dump against the *live* database can only ever fail, because the roll
moves and the audit log only ever appends. Measured against the manifest, the
question becomes the one worth asking — did everything that went into this file
come back out of it?

Both the hourly dump and the mandatory pre-deploy dump take the same
`flock` on `/run/institute-backup.lock`, so a deploy that starts on the hour
cannot collide with the scheduler.

### 7.1 Retention — `BACKUP_KEEP=168`, not 14

`BACKUP_KEEP` prunes by **count, not age**. That was harmless at one dump a night
and is a trap at one an hour: `BACKUP_KEEP=14` would give you fourteen *hours* of
history while every check stayed green and yesterday quietly disappeared.

- **On the box:** `BACKUP_KEEP=168` — seven days of hourly dumps. They are small.
- **Off the box:** keep everything, and put a **30-day lifecycle rule** on the
  bucket. Long retention belongs off-box, which is where it should live anyway.

### 7.2 Get a copy off the box

This is the difference between a backup and a disaster-recovery plan: a dump on
the same disk as the database dies with the disk.

Set `BACKUP_RCLONE_REMOTE` in `.env` and `backup:run` copies each dump off after
a **successful** run only — a failed or undersized dump is never propagated:

```env
BACKUP_RCLONE_REMOTE=r2:institute-backups
```

which runs:

```bash
rclone copy /var/backups/institute <remote> --max-age 2h
```

Leave it blank and the copy is skipped with a warning in the log rather than a
silent no-op. Configure the remote as the `institute` user
(`sudo -u institute rclone config`) so the scheduler can read it, and put a copy
of `~institute/.config/rclone/rclone.conf` in the off-box secret store (§11) —
losing it means losing access to your own backups.

Destinations that hold this comfortably: Cloudflare R2 (10 GB free), Backblaze
B2 (10 GB free), or any Google Drive via `rclone`.

### 7.3 MySQL durability — inspect, do not change

```sql
SHOW VARIABLES WHERE Variable_name IN (
  'innodb_flush_log_at_trx_commit', 'sync_binlog',
  'log_bin', 'binlog_expire_logs_seconds'
);
```

These are already at their safe defaults in MySQL 8 —
`innodb_flush_log_at_trx_commit=1` and `sync_binlog=1` together mean a committed
payment survives a power cut. **Do not "tune" them.** Record the actual output
here after provisioning, along with `du -sh /var/lib/mysql/binlog.*`, so a later
disk-space question has a baseline to compare against.

```text
Recorded on <date>, MySQL <version>:
  innodb_flush_log_at_trx_commit = ...
  sync_binlog                    = ...
  log_bin                        = ...
  binlog_expire_logs_seconds     = ...
  binlog disk usage              = ...
```

`innodb_buffer_pool_size` is deliberately left at its default. 25 tables and 450
students. Measure before tuning.

### 7.4 A restore is only proved by a login

Row counts matching is not proof of recovery. The drill passes when **all four**
of these hold:

```text
database restored  +  the same APP_KEY  +  Laravel boots  +  an existing
/superadmin account completes TOTP authentication
```

Without the original `APP_KEY` every stored TOTP secret is undecryptable and
every enrolled account is locked out — **the dump alone is worthless**. That is
why §11 exists and why it lists `APP_KEY` first.

Run the drill by hand any time:

```bash
sudo -u institute php8.4 artisan backup:verify
```

Restoring for real:

```bash
gunzip -c /var/backups/institute/bbt-backup-<stamp>.sql.gz \
  | mysql -u institute_pos -p institute_pos
```

You do not need to look that up under pressure — the weekly drill has already
run it.

---

## 8. CI and the release pipeline

`.github/workflows/ci.yml` is the **only** pipeline. `azure-pipelines.yml` was
deleted on 2026-08-29 (`decisions.md` D54) — the Azure DevOps organisation had
no credits, so it could never have run. There is deliberately no mirror now:
two definitions of the same thing drift, and the one nobody reads becomes a
check that cannot fail, which is worse than no check because it is still
trusted.

The required status check is the job named exactly **`CI`**. That name is
configured in branch policy, outside this repository, so renaming it silently
stops protecting `main`.

### Registering the pipeline, and the `DEPLOY_ENABLED` gate

Unlike Azure DevOps, GitHub Actions **does** discover a workflow on its own:
`.github/workflows/ci.yml` runs from the moment it is on `main`. There is no
pipeline object to create. What still has to be created by a human is everything
that gates it — see "One-time GitHub setup" below.

`DEPLOY_ENABLED` in the workflow's `env:` block is `'false'` until a production
box exists. The `tests` and `assets` jobs need no server — they run on
GitHub-hosted runners — so the workflow is useful from the first push. `deploy`
and `smoke` skip cleanly rather than failing on a missing runner.

This exists because a pipeline that is red on every run is a pipeline nobody
reads, and the cost of that is not the noise: it is that a **real** failure in
the test matrix gets waved through on the day it matters.

Turning it on is a commit, not a click, so enabling production deployment
carries an author and a reviewer in the history and reverting is one revert.
Do **not** make it a `workflow_dispatch` input. It is a convenience gate, **not**
the security control — the required reviewers, the branch restriction and the
concurrency group are, and the first two are configured in the UI (see below).

```text
CI  ──►  assets  ──►  deploy  ──►  smoke
 │         │            │           │
 │         │            │           └─ GitHub-hosted runner, public GET
 │         │            │              https://…/ready
 │         │            └─ self-hosted runner ON the box, as `institute`
 │         │               stage build-assets to /var/www/institute-builds/<sha>
 │         │               RELEASE_SHA=<sha> BUILD_ASSETS=no deploy.sh
 │         └─ Node 24 · npm ci · npm run build · upload `build-assets`
 └─ PHP 8.4 × MySQL 8.0 (production parity) · 8.4 × MySQL 8.4 · 8.4 × sqlite
    · 8.5 × sqlite · pint --test · artisan test
```

### The matrix

The **PHP 8.4 / MySQL 8.0** leg is the one that gates, because `provision.sh`
installs Ubuntu 24.04's `mysql-server`, which is the **8.0.x** line. Gate on the
version you actually run.

The MySQL 8.4 leg stays as forward-compatibility cover. It has already earned its
place once: it caught `admissions.status => 'active'`, a value absent from the
column's enum, which would have failed every one of the 478 roll-import rows on
MySQL while passing on SQLite.

**Do not upgrade production to MySQL 8.4 to make CI match.** Ubuntu's supported
package is the lower-risk option.

### Assets are built in CI, never on the box

Nothing generated enters git; `public/build` is git-ignored and stays that way.

```text
GitHub CI (Node 24) npm ci && npm run build  ->  public/build/
        └─ publish pipeline artifact `build-assets`
              └─ deploy stage copies to /var/www/institute-builds/<sha>/
                    └─ deploy.sh activates it, inside maintenance mode
```

Staged under `/var/www/institute-builds/`, **not `/tmp`** — `systemd-tmpfiles`
cleans `/tmp`, and a build that silently evaporates between the push and the
deploy is exactly the failure you do not want to be quiet.

`deploy.sh` refuses in preflight if
`/var/www/institute-builds/$RELEASE_SHA/manifest.json` is missing, activates the
directory only **after** maintenance mode is on and the code has been reset, and
prunes the staging area to the three most recent releases. Copying new assets
over a live site running old code gives a mismatched manifest, and
content-hashed filenames only *usually* save you.

### The deploy runs ON the box, not over SSH

The deployment job targets a **self-hosted runner** installed on the production
box, labelled `institute-prod`. It does not SSH in from a hosted runner.

That is a security decision, not a convenience one. An SSH-from-hosted-agent
design needs port 22 reachable from Microsoft's published address ranges, which
in practice means 22 open to the world on a box that bills course fees. A VM
self-hosted runner dials **out** to GitHub and polls for work, so 22 stays
restricted to the administrator's own IP and there is no inbound deployment path
at all.

### One-time GitHub setup (a human does this once)

**These are UI settings. They are deliberately NOT in the workflow file, and
they cannot be** — a workflow cannot meaningfully gate itself, because anything
it said about who may approve it is something a pull request could change.

#### 1. Create the environment

**Settings → Environments → New environment**
- Name: **`production`** (exactly — the workflow references it)

Add both protections on it:

| Setting | Value | Why |
| --- | --- | --- |
| **Required reviewers** | you and one other | a human presses go on money software |
| **Deployment branches** | Selected → `main` | stops a feature branch reaching production |

One-at-a-time is already in the workflow as `concurrency: production-deploy`.
It is the **first** of two layers. The second is the `flock` in `deploy.sh`, and
it is the one that matters — the GitHub concurrency group does not cover a human
who SSHes in and runs a release by hand.

#### 2. Register the self-hosted runner

**Settings → Actions → Runners → New self-hosted runner → Linux x64.** On the
server, **as the `institute` service account**, not as root:

```bash
sudo -u institute -H bash
cd ~ && mkdir -p actions-runner && cd actions-runner
# paste the download commands GitHub shows, then:
./config.sh --url https://github.com/<owner>/<repo> \
            --token <REGISTRATION_TOKEN> \
            --labels institute-prod \
            --unattended
```

The **`institute-prod` label is load-bearing**: the deploy job is
`runs-on: [self-hosted, institute-prod]`. A runner without it is invisible to
the workflow and the job queues indefinitely rather than failing — which reads
as a hang, not as a misconfiguration.

Then install it as a service so it survives a reboot:

```bash
sudo ./svc.sh install institute
sudo ./svc.sh start
sudo ./svc.sh status
```

> **Run the runner as `institute`, not root.** That account holds exactly three
> sudo grants — reload `php8.4-fpm`, restart and status `institute-queue` — and
> nothing else. Registering it as root to "simplify the pipeline" hands every
> future edit of the workflow unrestricted control of the box.
>
> The registration token is single-use and expires in about an hour, so unlike
> a PAT there is nothing to revoke afterwards. It must still never be committed.

#### 3. Branch protection on `main`

**Settings → Branches → Branch protection rules:**
- Require status checks to pass, and require the check named exactly **`CI`**

Renaming that job silently stops protecting the branch: the rule goes on waiting
for a check that no longer exists.

#### 4. Repository access from the box

The box still needs to `git fetch` this repository. Use a **read-only deploy
key**, never a developer's PAT and never write access:

```bash
sudo -u institute ssh-keygen -t ed25519 -N "" -f ~institute/.ssh/id_ed25519
sudo -u institute cat ~institute/.ssh/id_ed25519.pub
# Add at: Settings > Deploy keys > Add deploy key
#         leave "Allow write access" UNCHECKED
cd /var/www/institute
sudo -u institute git remote set-url origin git@github.com:<owner>/<repo>.git
sudo -u institute git fetch origin      # prove it before you need it
```

PATs expire, always at the worst moment, and the failure looks like an
unexplained deploy break months later. **Rotation:** generate a new key pair,
add the public half, remove the old one, and record the change in the secret
store. This whole dependency disappears in week two, when the application ships
as an immutable artifact.

---

## 9. Seeing what is happening

Everything below is on the box already; none of it needs another service.

| Question | Where |
| --- | --- |
| Is the process alive? | `curl -sf http://127.0.0.1/up` |
| Is the app actually *ready*? | `curl -sf https://pos.bigbinaryerp.com/ready` |
| Did the scheduler run? | `journalctl -u institute-scheduler --since today` |
| Is the queue alive? | `systemctl status institute-queue` |
| Did the last hourly backup work? | `ls -la /var/backups/institute` |
| Did the restore drill pass? | `journalctl -u institute-scheduler -g backup:verify` |
| Is the clock still right? | `timedatectl` — 2FA depends on it |
| What did the application log? | `tail -f storage/logs/laravel.log` |
| Slow pages? | `tail -f /var/log/php-fpm-institute-slow.log` |
| Who did what, in the app? | Super admin → Activity log |
| What was deployed, when? | Super admin → Activity log ("Deployment completed") |

### `/up` and `/ready` are different questions

`/up` is Laravel's built-in health route: the framework booted. `/ready` is
registered beside it and additionally proves `SELECT 1` reaches MySQL and the
storage paths are writable. It returns a **bare 200 or a bare 503** and nothing
else — no exception text, no hostname, no path, no diagnostic JSON. It is
publicly reachable, so it says nothing an attacker could use.

`deploy.sh` checks `/up` on localhost *and* `/ready` over public HTTPS, because
the localhost check cannot prove DNS, the firewall, TLS, or that MySQL is
reachable.

### Alerting — the one thing that is not on the box

Point a free external monitor at **`https://pos.bigbinaryerp.com/ready`** —
UptimeRobot or Better Stack, both free for this. Email or WhatsApp.

> **Alert on two consecutive failures, not one.** `/ready` returns 503 during
> every deploy, because Laravel's maintenance middleware covers it. That is
> correct and is how you find out a failed deploy left maintenance mode on. But
> a monitor that pages on a single failure pages on every routine release, and
> gets muted within a fortnight.

Turn **TLS expiry alerting on** in the same monitor.

An external monitor is the only thing that tells you the site is down **before
the counter does**, and the only check that survives the box itself going away.

---

## 10. When something is wrong

```bash
# The usual suspects, in order
sudo systemctl status php8.4-fpm nginx mysql institute-queue
sudo tail -50 /var/log/nginx/institute-error.log
sudo tail -50 /var/www/institute/storage/logs/laravel.log
df -h                     # a full disk looks like a hundred unrelated bugs
free -m                   # if swap is being used hard, MySQL is about to suffer
timedatectl               # drift locks every 2FA account out at once
```

**"I changed `.env` and nothing happened."** Almost always `config:cache`.
Configuration is read from the cache, not from `.env`, and the cache is rebuilt
during deploy. Run `php8.4 artisan config:cache`.

**"The deploy says the lock is held."** Another release is in flight, or one died
without releasing. `flock` releases on process exit, so a held lock means a live
process: `ps aux | grep deploy.sh` before assuming otherwise.

**"The site is in maintenance mode and I do not know why."** That is a failed
deploy, left that way deliberately. Read the tail of the pipeline log, decide
whether the database needs the pre-deploy dump restored, and only then
`php8.4 artisan up`.

---

## 11. The off-box secret store

Everything in this list is required to bring the institute back from a dump.
Losing any one of them makes the others insufficient. Keep them in a password
manager, off this box, reachable by **at least two people** — see the DR runbook
(`docs/PRODUCTION-EMERGENCY.md`), which is the page to hand someone who is not the
engineer.

| Secret | Also at | Why it is fatal to lose |
| --- | --- | --- |
| `APP_KEY` | `/var/www/institute/.env` | Decrypts every stored TOTP secret. Without it, a perfect restore locks out every enrolled account. |
| Database password | `/root/.institute-db-password` | Cannot restore into, or read, the database. |
| Super-admin recovery credential | — | No way into `/superadmin` to fix anything. |
| SSH / deployment private key | your workstation | No way onto the box at all. |
| rclone remote configuration | `~institute/.config/rclone/rclone.conf` | No access to the off-box backups. |
| Lightsail (AWS) account login | — | Cannot rebuild the instance or move the static IP. |
| Domain registrar login | — | Cannot repoint DNS at a replacement. |

---

## 12. The rehearsal — twice, before production exists

**Production must not be the first machine this path runs on.** Everything in
this document has been written, reviewed and syntax-checked; none of it has
provisioned a real instance. Until it has, "it should work" is a claim, not a
fact, and the difference is a Monday morning at a counter that cannot open.

### The rule

Run the whole path on a **disposable** instance at the **exact production spec**
— Mumbai, Ubuntu 24.04, 4 GB / 2 vCPU / 80 GB, IPv4-capable. A 2 GB box does not
rehearse the production resource profile, and the FPM sizing in §3 is the reason.

Then **destroy it**, create another from nothing, and do it all again — this time
**without manually compensating for any script failure**. If the second run needs
hand-holding, the scripts are not finished. Fix the script or the runbook,
destroy, recreate, and prove it again.

**Do not normalise undocumented fixes.** A step that lives only in somebody's
head is a step that will not happen at 2am.

### What each run must exercise

Use no production data and no production secrets. A throwaway domain or the raw
static IP is fine; for TLS use a real hostname you control, or accept that the
certbot step is the one thing rehearsed only on the production run.

- [ ] `provision.sh` from bare, one command, no prompts
- [ ] `provision.sh` a **second** time — changes nothing (it doubles as the repair tool)
- [ ] Records the MySQL version, and it is the **8.0.x** line
- [ ] FPM pool is `ondemand`, 6 children, 256M
- [ ] `/var/backups/institute` and `/var/www/institute-builds` exist, owned by `institute`
- [ ] `/etc/tmpfiles.d/institute.conf` created both lock files
- [ ] `timedatectl` reports synchronised, and the script fails loudly if it does not
- [ ] MySQL is on loopback only — `ss -lntp | grep 3306` shows `127.0.0.1`
- [ ] Migrations run; **only** `RolePermissionSeeder` and `SuperAdminSeeder`
- [ ] Self-hosted runner registers with the `institute-prod` label, as `institute`
- [ ] A pipeline deploy succeeds end to end with the exact SHA
- [ ] Assets stage under `/var/www/institute-builds/<sha>/` and activate
- [ ] **Bad SHA refuses** — before the backup, before maintenance mode
- [ ] **Missing artifact refuses** — same
- [ ] **Unsafe `.env` refuses** — try `TRUSTED_PROXIES=*` and `BACKUP_KEEP=14`
- [ ] **Deployment lock** — start two deploys, the second exits immediately
- [ ] `backup:run` writes a dump and a manifest
- [ ] `backup:verify` restores into the scratch database and passes
- [ ] Off-box `rclone copy` lands the dump in the bucket
- [ ] Restore into a scratch database, then **complete a `/superadmin` TOTP login**
- [ ] `/ready` is 200 publicly; 503 during `artisan down`
- [ ] `certbot --nginx` succeeds and `certbot renew --dry-run` passes
- [ ] Queue worker and scheduler timer are enabled and running after a **reboot**

Then: **delete the instance**, and do it again.

---

## 13. First-server bootstrap, in order

Once the rehearsal has passed twice. Every step, in sequence.

1. Lightsail → create instance: Mumbai, Ubuntu 24.04, **$24 / 4 GB / 2 vCPU /
   80 GB**, IPv4-capable, your SSH key. (§2.2)
2. Attach a **static IPv4**. (§2.3)
3. **Disable IPv6** on the instance, or configure both firewall rule sets. (§2.4)
4. Firewall: **22 from the admin IP only**, 80 and 443 open. No 3306. (§2.4)
5. Point DNS `pos.bigbinaryerp.com` → the static IP. Wait for it to resolve.
6. `ssh ubuntu@<static-ip>`
7. `git clone <repo> /tmp/pos && cd /tmp/pos`
8. `sudo bash deploy/provision.sh`
9. **Record the generated DB password and the printed MySQL version.** (§11)
10. `sudo -u institute git clone <repo> /var/www/institute`
11. `sudo -u institute cp .env.production.example .env` and fill it in. (§4)
12. `sudo -u institute php8.4 artisan key:generate` — **once, ever**
13. **Store `APP_KEY` off-box immediately.** Before anything else. (§11, §7.4)
14. Verify, by eye and then by command:
    ```bash
    cd /var/www/institute
    sudo -u institute php8.4 artisan deploy:preflight
    ```
    `APP_ENV=production`, `APP_DEBUG=false`, `DB_CONNECTION=mysql`,
    `DB_TIMEZONE=+00:00`, `INSTITUTE_TODAY` blank, `TRUSTED_PROXIES=127.0.0.1`,
    `BACKUP_PATH=/var/backups/institute`, `BACKUP_KEEP=168`, and
    **`INSTITUTE_CODE_PREFIX` confirmed by the institute**.
    Set `SESSION_SECURE_COOKIE=false` for now — TLS does not exist yet.
15. `sudo -u institute php8.4 artisan migrate --force`
16. Seed, **by name only**:
    ```bash
    sudo -u institute php8.4 artisan db:seed --class=RolePermissionSeeder --force
    sudo -u institute php8.4 artisan db:seed --class=SuperAdminSeeder --force
    ```
17. **Never `php artisan db:seed` bare.** `DemoDataSeeder` creates fictional
    students, fake revenue, and three logins whose passwords are in this
    repository.
18. **Record the super-admin password** printed once by the seeder. (§11)
19. Confirm DNS resolves to this box: `dig +short pos.bigbinaryerp.com`
20. `sudo certbot --nginx -d pos.bigbinaryerp.com`
21. `sudo certbot renew --dry-run`
22. Set `SESSION_SECURE_COOKIE=true` in `.env`
23. `sudo -u institute php8.4 artisan config:cache`
24. `sudo systemctl enable --now institute-queue && systemctl status institute-queue`
25. `systemctl status institute-scheduler.timer`
26. Register the box as a self-hosted runner in the `production` Environment (§8)
27. Confirm the agent runs as `institute`, not root
28. Configure approvals, branch control and the exclusive lock (§8)
29. Only now allow a normal pipeline deploy.

---

## 14. Before the counter opens

Deployment being done is not the same as being ready to take money.

### Smoke test, on production, with the client watching

Through a **browser**, never PHPUnit — the suite uses `RefreshDatabase` and
would drop every table in `institute_pos`.

- [ ] `https://pos.bigbinaryerp.com/ready` returns **200**
- [ ] Super-admin login at `/superadmin` works
- [ ] TOTP works — both an existing enrolment and a fresh one
- [ ] Dashboard loads
- [ ] **Reports** loads
- [ ] **Staff & Roles** loads
- [ ] **Courses** loads
- [ ] **Settings** loads
- [ ] Register a test student
- [ ] Create and inspect a challan
- [ ] Take an **authorised** test payment
- [ ] Take a **part** payment; the balance is correct
- [ ] Print a challan PDF
- [ ] Print a receipt PDF
- [ ] Export a spreadsheet report
- [ ] Dashboard and Reports **agree** on the same figures
- [ ] Reverse the test payment; every money figure drops by exactly that amount
- [ ] The receipt reprints stamped **Reversed**
- [ ] Activity log shows the enrolment, the payment, the reversal, and
      "Deployment completed"

> **Reports, Staff & Roles, Courses, Settings and `/superadmin` have never been
> driven in a browser.** Every round of work that did include a browser pass
> found defects the suite could not see. Budget real time for this.

> **Do not leave fake financial transactions in production** unless the institute
> explicitly authorises them. Agree beforehand whether the test student and its
> payment are removed, reversed, or kept as the first real record.

### The timestamp check, which is its own item

- [ ] The receipt timestamp reads **Lahore** time, not UTC
- [ ] "Today's collection" matches what the counter actually took **today**,
      on the Lahore calendar day

Storage is UTC by design. The display layer is what nine staff look at, and a
five-hour offset on a receipt erodes confidence in a fee system on day one.

### Monitoring

- [ ] External monitor on `https://pos.bigbinaryerp.com/ready`, 5-minute interval
- [ ] Alerting on **two consecutive failures**, not one (§9)
- [ ] **TLS expiry alerting on** — certbot renews on a 90-day timer nobody will
      be watching in November

### Backup verification, after seven days of operation

- [ ] `ls -lt /var/backups/institute/*.sql.gz | head -1` — newest is under an hour old
- [ ] `ls /var/backups/institute/*.sql.gz | wc -l` — **168**, not 14
- [ ] `ls -t /var/backups/institute/*.sql.gz | tail -1` — oldest is **~7 days** old
- [ ] `rclone lsl <remote> | sort -k2 | tail -1` — remote newest is under an hour old
- [ ] The bucket's 30-day lifecycle rule is actually configured

If the oldest local dump is ~14 hours rather than ~7 days, `BACKUP_KEEP` is still
14. That is the failure this whole retention section exists to prevent.

### Decisions the institute must make — not work an engineer can do

- [ ] **The 8 unanswered roll rows.** Two lines contradict each other on the same
      student/course; six collections exceed their fee. Do not guess. Keep them
      excluded until the institute answers, then dry-run, back up, and
      `roll:import --commit`.
- [ ] **The 54 duplicate students.** Run `records:duplicates` against production
      and walk them with the institute. **Do not auto-merge** without approved
      rules.
- [ ] **`INSTITUTE_CODE_PREFIX`.** Changing it after go-live does not rewrite
      issued vouchers; the identifier series stays permanently inconsistent.
- [ ] **Payment-reversal business rules.** The mechanism is built and tested.
      Undecided: whether a **partial** reversal is permitted at all, and whether
      reversing implies handing cash back today or crediting the next
      instalment. See `decisions.md` D38.

---

## 15. Correcting a payment

`payments` is append-only and strictly positive. A mistyped amount is corrected
by recording an offsetting entry, never by editing or deleting the original.

**In the app** (normal route): Challans or Registrations → open the challan →
the reversal control beside the payment. Supervisor only — it requires
`payments.reverse`, which Administrator holds and Admission Officer deliberately
does not. That separation is the control: the person who mistyped the amount is
not the person who undoes it.

**From the command line** (when the UI cannot reach it — a cancelled enrolment,
a scripted correction, nobody yet holding the permission):

```bash
cd /var/www/institute
sudo -u institute php8.4 artisan payments:reverse <payment-id> \
  --actor=<supervisor-username> \
  --amount=<pkr> \
  --reason="Amount mistyped at the counter — 50,000 entered for 5,000"
```

Both routes call the same service and enforce the same rules: cumulative
reversals can never exceed the payment, a reason is required, a reversal cannot
itself be reversed, the challan reopens if a balance returns, and an
`audit_logs` entry is written naming the supervisor.

Every money figure then reports `gross − reversals`. The receipt reprints with a
**Reversed** stamp rather than a quietly smaller number — the figure on a
document a parent is holding does not change retroactively.

---

## 16. Known limits of this shape

- **One box means one failure domain.** There is no standby. The recovery plan is
  the backup, which is why the drill runs weekly rather than never, and why the
  dumps go off-box hourly.
- **Recovery point is one hour, recovery time is manual.** Binlog PITR would
  shrink the first; it is deliberately deferred until a one-hour RPO is shown to
  be insufficient.
- **The box still needs repository credentials** to `git fetch` a release. Week
  two replaces that with an immutable CI artifact.
- **Deploys are in-place, not atomic.** The releases-directory and `current`
  symlink design is the right long-term answer and is deferred: with symlink
  deploys, opcache keys compiled files by path, so unless nginx builds
  `SCRIPT_FILENAME` from `$realpath_root` the site keeps serving the previous
  release's code after the swap — the deploy reports success, the health check
  passes, and old code runs. Not a thing to meet on the same night as the first
  production box.

---

## 17. Deferred to week two

Introduce these only once production is running, each rehearsed on a disposable
instance first:

- Immutable CI artifact (`release-<sha>.tar.gz`); production stops needing
  repository credentials
- Releases directory + `current` symlink, with nginx `$realpath_root` for
  `SCRIPT_FILENAME`
- Removing Node and git from the production box entirely
- Lightsail automatic snapshots — host recovery, **not** database recovery
- Binlog point-in-time recovery, **only if** a one-hour RPO proves insufficient
- Expand/contract migration rule: never drop or rename something in the same
  release that introduces its replacement
- Cashier shift / drawer reconciliation (GAP-06)
