# Production emergency card

**One page. Print it. Keep a copy off this machine.**

This is written for whoever is holding the phone when the counter cannot take
money and the engineer is unreachable. It is a one-engineer system at a business
that handles cash, owned by a non-technical client, and that combination is the
reason this page exists at all.

> **It contains no secrets and never should.** Every row below says *where* a
> credential lives, never what it is. If you are about to paste a password into
> this file, stop.

---

## 1. What this is

| | |
| --- | --- |
| System | Institute POS — enrolments, fee challans, part-payments, receipts |
| Production URL | `https://pos.bbt.edu.pk` |
| Users | ~9 staff accounts, ~450 students |
| Host | AWS **Lightsail**, region **Asia Pacific (Mumbai)** `ap-south-1` |
| Instance name | `institute-prod` _(fill in the console name if it differs)_ |
| Static IPv4 | `_______________` _(fill in after creation)_ |
| Instance spec | Ubuntu 24.04 LTS, x86, 4 GB / 2 vCPU / 80 GB, $24/month |
| App directory | `/var/www/institute` |
| Service account | `institute` |
| Database | MySQL 8.0 on the same box, loopback only, `institute_pos` |
| CI/CD | Azure DevOps — org `bigbinarytech`, project `bigbinarytech-POS` |
| Pipeline | `azure-pipelines.yml`, Environment `production`, VM resource `institute-prod` |
| Source | Azure DevOps (authoritative) and GitHub `GhazanfarSheikh/POS` (mirror) |

---

## 2. Where the credentials live

Fill the right-hand column in **once**, by hand, and never in this repository.

| Credential | Why it matters | Where it is |
| --- | --- | --- |
| **`APP_KEY`** | **Read §6 first.** Decrypts every stored 2FA secret. A database restored without it locks out every account. | `_______________` |
| Database password | Cannot restore into or read the database | Password manager, and `/root/.institute-db-password` on the box |
| Super-admin recovery credential | No way into `/superadmin` to fix anything | `_______________` |
| SSH private key (`ubuntu@`) | No way onto the box at all | `_______________` |
| rclone remote config | No access to the off-box backups | Password manager, and `~institute/.config/rclone/rclone.conf` (mode 0600) |
| AWS / Lightsail login | Cannot rebuild the instance or move the static IP | `_______________` |
| Domain registrar login | Cannot repoint DNS at a replacement | `_______________` |
| Azure DevOps access | Cannot deploy | `_______________` |

**Who can reach them:** `_______________` and `_______________`.
At least two people. One is not a plan.

**Off-box backups:** Cloudflare R2, bucket `institute-backups`, 30-day lifecycle
rule. Copies are pushed hourly after each successful dump.

**Escalation:** `_______________` (engineer) → `_______________` (backup contact).

---

## 3. Is it actually down?

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://pos.bbt.edu.pk/ready
```

| Code | Means | Do |
| --- | --- | --- |
| **200** | Healthy. App, database and storage are all fine. | Nothing. The problem is elsewhere — a browser, a network, one user. |
| **503** | App is up but not ready — **usually maintenance mode left on by a failed deploy**. | §5 |
| **000 / timeout** | Never reached the app: DNS, TLS or firewall. | §4 |
| **502 / 504** | nginx is up, PHP-FPM is not answering. | §4 |

`/up` answers 200 even during maintenance — it is a liveness probe, not a
readiness one. Do not use it to decide whether the counter can work.

---

## 4. On the box

```bash
ssh ubuntu@<static-ip>

sudo systemctl status nginx
sudo systemctl status php8.4-fpm
sudo systemctl status mysql
sudo systemctl status institute-queue          # queue worker
sudo systemctl status institute-scheduler.timer # backups run from here

df -h                    # a full disk looks like a hundred unrelated bugs
free -m                  # heavy swap use means MySQL is about to suffer
timedatectl              # clock drift locks EVERY 2FA account out at once

sudo tail -50 /var/log/nginx/institute-error.log
sudo tail -50 /var/www/institute/storage/logs/laravel.log
sudo tail -50 /var/log/php-fpm-institute-slow.log

