# Decisions

Every judgement call made while taking this system from "not deployed anywhere"
to "ready for a first production release", and the reasoning behind it. Written
so a later engineer can disagree on purpose rather than by accident.

Companion document: [flow.md](flow.md), which describes *what* happens.

Format: **the decision**, then what it rules out, then why.

---

## Infrastructure

### D1 · AWS Lightsail, Mumbai, $24 bundle, x86 — not Oracle Ampere
The previous plan targeted Oracle Cloud Always Free on Ampere A1 (arm64).

Oracle halved its free allowance in June 2026 with no announcement, Ampere
capacity is scarce enough that creating an instance takes several attempts, and
the home region is permanent. Lightsail costs $24/month, has predictable
capacity, and a snapshot rebuilds anywhere — the region is a latency decision,
not an irreversible one.

Mumbai rather than anywhere further: every Livewire interaction is a full server
round trip, and at ~250 ms each the counter feels broken while nothing is wrong.

**Consequence:** every `arm64` assumption had to go. `provision.sh` now prints
its architecture and warns on anything but `amd64`, so a future hardcoded
download cannot quietly reintroduce the assumption.

### D2 · No re-platforming
No Docker, no Kubernetes, no Redis, no managed database, no PostgreSQL, no
second app server, no releases-directory/symlink deploy.

The releases-directory design is the right long-term answer and is deliberately
deferred: with symlink deploys, opcache keys compiled files by path, so unless
nginx builds `SCRIPT_FILENAME` from `$realpath_root`, the site keeps serving the
previous release after the swap — the deploy reports success, the health check
passes, and old code runs. Not a thing to meet on the same night as the first
production box.

### D3 · MySQL 8.0 is the production target, and CI gates on it
Ubuntu 24.04's `mysql-server` metapackage resolves to the **8.0.x** line. CI was
gating on `mysql:8.4`.

Gate on the version you actually run. The 8.4 leg stays as forward-compatibility
cover — it has already earned its place once, catching an `admissions.status`
enum value that would have failed all 478 roll-import rows.

**Explicitly rejected:** upgrading production to 8.4 so CI matches. Ubuntu's
supported package is the lower-risk option, and "change production so CI is
tidier" is the wrong direction of travel.

### D4 · PHP-FPM `ondemand` with 6 children, memory limit unchanged at 256M
Was `static` with 12 children — sized for a 12 GB Ampere box. On 4 GB that is
3 GB of PHP resident permanently whether anybody is at the counter or not.

`ondemand` costs a fork on the first request after a quiet spell (microseconds
against a Livewire round trip) and makes idle free. Nine staff cannot generate
more than six concurrent PHP requests in practice; beyond that, requests queue
in the socket backlog rather than the box going to swap.

**The memory limit stays at 256M.** Fewer workers is a concurrency decision;
per-request headroom is a correctness one, and they are unrelated. One dompdf
voucher render peaks near 56 MB.

### D5 · MySQL pinned to loopback, and left otherwise untuned
`bind-address = 127.0.0.1` is asserted rather than assumed, and `provision.sh`
refuses if MySQL is listening anywhere else. `innodb_buffer_pool_size` is left
at its default: 25 tables, 450 students. Measure first.

### D6 · The iptables block is kept, conditional, and cannot lock anyone out
Oracle's image shipped drop-all INPUT rules; Lightsail's does not, so the real
firewall is the console.

The old code inserted at index 6, which **errors on an empty ruleset** — and
with `set -e` that aborted provisioning outright on the exact platform being
targeted. It now inserts above a terminal rule if one exists and appends
otherwise, only ever adds ACCEPT rules, and never touches a policy.

It also no longer installs `iptables-persistent` on the fly: doing so
non-interactively freezes the *current* ruleset as the boot ruleset, which is a
way to persist a lockout somebody was midway through creating.

### D7 · Clock synchronisation is a hard failure, not a warning
`provision.sh` refuses to finish, and `deploy.sh` refuses to deploy, if
`timedatectl` does not report `NTPSynchronized=yes`.

