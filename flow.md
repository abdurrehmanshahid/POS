# Flow — how a change reaches the counter

What actually happens, in order, from a commit to a student holding a receipt.
Written so that somebody who has never seen this repository can follow the path
and know where it stops if something is wrong.

Companion document: [decisions.md](decisions.md), which records *why* each of
these steps is shaped the way it is.

---

## 0. The shape of the system

```
  developer
     │  git push
     └──────────────► GitHub Actions — the authoritative pipeline
                              │
                              ▼
                      ┌───────────────┐
                      │ VERIFY        │  4 legs; PHP 8.4 × MySQL 8.0 gates
                      │   └─ job "CI" │  ← the required branch-policy check
                      └───────┬───────┘
                              ▼
                      ┌───────────────┐
                      │ BUILD ASSETS  │  Node 24 · npm ci · npm run build
                      │               │  → Pipeline Artifact "build-assets"
                      └───────┬───────┘
                              ▼
                      ┌───────────────┐
                      │  APPROVAL     │  a human, in the GitHub Environment
                      └───────┬───────┘
                              ▼
                      ┌───────────────┐
                      │ DEPLOY        │  runs ON the Lightsail box
                      │               │  (Environment VM resource)
                      └───────┬───────┘
                              ▼
                      ┌───────────────┐
                      │ EXTERNAL SMOKE│  hosted agent → public https /ready
                      └───────────────┘
```

One box in Mumbai runs everything: nginx → PHP-FPM → MySQL on loopback, with
systemd timers for the scheduler and the queue. No Docker, no Redis, no managed
database, no second server.

---

## 1. Verify

Four matrix legs, all on `ubuntu-24.04`, PHP installed from the **same ondrej
PPA that `provision.sh` uses** so CI and production agree on the runtime.

| Leg | Role |
| --- | --- |
| PHP 8.4 × **MySQL 8.0** | **The gate.** Ubuntu 24.04's `mysql-server` is the 8.0.x line, so this is production. |
| PHP 8.4 × MySQL 8.4 | Forward compatibility. Has already caught one enum bug SQLite could never see. |
| PHP 8.4 × SQLite | Fast leg. Four services hand-write driver-specific SQL; this is the other branch. |
| PHP 8.5 × SQLite | Catches deprecations early. Production stays on 8.4 regardless. |

Each leg runs, in order:

1. `composer validate --strict` — a manifest edited without re-locking
2. `composer install`
3. `vendor/bin/pint --test` — style, as its own step so it is never confused with behaviour
4. `php artisan test` — the full suite, **with `RefreshDatabase`**
5. `migrate` → `migrate:reset` → `migrate` — proves every `down()` actually reverses

Step 5 is not ceremony. It is the step that catches a `down()` which half-
reverses, and the one that would have caught the `attendances_student_id_index`
bug that took 276 tests down with it.

The job named exactly **`CI`** then gates the whole stage. That name is
configured in GitHub branch protection, outside this repository — renaming the
job silently stops protecting `main`.

**Where it stops:** any leg red ⇒ `CI` red ⇒ nothing builds, nothing deploys.

---

## 2. Build assets

Node 24 (Node 20 reached end of life on 30 April 2026; Vite 8 needs
`^20.19.0 || >=22.12.0`). `npm ci && npm run build` produces:

```
public/build/manifest.json
public/build/assets/app-<hash>.css
public/build/assets/app-<hash>.js
```

The step then **asserts `manifest.json` exists** before publishing, and the
directory's *contents* are published as the Pipeline Artifact `build-assets`.

Nothing generated enters git. `public/build` is git-ignored and stays that way.

**Where it stops:** no manifest ⇒ the stage fails ⇒ no artifact ⇒ the deploy
would refuse anyway, one step later and less clearly.

---

## 3. Approval

A human approves in **GitHub → Settings → Environments → production**.

Three controls live there, in the UI, and **cannot be expressed in YAML** — a
pipeline file cannot meaningfully gate itself, because anything it said about
who may approve it is something a pull request could change:

- **Manual approval** — a person presses go
- **Branch control** — only `refs/heads/main` may consume the environment
- **Exclusive lock** — one pipeline at a time

**Where it stops:** nobody approves ⇒ it waits, indefinitely, harmlessly.

---

## 4. Deploy — on the box itself

The deployment job runs on a **self-hosted runner** installed on the production
box, not over SSH from a hosted runner. The runner dials *out* to GitHub, so
port 22 stays restricted to the administrator's IP and there is no inbound
deployment path at all.

### 4a. Stage the assets

The artifact is downloaded onto the box and copied to
`/var/www/institute-builds/<RELEASE_SHA>/`, replaced rather than merged.

Not `/tmp` — `systemd-tmpfiles` cleans that, and a build that evaporates between
the push and the deploy is exactly the failure you do not want to be quiet.

### 4b. `deploy.sh`

```
RELEASE_SHA=<Build.SourceVersion> BUILD_ASSETS=no bash deploy/deploy.sh
```

The order is fixed and there is no flag to skip any of it:

```
LOCK  →  PREFLIGHT  →  backup  →  maintenance ON  →  code  →  assets
      →  migrate  →  caches  →  maintenance OFF  →  health  →  prune  →  audit
```