ls -la /var/backups/institute | tail -5    # newest dump should be < 1 hour old
```

Restarting, least drastic first:

```bash
sudo systemctl reload php8.4-fpm
sudo systemctl restart php8.4-fpm nginx
sudo systemctl restart mysql          # last resort; nothing is lost, it is ACID
```

---

## 5. A deployment failed

**The site being in maintenance mode after a failed deploy is deliberate.** It is
not a bug to be cleared. A half-migrated financial database behind a working
login screen is worse than a maintenance page, because the counter will start
taking money against it.

Do **not** run `php artisan up` to "see if it works". Ask, in order:

1. **Did it get past the backup?** Read the tail of the Azure DevOps deploy job.
   Everything that can refuse refuses *before* the backup — a missing release
   SHA, a missing staged build, unsafe `.env`, an unsynchronised clock. If it
   refused there, **nothing was changed**; fix the cause and re-run the pipeline.

2. **Did migrations run?** If the job reached "Running migrations" and failed
   after, the database may be part-migrated. **It has NOT been rolled back and
   will not be** — that is a person's decision, not the script's.

   ```bash
   cd /var/www/institute && sudo -u institute php8.4 artisan migrate:status
   ```

3. **If the database must be restored**, go to §6. The pre-deploy dump is the
   newest file in `/var/backups/institute`.

4. Bring it back only when you are satisfied:

   ```bash
   cd /var/www/institute && sudo -u institute php8.4 artisan up
   ```

**"Another deploy is already running"** — `flock` released on process exit, so a
held lock means a live process. `ps -ef | grep deploy.sh`.

---

## 6. Restoring — and why the dump alone is not enough

> **A restored database is not a recovered system.**
>
> Every 2FA secret in `users` and `super_admins` is encrypted with `APP_KEY`.
> Restore the database beside a different `APP_KEY` and every enrolled account —
> including the only `/superadmin` — is locked out, permanently, with no way
> back in through the application. Row counts will match perfectly. Nobody will
> be able to log in.
>
> **Never rotate `APP_KEY` after go-live.**

A recovery is complete only when **all four** hold:

```
database restored  +  the SAME APP_KEY  +  Laravel boots  +  an existing
/superadmin account completes TOTP authentication
```

### Restore order

```bash
# 1. Stop writers first, so nothing lands mid-restore.
cd /var/www/institute
sudo -u institute php8.4 artisan down
sudo systemctl stop institute-queue institute-scheduler.timer

# 2. Choose the dump. Newest on-box:
ls -lt /var/backups/institute/*.sql.gz | head
#    Or fetch from off-box if the disk is gone:
#      rclone copy r2:institute-backups ./restore --max-age 24h

# 3. Restore.
gunzip -c /var/backups/institute/bbt-backup-<stamp>.sql.gz \
  | mysql -u institute_pos -p institute_pos

# 4. Confirm APP_KEY matches the one the dump was taken under.
grep '^APP_KEY=' /var/www/institute/.env
#    Compare against the value in the password manager. They MUST be identical.

# 5. Boot and prove it.
sudo -u institute php8.4 artisan config:cache
sudo systemctl start institute-queue institute-scheduler.timer
sudo -u institute php8.4 artisan up
curl -s -o /dev/null -w '%{http_code}\n' https://pos.bbt.edu.pk/ready   # want 200

# 6. THE STEP THAT ACTUALLY PROVES IT: open /superadmin in a browser and
#    complete a TOTP login with an existing account. Until that succeeds you
#    have a database, not a recovery.
```

The weekly drill (`backup:verify`, Sunday 03:00) has already rehearsed steps 2
and 3 against a scratch database. It does **not** rehearse step 6 — that is why
it is written out here.

---

## 7. The box is gone entirely

1. Lightsail console → create a new instance, **same spec**: Mumbai, Ubuntu
   24.04, 4 GB / 2 vCPU / 80 GB, IPv4-capable.
2. **Move the static IP to it** (Networking → Static IP → attach). DNS does not
   need to change and does not need to propagate.
3. Configure the firewall: 22 from the admin IP only, 80 and 443 open. **IPv4
   and IPv6 are separate rule sets** — do both, or disable IPv6.
4. `git clone` the repository and run `sudo bash deploy/provision.sh`.
5. Write `.env` from `.env.production.example`, with the **original `APP_KEY`**
   from the password manager.
6. Restore the newest off-box dump (§6).
7. `certbot --nginx -d pos.bbt.edu.pk`.
8. Re-register the box as the Azure DevOps `production` Environment VM resource.
9. Prove it with a `/superadmin` TOTP login before telling the counter it is up.

Full detail in [DEPLOYMENT.md](DEPLOYMENT.md).

---

## 8. What NOT to do

- **Do not rotate `APP_KEY`.** It locks out every 2FA account at once. (§6)
- **Do not run `php artisan db:seed`.** `DemoDataSeeder` invents students, fake
  revenue and three logins whose passwords are in the repository. Only
  `RolePermissionSeeder` and `SuperAdminSeeder`, by name.
- **Do not run the test suite on the production box.** It uses `RefreshDatabase`
  and would drop every table in `institute_pos`. Production is smoke-tested
  through a browser, never through PHPUnit.
- **Do not clear maintenance mode to "see if it works"** after a failed deploy.
  Read §5 first.
- **Do not edit or delete a row in `payments`.** It is append-only. A mistaken
  collection is corrected with `payments:reverse`, which records an offsetting
  entry and leaves both facts on the record.
- **Do not change the server's timezone to Asia/Karachi.** Storage is UTC by
  design; changing it re-interprets every payment's date.