TOTP codes are computed from the clock. Drift locks out every enrolled account
simultaneously — including the only `/superadmin`, which is the account you would
need to fix anything. There is no recovery path from inside the application.

**The server clock stays UTC.** Storage is UTC by design; the display layer
localises to Lahore. Setting the box to Asia/Karachi would re-interpret money
history.

### D8 · Node 24 stays on the box, as a fallback only
Node 20 reached end of life on 30 April 2026 and Vite 8 requires
`^20.19.0 || >=22.12.0`.

Production runs `BUILD_ASSETS=no` and never compiles assets. Node is kept solely
for the emergency path where a human must release without a pipeline — because
discovering npm is absent during *that* particular evening is not the moment for
it. Removing it entirely is week-two work, once the immutable artifact removes
the fallback's reason to exist.

---

## The pipeline

### D9 · Azure DevOps Environment **VM resource**, not SSH from a hosted agent
An SSH-from-hosted-agent design needs port 22 reachable from Microsoft's address
ranges, which in practice means 22 open to the world on a box that bills fees.

A VM agent dials *out* and polls for work, so 22 stays restricted to the
administrator's own IP and there is no inbound deployment path at all.

The agent runs as the existing `institute` service account, which already holds
exactly three sudo grants. Registering it as root to simplify the pipeline would
hand every future YAML edit unrestricted control of the box.

### D10 · Approval, branch control and exclusive lock live in the UI, not in YAML
They cannot be expressed in the pipeline file, and that is the point: a pipeline
cannot meaningfully gate itself, because anything it said about who may approve
it is something a pull request could change.

Documented click-by-click in `docs/DEPLOYMENT.md` §8 instead of faked in YAML.

### D11 · The server-side `flock` is the authority, not the Azure lock
Azure's exclusive lock is a control in somebody else's system and does not cover
the human who SSHes in and runs a release by hand while a pipeline is mid-deploy.
Two layers; the server is final.

`flock` on a held file descriptor: the kernel releases it on exit, including
`kill -9`, so there is no stale lock to clear and no timeout to tune.

### D12 · `docker run` for the CI MySQL legs, not Azure service containers
Service containers are resolved at compile time and cannot read a matrix value.
That would start MySQL on the SQLite legs too and — fatally — could not vary the
image between the 8.0 and 8.4 legs, which is the entire reason both exist.

The GitHub mirror uses a service container with a default, because Actions
genuinely cannot condition on a matrix value there. **The two files agree on
what is tested, not on the mechanism.**

### D13 · `.github/workflows/ci.yml` is kept, relabelled non-production
The repository pushes to both remotes on the same `git push`, so a second run of
the matrix is free. It deploys nothing and must never be given the ability to.

**Explicitly rejected:** deleting it. A second independent verdict on a commit
costs nothing and the file already exists.

### D14 · The job named `CI` keeps its name
It is the required status check in branch policy, configured outside this
repository. Renaming it silently stops protecting `main`. It now depends on the
whole `tests` matrix, so a failure on *any* leg — required or
forward-compatibility — fails the gate. An optional leg is optional in what it
proves, not in whether it may be broken.

---

## `deploy.sh`

### D15 · `RELEASE_SHA` is mandatory with no fallback
`git reset --hard origin/main` is a race with a money system on the end of it:
commit A passes CI and is approved, someone pushes B while the approval waits,
the deploy stage runs, and you have shipped B — untested, unapproved, and
indistinguishable in the pipeline log from the release that was authorised.

### D16 · Everything that can refuse, refuses before the backup
The SHA, the staged build, the eleven `.env` assertions, the clock, the disk,
the PHP version, MySQL reachability. A deploy that was always going to fail
should fail while the counter is still open — not halfway through, with the site
in maintenance mode and a database that may or may not be part-migrated.

### D17 · `.env` is read with a helper that distinguishes absent from empty
Several keys have a config default that is **unsafe in production**:
`TRUSTED_PROXIES` defaults to `*` and `DB_CONNECTION` to `sqlite`. A missing line
is therefore not "unset", it is "the dangerous value, silently". A plain grep for
the wrong value would happily pass a file that never mentions the key.

