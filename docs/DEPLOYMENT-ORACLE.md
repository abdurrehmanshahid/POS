# Deploying to an Oracle Cloud Always Free VM

Target: Ubuntu 24.04 LTS on Ampere A1 (ARM64), $0/month, forever. Everything
runs as an ordinary systemd service — nginx, PHP-FPM and MySQL, installed from
packages. **There is no Docker anywhere in this deployment.** A container
runtime is one more moving part that can fail at 9am on a Monday for reasons
that have nothing to do with the application.

For the cPanel path see [DEPLOYMENT.md](DEPLOYMENT.md). Do not use
[DEPLOYMENT-VERCEL.md](DEPLOYMENT-VERCEL.md) for this institute: Vercel's Hobby
plan forbids commercial use, and this system bills course fees.

---

## 1. What you are building

```text
                    ┌─────────────────────────────────────────┐
   browser ──443──► │  nginx  ──socket──►  PHP-FPM (pool)     │
                    │    │                     │              │
                    │    │                 MySQL 8 (localhost)│
                    │    └── /up health       │               │
                    │                    /var/backups/institute│
                    │  systemd: scheduler timer, queue worker │
                    └─────────────────────────────────────────┘
                                  one VM, no other providers
```

One box, one database, no external services. That is not a compromise forced by
the free tier — it is the right shape for this workload. The UI is Livewire, so
every interaction is a server round trip; putting the database on another
provider would add a network hop to each one, plus a second free tier that can
be withdrawn.

**Sizing.** 2 OCPU / 12GB is the Always Free allowance since 15 June 2026
(halved from 4/24, with no announcement — see §10). For 451 students and nine
staff accounts it is enormous. MySQL will use around 400MB, PHP-FPM around
600MB across 12 workers.

---

## 2. Creating the account and the instance

### 2.1 Before you start — the two things that fail

Both of these are far easier to get right first time than to fix afterwards.

**Your card must be able to take an international charge.** Oracle places a
temporary verification hold (about US$1, released by the bank within a few
days). Most Pakistani debit and credit cards ship with international or
e-commerce transactions **disabled by default** — HBL, Meezan, UBL, Allied and
Standard Chartered all require you to switch it on in the app or by calling the
bank. This is the single most common cause of the `Error Processing Transaction`
message, and Oracle's error text gives no hint that this is what it means.

Oracle accepts a credit card, or a debit card carrying a Visa/Mastercard logo
that does not require a PIN. It **rejects** prepaid, virtual and single-use
cards outright — do not bother with a disposable card number.

**Your home region is permanent.** It is chosen during signup, cannot be changed
afterwards, and Always Free resources exist *only* in it. Getting it wrong means
deleting the tenancy and starting over.

### 2.2 Which home region

From Pakistan the shortlist is **UAE East (Dubai)** `me-dubai-1` and **India West
(Mumbai)** `ap-mumbai-1`.

**Prefer Dubai.** Mumbai is marginally closer on a map, but Pakistani ISPs have
limited direct peering with Indian networks and traffic frequently routes the
long way round; Karachi's submarine cable landings connect to the Gulf directly.
Dubai is the more predictable path, and predictability is what a counter needs.

Never pick a US or European region. Every Livewire interaction is a full server
round trip — typing in a search box, opening a dropdown, adding a payment line.
At ~250ms each the counter will feel broken while nothing is actually wrong.

### 2.3 Signing up