**Lock.** A non-blocking `flock` on `/run/institute-deploy.lock`, held for the
life of the process. The GitHub concurrency group is the first layer; this is the
one that also covers a human running a release by hand.

**Preflight — nothing here may touch the site.** Every knowable precondition,
checked while the counter is still open:

| Check | Refuses when |
| --- | --- |
| `RELEASE_SHA` present | absent — there is deliberately no fallback to `origin/main` |
| `git cat-file -e <sha>` | the commit does not exist after fetching |
| Staged build | `…/<sha>/manifest.json` is missing |
| `.env` assertions ×11 | any one is unsafe (see below) |
| Clock | `timedatectl` says not synchronised — TOTP depends on it |
| Disk | under 2 GB free |
| PHP | not 8.4 (sudoers names `php8.4-fpm` literally) |
| `deploy:preflight` | effective config unsafe, MySQL unreachable, storage unwritable |

The eleven `.env` assertions: `APP_ENV=production`, `APP_DEBUG=false`,
`APP_KEY` non-empty, `APP_URL` https, `DB_CONNECTION=mysql`,
`DB_HOST=127.0.0.1`, `DB_TIMEZONE=+00:00`, `SESSION_DRIVER=database`,
`SESSION_SECURE_COOKIE=true`, `CACHE_STORE=database`,
`QUEUE_CONNECTION=database`, `BACKUP_KEEP=168`, `BACKUP_PATH` set and writable,
`TRUSTED_PROXIES` not `*`, `INSTITUTE_TODAY` blank.

**Backup.** `php artisan backup:run`, before anything is touched, every time, no
skip flag. It takes the same `flock` as the hourly scheduled dump.

**Maintenance on**, with a bypass secret so the deployer can smoke-test first.

**Code.** `git reset --hard "$RELEASE_SHA"` — the exact revision CI tested.

**Assets.** The staged build replaces `public/build`, *after* maintenance mode
and *after* the reset. Never over a live site running old code.

**Migrate**, then rebuild the config/route/view caches, then re-assert the
effective config against the compiled cache while still in maintenance.

**Health.** `http://127.0.0.1/up` locally, then the **public HTTPS `/ready`**.
The localhost check cannot prove DNS, the firewall, TLS, or that MySQL answers.

**Prune** the staging directory to the three most recent, never the live one.

**Audit** — a deploy is a change to the thing that holds the money, so it lands
in the same activity log as every other such change.

### On failure

| When | What happens |
| --- | --- |
| Preflight | Nothing touched. No backup, no maintenance mode. |
| After maintenance is on | Code rolled back; **maintenance mode LEFT ON, deliberately** |
| After migrations began | **The database is never automatically rolled back.** A person looks at it. |
| Public `/ready` fails but `/up` passes | Not rolled back — the fault is DNS/TLS/firewall/database, which no previous commit fixes |

---

## 5. External smoke

A Microsoft-hosted agent fetches `https://pos.bigbinaryerp.com/ready` over the public
internet. `deploy.sh` already checked it from the box, which is necessary and
not sufficient: a check originating on the server shares its DNS resolver and
bypasses the Lightsail firewall entirely.

---

## 6. Running continuously

| When | What | Where |
| --- | --- | --- |
| Every minute | `schedule:run` | `institute-scheduler.timer` |
| **Hourly** | `backup:run` → dump, prune to 168, `rclone copy` off-box | scheduler |
| Sunday 03:00 (Karachi) | `backup:verify` → restore into a scratch DB, compare against the manifest | scheduler |
| Always | `queue:work --tries=3 --max-time=3600` | `institute-queue` |
| Every 5 min | external monitor GETs `/ready`, alerts on **two** consecutive failures | UptimeRobot / Better Stack |

Worst-case data loss is one hour. Seven days of dumps on the box, thirty days
off it under a bucket lifecycle rule.

`/ready` returns 503 for the duration of every deploy — that is correct, and is
how a failed deploy that left maintenance mode on gets noticed. It is also
exactly why the monitor must alert on two consecutive failures and not one.

---

## 7. Money, end to end

```
enrol → challan issued → payment(s) collected → receipt(s) printed
                              │
                              └─ mistake? → payments:reverse / supervisor UI
                                             (append-only offsetting entry)
```

`payments` is append-only and strictly positive. A correction is a row in
`payment_reversals`, and every money figure in the application reports

```
gross payments − reversals = net receipts
```

through one SQL fragment (`App\Support\NetReceipts`). Fifteen separate figures
depend on it, and `PaymentReversalTest` asserts every one of them moves by
exactly the reversed amount.

---

## 8. Where this flow has never run

Honest status, as of this work:

- **Nothing here has run on real hardware.** `provision.sh` and `deploy.sh` have
  been syntax-checked, and `deploy.sh`'s refusal paths exercised in a sandbox,
  but neither has provisioned an actual Lightsail instance.
- The **rehearsal is mandatory and must pass twice** on a disposable instance at
  the exact production spec, the second time with no manual repairs. See
  [DEPLOYMENT.md](docs/DEPLOYMENT.md).
- The GitHub Environment, self-hosted runner, required reviewers and branch
  restriction are **documented, not configured** — they need browser access.
- No AWS resource, DNS record, TLS certificate, monitor or backup bucket has
  been created.