### D18 · Two layers of assertion, `.env` and effective config
The shell layer refuses fastest and has no side effects. `deploy:preflight` then
asks Laravel, which is the only thing that can answer "what do you actually
resolve" and "can you reach MySQL". It runs twice: in preflight after
`config:clear`, and again after `config:cache` against the compiled cache the
application will genuinely serve from — while still in maintenance mode, so a
failure there is still cheap.

### D19 · Assets are activated inside maintenance mode, after the git reset
Copying a new build over a live site running old code gives a window where the
manifest names files the running Blade templates do not reference. Content-hashed
filenames only *usually* save you, and "usually" is not a property you want on
the fee voucher.

Replaced rather than merged: a stale chunk from an earlier release is a file the
manifest no longer names and nothing ever cleans up.

### D20 · A failed public `/ready` does not roll back
If `/up` passes locally but the public HTTPS `/ready` does not, the application
is serving. What is broken is DNS, TLS, the firewall or the database — none of
which a previous commit fixes, and all of which need a person. The script says
so, lists the four commands to tell them apart, and exits non-zero without
touching the code.

### D21 · The database is never automatically rolled back
Code rollback and database rollback are different decisions, and the script only
ever makes the first. A part-migrated financial database is a thing a person
looks at.

### D22 · `exec 9>file 2>/dev/null` was a real bug, found in testing
`exec` with redirections and no command applies them to **the current shell**, so
that `2>/dev/null` silenced every subsequent `die` for the rest of the script.
The deploy exited 1 having printed nothing at all — a refusal with no reason,
which is worse than a crash. The probe is now scoped to a brace group.

### D23 · A missing `flock` binary is reported as such
`flock` exiting non-zero cannot distinguish "lock held" from "not installed", and
reporting a missing binary as a concurrent deploy sends the operator hunting for
a process that was never there. Checked explicitly, with the apt package named.

---

## Backups

### D24 · Hourly, not nightly
Worst-case data loss goes from ~24 hours of counter transactions to ~1. It buys
that with the mechanism already proved every Sunday: same command, same artefact,
same drill, twenty-four times as often. No binlog PITR, no second restore
methodology to learn under pressure.

### D25 · `BACKUP_KEEP=168`, and the deploy refuses anything lower
`BACKUP_KEEP` is a **count, not an age**. Harmless at one dump a night; at one an
hour, `14` means fourteen *hours* — and nothing would say so. The command
succeeds, the weekly drill passes, the directory looks full, and yesterday is
gone. This is the single most dangerous line in the change, so it is asserted in
`deploy.sh`, in `deploy:preflight`, and in a test.

The config default stays 14, because the file is also read on a laptop where 168
dumps of a demo database would be litter. Production is explicit in `.env`.

### D26 · The backup lock lives in the command, not in `deploy.sh`
Three things start `backup:run`: the hourly scheduler, the pre-deploy dump, and
a human at a prompt. A lock that only one of three paths takes is not a lock.

`withoutOverlapping` is kept as well, but it only covers the scheduler and it is
a cache entry rather than a kernel lock. Its expiry dropped from 120 to 55
minutes — it must be shorter than the interval, or a killed run holds the lock
past the next tick and silently skips it.

### D27 · The lock is released explicitly, not left to process exit
Found by the test suite: `flock()` attaches to the open file description, so a
second `fopen` of the same path **inside one process** blocks on the first. Every
artisan invocation is its own process in production, so relying on exit looked
fine — and then seven of eight tests no-opped with a cheerful "another backup is
already running". Anything else calling this twice in a process would have hit
the same wall, silently.

### D28 · A blocked backup exits SUCCESS, not FAILURE
A dump was taken, by the other process, moments ago. Failing would page an
operator about a system working correctly, and an alert that cries wolf is an
alert that gets muted.