Go to **[oracle.com/cloud/free](https://www.oracle.com/cloud/free/)** → *Start
for free*.

1. Email, and **Country: Pakistan**. This sets the currency and the address
   format; it does not restrict which region you may choose.
2. Verify the email — the link expires quickly, so do it straight away.
3. Set a password, then choose the **home region**. This is the irreversible
   step (§2.2).
4. Address, and a mobile in `+92…` form for the SMS code.
5. Card details. The billing address must match what the bank holds, exactly.
6. Accept the agreement and submit.

Provisioning takes roughly 10–30 minutes and finishes with an email. Sign in at
**[cloud.oracle.com](https://cloud.oracle.com)**.

> **Do not use a VPN during signup.** A country that disagrees with the card's
> issuing country is a common silent rejection.
>
> **If the card is refused repeatedly** after international transactions are
> confirmed on, use the **Chat** link on Oracle's free-tier page. Support can
> verify an account manually, and for Pakistan that is a normal outcome rather
> than an escalation.

### 2.4 The 30-day trial is not the free tier

Signing up grants US$300 of credit for 30 days **on top of** the Always Free
resources. The two are easy to confuse, and the confusion has a bill attached.

- Create **only** resources labelled **"Always Free eligible"** in the console.
- When the 30 days end, the account drops to Always Free automatically and keeps
  running. You do **not** need to upgrade, and upgrading to Pay As You Go is
  what turns an accidental non-free resource into an invoice.

### 2.5 Build the network before the instance

Do not let the instance wizard create the network inline. **A subnet is public or
private for life** — the choice cannot be changed afterwards — and when the
inline form produces a private one, the "Assign a public IPv4 address" toggle is
greyed out with no way to retrofit it. The instance has to be destroyed and
rebuilt.

**Networking → Virtual Cloud Networks → Start VCN Wizard → *Create VCN with
Internet Connectivity*.** Name it `institute-vcn`, accept the default CIDRs
(`10.0.0.0/16`, public subnet `10.0.0.0/24`), create.

> **If the wizard fails on the NAT gateway**, the tenancy has its NAT limit set
> to 0 — some Always Free accounts do. Nothing here needs one: the app and its
> database share one box that has to be publicly reachable, so there is no
> private subnet to service. Build it by hand instead:
>
> 1. **Create VCN** — `institute-vcn`, `10.0.0.0/16`
> 2. **Create Subnet** — `public`, `10.0.0.0/24`, type **Public Subnet**
> 3. **Create Internet Gateway** — `igw`
> 4. **Default Route Table → Add Route Rule** — `0.0.0.0/0` → Internet Gateway `igw`
>
> Step 4 is the one that is silently fatal. Skip it and the instance still gets
> a public IP and still looks healthy in the console — it simply never answers.

Then add the ingress rules on that VCN's **Default Security List**: TCP **80**
and TCP **443** from `0.0.0.0/0`. Port 22 is already there.

### 2.6 Creating the instance

1. **Compute → Instances → Create**
2. Image: **Ubuntu 24.04** · Shape: **VM.Standard.A1.Flex**, **2 OCPU / 12GB**
   — confirm the *Always Free eligible* badge is showing before you continue
3. Add your **SSH public key** (`~/.ssh/id_ed25519.pub`, or generate with
   `ssh-keygen -t ed25519`). Losing the private key means losing the box —
   Oracle cannot reset it for you.
4. Networking → **Select existing virtual cloud network** → `institute-vcn` →
   subnet **public** → **Assign a public IPv4 address: Yes** (see §2.5)
5. Boot volume: the default (~46GB) is fine; the Always Free allowance is 200GB

Then `ssh ubuntu@<public-ip>` and continue at §3.

> **"Out of capacity" is normal and is not a problem with your account.**
> Ampere A1 is heavily oversubscribed. Capacity is released continuously, so
> retrying at a quieter hour is the lever that works.
>
> In multi-AD regions you can also try each availability domain in turn.
> **Mumbai (`ap-mumbai-1`) has only one AD**, so there is no second domain to
> fall back on there — retrying over time is the only option.

---

## 3. Provision

```bash
ssh ubuntu@<public-ip>
git clone <this-repo> /tmp/pos && cd /tmp/pos
sudo bash deploy/provision.sh
```

Idempotent — safe to re-run, and it doubles as the repair tool after somebody
changes something by hand. It installs PHP 8.4, MySQL 8, nginx, Node 20,
Composer, fail2ban and unattended security upgrades; creates the database and a
scoped user; writes the FPM pool, the nginx site, the systemd units and log
rotation; adds swap; and opens ports 80/443.

It prints the generated database password **once**. Record it.

### PHP 8.4 is a hard floor, not a preference

Ubuntu 24.04 ships PHP 8.3 and this application cannot run on it. Laravel 13
pulls in Symfony 8, and twenty of its components declare `php >=8.4.1` —
`composer install` refuses outright. That is why provisioning adds the `ondrej`
PPA, and it is also why free shared hosting is not an option for this project:
most of it caps at 8.3.

### The Oracle firewall trap

Oracle's Ubuntu image ships iptables rules that drop everything except SSH,
**in addition to** the cloud Security List. `provision.sh` handles the local
half. You must still do the other half by hand:

> **Networking → Virtual Cloud Network → Security Lists → Ingress Rules**
> Add TCP 80 and 443 from `0.0.0.0/0`.

Both layers must allow the port. This is the single most common reason an
Oracle VM appears unreachable while every dashboard says it should be fine.

---

## 4. Configure

```bash
sudo -u institute git clone <this-repo> /var/www/institute
cd /var/www/institute
sudo -u institute cp .env.example .env
sudo -u institute php8.4 artisan key:generate
sudo -u institute nano .env
```

```env
APP_NAME="Big Binary Tech Institute"
APP_ENV=production          # REQUIRED: 'local' exposes the passwordless demo logins
APP_DEBUG=false             # REQUIRED: an error page otherwise prints the DB password
APP_KEY=base64:…            # generated above, ONCE
APP_URL=https://pos.bbt.edu.pk

TRUSTED_PROXIES=127.0.0.1   # nginx is on this box. NOT '*' — see DEPLOYMENT.md

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=institute_pos
DB_USERNAME=institute_pos
DB_PASSWORD=…               # printed by provision.sh

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true  # REQUIRED once HTTPS is on
SESSION_ENCRYPT=true
CACHE_STORE=database
QUEUE_CONNECTION=database

BACKUP_PATH=/var/backups/institute
BACKUP_KEEP=14

INSTITUTE_TODAY=            # REQUIRED BLANK, or every "overdue" date freezes
INSTITUTE_SELF_SERVICE_RESET=false
SUPERADMIN_PASSWORD=        # REQUIRED BLANK: the seeder mints one and prints it
```

> **Never rotate `APP_KEY` after go-live.** It decrypts every stored two-factor
> secret; rotating it locks out every enrolled account at once.

---

## 5. First deploy

```bash
cd /var/www/institute
sudo -u institute php8.4 artisan migrate --force
sudo -u institute php8.4 artisan db:seed --class=RolePermissionSeeder --force
sudo -u institute php8.4 artisan db:seed --class=SuperAdminSeeder --force
sudo systemctl enable --now institute-queue
```

**Never run bare `db:seed` in production** — it is guarded to local and testing,
but the habit is what matters. `SuperAdminSeeder` prints the owner password
once. Record it, sign in at `/superadmin`, and complete 2FA enrolment
immediately.

Then, for every release after this one:

```bash
sudo -u institute bash deploy/deploy.sh
```

That script is also what the Azure pipeline runs, so the automated path and the
manual path are the same path. Its order is fixed and there is no flag to skip
any of it:

```text
backup → maintenance on → code → migrate → caches → maintenance off → health check
```

The backup happens **before** the migration, every time. If the backup fails,
the deploy does not start. If the health check fails, the code is rolled back
and maintenance mode is left **on** — a half-migrated database behind a working
login screen is worse than a maintenance page, because the counter will start
taking money against it.

---

## 6. HTTPS

Only after DNS points at the instance:

```bash
sudo certbot --nginx -d pos.bbt.edu.pk
```

Renewal installs its own systemd timer. Verify it once:

```bash
sudo certbot renew --dry-run
```

---

## 7. Backups — and the part everyone skips

Two commands, both on the scheduler:

| When | Command | What it proves |
| --- | --- | --- |
| Daily 02:00 | `backup:run` | A dump exists |
| Sunday 03:00 | `backup:verify` | The dump can actually be **restored** |

`backup:run` writes a gzipped, restore-ready dump to `/var/backups/institute`,
keeps 14, and records the event in the institute's own audit log. It **fails
loudly** — a dump below 1KB is treated as a failure and deleted, because a cron
job that goes green while writing nothing is the exact disaster the command
exists to prevent.

Beside each dump it writes a `.json` manifest of row counts taken *before* the
dump. That manifest is what makes the weekly drill meaningful: comparing a
restored dump against the *live* database can only ever fail, because the roll
moves and the audit log only ever appends. Measured against the manifest, the
question becomes the one worth asking — did everything that went into this file
come back out of it?

`backup:verify` restores the newest dump into a scratch database, compares every
table against the manifest, drops the scratch database, and exits non-zero on
any mismatch. **This is what closes B-05.** The blocker was never "there is no
backup" — the super admin could always download one. It was that no backup had
ever been restored, which means owning a file with no evidence you can use it.

Run it by hand any time:

```bash
sudo -u institute php8.4 artisan backup:verify
```

### Get a copy off the box

A dump on the same disk as the database dies with the disk, and Oracle has
already shown it will change your instance without telling you. Add one line:

```bash
sudo -u institute crontab -e
30 3 * * * rclone copy /var/backups/institute remote:institute-backups --max-age 48h
```

Free destinations that hold a fortnight of these comfortably: Cloudflare R2
(10GB), Backblaze B2 (10GB), or any Google Drive via `rclone`.

---

## 8. Seeing what is happening

Everything below is on the box already; none of it needs another service.

| Question | Where |
| --- | --- |
| Is the site up? | `curl -sf http://127.0.0.1/up` |
| Did the scheduler run? | `journalctl -u institute-scheduler --since today` |
| Is the queue alive? | `systemctl status institute-queue` |
| Did last night's backup work? | `ls -la /var/backups/institute` |
| Did the restore drill pass? | `journalctl -u institute-scheduler -g backup:verify` |
| What did the application log? | `tail -f storage/logs/laravel.log` |
| Slow pages? | `tail -f /var/log/php-fpm-institute-slow.log` |
| Who did what, in the app? | Super admin → Activity log |
| What was deployed, when? | Super admin → Activity log ("Deployment completed") |

### Alerting — the one thing that is not on the box

Point a free external monitor at `https://pos.bbt.edu.pk/up` — **UptimeRobot**
or **Better Stack**, both free for this. Five-minute interval, email or WhatsApp
alert.

This matters more than it sounds. Everything above tells you what happened
*after you go and look*. An external monitor is the only thing that tells you
the site is down **before the counter does**, and it is the only check that
survives the box itself going away.

---

## 9. When something is wrong

```bash
# The usual suspects, in order
sudo systemctl status php8.4-fpm nginx mysql institute-queue
sudo tail -50 /var/log/nginx/institute-error.log
sudo tail -50 /var/www/institute/storage/logs/laravel.log
df -h                     # a full disk looks like a hundred unrelated bugs
free -m                   # if swap is being used hard, MySQL is about to suffer
```

**"I changed .env and nothing happened."** Almost always `config:cache`.
Configuration is read from the cache, not from `.env`, and the cache is rebuilt
during deploy. Run `php8.4 artisan config:cache`.

**Restoring a backup:**

```bash
gunzip -c /var/backups/institute/bbt-backup-<stamp>.sql.gz \
  | mysql -u institute_pos -p institute_pos
```

You do not need to look up that command under pressure — the weekly drill has
already run it.

---

## 10. Known limits of this platform

- **Oracle halved the Always Free allowance on 15 June 2026** (4 OCPU/24GB →
  2/12) with no announcement, no email, and no blog post. Users found out when
  instances were resized. Assume it can happen again: keep backups off the box,
  and treat the ~$5/month VPS as a one-command migration away rather than a
  rewrite. Everything here is standard Ubuntu and moves unchanged.
- **Ampere capacity is scarce.** Creating the instance may take several
  attempts.
- **Idle reclamation.** Oracle reclaims idle Always Free compute. A POS in daily
  use is not idle, but do not build a second instance you never log into and
  expect it to survive.
- **One box means one failure domain.** There is no standby. The recovery plan
  is the backup, which is why the drill runs weekly rather than never.