### D29 · Off-box copy is configurable, runs only on success, and never deletes
`BACKUP_RCLONE_REMOTE` (Cloudflare R2 — 10 GB free, no egress, lifecycle rules
are a bucket setting). Copied with `--max-age 2h` after a **successful** dump
only: a truncated archive pushed off-box would overwrite the last good copy with
a broken one.

Never `rclone move`, and no local delete on success. The two retentions are
independent by design: seven days on the box for a fast restore, thirty off it
for the disk-died case, enforced by a bucket lifecycle rule rather than by
rclone.

A blank remote **warns loudly every hour** rather than failing. Refusing the
deploy over it would block the very first release, which is exactly when the
bucket does not exist yet.

### D30 · A failed off-box copy is a warning, not a command failure
The dump exists and is good; what is missing is the second copy. Returning
FAILURE would make the deploy that called it refuse, and refusing to release
because an object store had a bad minute is worse than a logged warning.

### D31 · Recovery is proved by a login, not by row counts
Every 2FA secret is encrypted with `APP_KEY`. A database restored beside a
different key locks out every enrolled account while passing the weekly drill
perfectly. The drill rehearses the restore; `docs/PRODUCTION-EMERGENCY.md` §6
carries the step it cannot — a real `/superadmin` TOTP login.

---

## `/ready`

### D32 · A bare 200 or a bare 503, with an empty body
Publicly reachable, unauthenticated, forever. A readiness endpoint that
helpfully reports `SQLSTATE[HY000] Connection refused for user institute_pos at
127.0.0.1` has told a stranger the database user, the host, and that the box is
currently degraded. Diagnostic JSON is a reconnaissance gift on an endpoint that
exists to be polled. Reasons go to the log, where the operator already is.

### D33 · Registered outside the `web` middleware group
That group starts a session, and `SESSION_DRIVER=database`. A monitor polling
every five minutes would write 288 junk session rows a day into the database this
endpoint exists to check. Outside the group it still picks up the **global**
middleware stack, which is where `PreventRequestsDuringMaintenance` lives.

### D34 · `/ready` deliberately returns 503 during maintenance; `/up` does not
`/up` is excepted from maintenance by Laravel itself — a liveness probe.
`/ready` is not, so it goes 503 for every deploy. That is how a failed deploy
that left maintenance mode on gets noticed, and it is exactly why the monitor
must alert on **two consecutive failures**. A single-failure rule pages on every
routine release and is muted within a fortnight.

---

## Payment reversal

### D35 · A separate append-only table, not a negative `payments` row
Three reasons, any one sufficient: `payments.amount` is `UNSIGNED INTEGER` and
would reject or clamp a negative; every per-payment surface assumes a payment is
money that arrived (a receipt would print for it, `byPaymentMethod` would show a
negative Cash row); and "was this reversed, by whom, why" has no answer in a
schema where a reversal is indistinguishable from a payment.

### D36 · One SQL fragment, `App\Support\NetReceipts`
Fifteen figures in this application answer "how much came in". A reversal that
reaches fourteen of them produces no error — it produces one screen that
**overstates** net receipts, which is the direction that makes a cashier look
like a thief.

Substituting it into `RevenueShare::ofPayment()` alone made every *apportioned*
figure correct at once. `PaymentReversalTest` snapshots all fifteen and asserts
each falls by exactly the reversed amount.

**A correlated subquery, not a join:** a LEFT JOIN would multiply the payment row
once per reversal and silently inflate every gross total.

### D37 · Reversing reopens a settled challan
`challans.status` is a denormalised "balance reached zero" flag, and the overdue
query, the ageing buckets and the status pill all read it rather than
recomputing. Leaving it on `paid` would hide a genuine debt from every screen
that exists to surface debt. The instalment schedule is re-derived for the same
reason.

### D38 · Partial reversal is supported; whether it is *permitted* is not decided
Full reversal is the special case of partial, and building only the special case
would mean rebuilding this the first time somebody types 50,000 for 5,000 —
which is the exact scenario that justified the feature.

Whether the institute *permits* a partial, and whether reversing implies handing
cash across the counter today or crediting the next instalment, are business
rules nobody has given. **Flagged as blockers, not guessed at.**

### D39 · A reversal cannot be reversed
There is no un-reverse. The correction for a mistaken reversal is a fresh payment
through the ordinary counter flow, which leaves both facts on the record.

### D40 · Supervisor-only, and the officer who took the money cannot undo it
New permission `payments.reverse`. `RolePermissionSeeder` grants Administrator
every key, so it lands there automatically and **not** on Admission Officer. That
separation is the entire control.

### D41 · Artisan **and** a supervisor UI action
The brief specified Artisan-only for release one. Rejected on the user's
direction, and it was the right call: an Artisan-only path means correcting a
counter error requires SSH, which in practice means the engineer does it, not the
supervisor — so the control in D40 exists on paper only.

Both routes call the same service. A second reversal implementation "just for
the CLI" would be the third money path in this codebase to drift from its twin,
and the previous two each cost a release.

### D42 · The receipt is stamped, not silently re-arithmetised
`payments.amount` is a historical fact and a reprint must match the copy the
student holds. A reversal changes the document's *standing*, not its figure, so
both copies carry a red "Reversed in full / Partly reversed" stamp.

`ReceiptController::balanceAfter()` counts only reversals that **already existed**
when that payment was taken. Counting all of them would rewrite history; counting
none would understate the balance on every later receipt, telling a student they
owe less than they do.

### D43 · `RecordRemoval` deliberately still reads GROSS
The one place in the application where that is right. Everywhere else the
question is "how much did the institute keep". There it is "would purging this
destroy money history", and a fully-reversed payment is still a payment row, a
printed receipt, and part of the trail an auditor expects. Reading net would let
a student whose only collection had been corrected be purged outright.

---

## Repository hygiene

### D44 · `.env.production.example` rather than changing `.env.example`
`.env.example` is a local-development template and its defaults
(`APP_ENV=local`, `APP_DEBUG=true`, `SESSION_SECURE_COOKIE=false`,
`BACKUP_KEEP=14`) are each individually unsafe on a live host. Making it
production-safe would make local development inconvenient and would not stop
anyone copying the wrong file. Two files, each honest about its purpose.

`.gitignore`'s `.env.*` rule needed an explicit negation, or the new template
would have been invisible.

### D45 · Deleted: `vercel.json`, `api/index.php`, `docs/DEPLOYMENT-VERCEL.md`, `.vercelignore`
The Vercel Hobby plan forbids commercial use and this system bills course fees.
Also removed the Vercel references from `config/app.php` and
`AppServiceProvider`'s comments — a wrong explanation in a comment outlives the
file it referred to.

### D46 · `docs/tech-stack.md` stubbed, not deleted
It described a system that was never built: PostgreSQL, spatie/laravel-permission,
Fortify, laravel-auditing, Sentry, Hetzner + Ploi. Deleting it means a link
somewhere goes dead and somebody reconstructs it from memory; a two-line stub
pointing at the real documents stops the reader where they are.

### D47 · Two root PNGs removed, `public/vendor/pdfjs` kept
`challan-view.png` and `students-azeem.png` were debugging screenshots from a
browser session, unreferenced by anything, 136 KB each, in the repository root.
`.gitignore` now blocks root-level images so they cannot come back — anchored, so
`public/assets/*.png` (real application assets) are untouched.

`public/vendor/pdfjs` **is not junk**: `DocumentResponse` loads it for every fee
voucher.

---

## Found while working, not in the brief

### D48 · The attendance migration could not roll back on MySQL
`migrate:rollback` died with `1553 Cannot drop index
'attendances_student_id_index': needed in a foreign key constraint`. MySQL will
not release the last index that can serve a foreign key. SQLite does not care, so
it was invisible on the driver we develop on.

`down()` now drops the constraint first and restores it after. Reproduced against
`mysql:8.0.46` locally and proved with `migrate → migrate:reset → migrate` on
both drivers.

### D49 · One bad `down()` presented as 276 unrelated test failures
`DatabaseMigrations` registers `migrate:rollback` and
`RefreshDatabaseState::$migrated = false` as one teardown callback. The rollback
threw, so the flag was never cleared, so every later `RefreshDatabase` test
skipped `migrate:fresh` and re-seeded into the database `ConcurrencyTest` had
already committed. Every one reported a duplicate `adminansar`, and not one
pointed at the cause.

`ConcurrencyTest` now clears that flag itself, unconditionally, so the next
failure of this shape stays in the file that caused it.

### D50 · Livewire 4 (Dependabot #35) closed, not merged
A framework **major** on an application that is Blade + Livewire 3 + Volt
throughout, failing CI, days before a first production deployment that has never
run on real hardware. It would invalidate the browser smoke pass that has to
happen before the counter opens. Deferred to week two.

### D51 · Hostinger KVM 2, Germany (Frankfurt) — superseding D1's Lightsail/Mumbai
D1 chose Lightsail in Mumbai on a latency argument. The server was bought on
Hostinger instead: **KVM 2, plain Ubuntu 24.04, no control panel, Frankfurt**,
$13.99/month, purchased 2026-08-29.

Frankfurt rather than Mumbai costs about 27 ms against Lahore — roughly 145 ms
versus 118 ms. D1's threshold was ~250 ms, where a Livewire round trip per
keystroke starts to feel broken at the counter, and neither figure is near it.
Germany is the better-supported Hostinger region, so the latency difference buys
nothing worth having on the other side.

The second half of the usual argument is **not** made here on purpose: student
records for a Lahore institute sitting on an EU server is a jurisdiction choice
with trade-offs both ways, not a legal improvement in itself. If the institute
has a view on where the data physically sits, that decision is theirs.

**Consequence:** the region is not permanent but moving means a rebuild and a DNS
change, so it is settled now rather than after go-live. Everything Lightsail-
shaped in `docs/DEPLOYMENT.md` §2 — instance creation, static IP, the console
firewall, snapshots — is replaced by `docs/GO-LIVE-CHECKLIST.md`. The firewall
is the one that matters: Lightsail's network ACL was restricting port 22 for us
and Hostinger has no equivalent, so `ufw` becomes the only control (P3-05).

### D52 · The VPS is billed to, and owned by, the client's Hostinger account
Purchased under `abdurrehman545@gmail.com`, with Admin access granted to the
engineer rather than the engineer owning the subscription.

That is the right ownership for a system the institute will still be running
after this engagement ends — the asset should not be hostage to a contractor's
billing relationship. It carries two consequences that are ours to manage rather
than theirs:

**The browser console and the OS-reinstall button are borrowed, not owned.**
Both are needed: the console is the way back in if `ufw` (P3-05) locks SSH out,
and the reinstall is how the twice-through rehearsal (P2-20) happens without
buying a second box. If Admin access is revoked or lapses, both disappear, and
they disappear at exactly the moment they are wanted.

**Renewal is on somebody else's card.** The term expires 2026-09-25. A fee-
collection system that goes dark because a renewal did not go through is
indistinguishable, from the counter, from a system that crashed.

### D53 · `pos.bigbinaryerp.com`
The production hostname is `pos.bigbinaryerp.com`, settled 2026-08-29 before any
certificate was issued, before `APP_URL` reached a live `.env`, and before
anything was committed. It is the natural home: the product is an ERP, and the
domain is the company's ERP domain.

Two other names were evaluated the same day and the reasoning is worth keeping,
because the criterion turned out not to be branding — **it was which zone we can
change without asking anyone.**

- `pos.bbt.edu.pk` — `bbt.edu.pk` is served flat by PKNIC with **no delegation**,
  so every record is a request to whoever holds the registry login, against a
  four-hour negative cache. It remains the institute's **email** domain and
  nothing here changes that.
- `pos.bigbinarytech.com` — briefly chosen, because at the time it was the only
  zone we demonstrably controlled: nameservers `ns1/ns2.dns-parking.com`, in the
  Hostinger account we hold Admin on. Sound while it was true, and dropped the
  moment it stopped being the only option.
- `pos.bigbinaryerp.com` — **initially blocked, then unblocked.** It was
  registered at Hostinger but resolved by Vercel, under an account nobody present
  could open. Moving the nameservers to Hostinger removed the blocker entirely,
  and the choice reverts to what it should have been on the merits.

**The caveat that outlives this decision.** The nameserver move is a live
migration of a zone that serves a working site (`www.bigbinaryerp.com` answers
200). During the changeover, resolvers holding the old Vercel delegation and
resolvers using the new Hostinger one give **different answers for the same
name** — and Vercel's zone has a wildcard, so `pos` resolves there too, to the
wrong address. Certbot must not run until that has settled (checklist P3-18/19),
and the Hostinger zone has to reproduce whatever Vercel was serving or the
marketing site goes dark hours later, looking causeless.

**Consequence.** The application hostname moved everywhere it is functional:
`azure-pipelines.yml` `PRODUCTION_HOST`, `.env.production.example` `APP_URL`,
`docs/DEPLOYMENT.md`, `docs/PRODUCTION-EMERGENCY.md`, `flow.md` and the go-live
checklist. It deliberately did **not** move in three places: the institute's
email addresses (`no-reply@`, `accounts@`, `owner@bbt.edu.pk`), the staff
accounts created by migration `2026_08_11_000002`, and the test fixtures. Those
are the **institute's** identity; the hosting hostname has nothing to say about
them, and a blind find-and-replace across `bbt.edu.pk` would have rewritten staff
account identities in a migration that has already run.

**The general lesson, which cost a session.** Three domains, three different
authorities, and every time the panel we were registered with was not the panel
that answered. **Run `dig NS <domain>` before typing into any DNS form.**
Hostinger's UI says so itself when it is not the authority — *"DNS is managed at
another provider… Inactive"* — and that banner is worth reading rather than
scrolling past.

### D54 · GitHub Actions, not Azure Pipelines — and `azure-pipelines.yml` deleted
The Azure DevOps organisation has no pipeline credits, so `azure-pipelines.yml`
could not run. GitHub Actions becomes the authoritative release path and the
repository stays on GitHub. Decided 2026-08-29, before the pipeline had ever
deployed anything, so nothing had to be migrated — only re-pointed.

**The architecture did not change; only the runner did.** Every property the
Azure design was chosen for is preserved:

| Azure DevOps | GitHub Actions | The property it protects |
| --- | --- | --- |
| Environment VM resource | self-hosted runner on the box | the box dials **out**; port 22 stays pinned to the admin IP |
| Agent runs as `institute` | runner runs as `institute` | three sudo grants, not a shell |
| Environment → Approvals | Environment → required reviewers | a human presses go on money software |
| Environment → Branch control | Environment → deployment branches: `main` | a feature branch cannot reach production |
| Environment → Exclusive lock | `concurrency: production-deploy` | one release at a time |
| `flock` in `deploy.sh` | unchanged | the lock that actually matters — it also covers a human running a release by hand |
| Required check named `CI` | unchanged | branch protection keeps waiting on the same name |
| `DEPLOY_ENABLED: 'false'` | unchanged, in the workflow | enabling production deploys is a commit, with an author and a reviewer |

`deploy/deploy.sh` and `deploy/provision.sh` were not touched. They never knew
which CI was calling them, which is why this was a half-hour change rather than
a re-platforming.

**`azure-pipelines.yml` was deleted rather than left in place.** The repository's
own rule, stated in the header of the file that is now authoritative, is that
two definitions of the same thing drift and the one nobody reads becomes a check
that cannot fail — which is worse than no check, because it is still trusted. A
dead pipeline for a provider with no credits is that failure mode by
construction. It remains in git history if it is ever wanted.

**Consequence:** the GitHub workflow gained the deploy and smoke jobs it
deliberately did not have, and its header — which previously said in as many
words *"this file does not deploy anything, and must not be given the ability
to"* — was rewritten rather than quietly contradicted.
