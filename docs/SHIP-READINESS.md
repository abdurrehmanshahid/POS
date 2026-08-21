# Ship readiness: can this go live tomorrow?

**Date:** 2026-08-16 (updated) · **Branch:** `main` at `cbd273f` + uncommitted deployment work
**Suite:** 347 tests, 1129 assertions — green on **both** legs (SQLite: 342 + 5 MySQL-only skips; MySQL: 346 + 1)
**Companion:** [PROJECT-TRACKER.md](PROJECT-TRACKER.md), [STATUS-REPORT.md](STATUS-REPORT.md)

> **This revision supersedes the `7a38ae4` version of this file.** Four of that
> version's six blockers are closed. The import it called "blocked on nine
> decisions" has since run. Numbers below were re-measured against `cbd273f`,
> not carried over.

---

## 1. The verdict

**The application can ship. The remaining work is environment and one human pass
over the data — not code.**

What changed since the last revision:

- **The roll imported.** 447 of 478 rows are in. The nine decisions that blocked
  it are down to two, covering **8 rows**. This was the whole point of launch and
  it is substantially done (§4).
- **The counter has a receipt.** B-03 is closed — `ReceiptController`, three
  routes, covered by tests.
- **The suite grew from 265 to 332 tests** (1092 assertions), all green.
- **The QA junk is gone** from the dev database.

What still stands between here and a real counter:

- **Production does not exist yet.** `.env` is still `sqlite` / `local` /
  `APP_DEBUG=true`. Everything above was proven against the dev database (§3, B-02).
- **54 students need a human merge pass** — a new finding, expected by design,
  but nobody has done the pass and there is no tool to do it with (§5, B-07).

Closed since, and no longer standing in the way:

- **Concurrency is proven** (B-04). Five tests, two real MySQL connections.
- **A backup has now been restored** (B-05) — automatically, and on a schedule.
- **The production path is written and automated** — `deploy/provision.sh`,
  `deploy/deploy.sh`, and a deploy stage on the pipeline. See
  [DEPLOYMENT-ORACLE.md](DEPLOYMENT-ORACLE.md). B-02 is now *unrun*, not
  *unplanned*: somebody has to create the Oracle instance.

**The one thing that must not happen:** running `--commit` against production
before a backup exists and the 8 rows in §4 are answered. That has not changed.

---

## 2. Blockers, ranked — current state

| ID | Blocker | Status |
| --- | --- | --- |
| B-01 | Roll not imported | **CLOSED in dev** — 447/478 in. 8 rows still need answers (§4) |
| B-02 | Production environment not configured | **OPEN — now the top blocker** |
| B-03 | No receipt after money is collected | **CLOSED** — `ReceiptController` + 3 routes + tests |
| B-04 | Concurrency guarantees unproven | **CLOSED** — `ConcurrencyTest`, 5 tests, two real connections, green on MySQL |
| B-05 | No backup has ever been restored | **CLOSED in code** — `backup:run` + `backup:verify`, drill proven on MySQL; awaits a production box |
| B-06 | QA records in dev database | **CLOSED** — 0 remaining |
| B-07 | 54 duplicate students need a merge pass | **NEW, OPEN** |

### B-02 Production environment is not configured — now the critical path

Unchanged and now the thing everything else waits on. `.env` today:

```
APP_ENV=local   APP_DEBUG=true   DB_CONNECTION=sqlite   MAIL_MAILER=log
```

Production needs MySQL 8, `APP_ENV=production`, `APP_DEBUG=false`, a fresh
`APP_KEY`, a real mail transport, and HTTPS. `APP_DEBUG=true` in production leaks
stack traces and config on every error. Mechanical — but until it is done, the
successful import is an import into a laptop.

### B-04 Concurrency guarantees — CLOSED

`tests/Feature/ConcurrencyTest.php` now drives **two genuinely separate MySQL
connections** and proves all four invariants serialise: the counter row (serial
allocation and the challan serial both go through `Sequences::bump`), the course
row (capacity), and the challan row (collection). Connection A holds the lock,
connection B is given a one-second `innodb_lock_wait_timeout` and is refused.

A control test asserts B *can* read the same row when no lock is held — without
it, a broken second connection would look exactly like a working lock.

Skipped on SQLite by design and marked as such: SQLite takes one writer per
file, so it would pass while proving nothing. The CI MySQL leg runs it.

### B-05 No backup has ever been restored — CLOSED in code

Two commands, both on the scheduler:

- **`backup:run`** (daily 02:00) — gzipped, restore-ready dump; keeps 14;
  audited; **fails loudly** rather than writing an empty file and going green.
- **`backup:verify`** (Sunday 03:00) — restores the newest dump into a scratch
  database, compares every table, drops the scratch, exits non-zero on mismatch.

The drill was run for real against MySQL 8: 20 tables restored and matched.

It compares against a `.json` manifest of row counts captured *before* the dump,
not against the live database. That distinction is the whole design — measured
against live, the drill fails every week (the roll moves, the audit log only
appends), and a check that cries wolf is a check nobody reads. Verified: with
live `audit_logs` at 26 against a manifest of 23, the drill correctly passes.

Note the earlier revision's claim that "there is no backup command at all" was
wrong — `BackupController` has always streamed a dump from the super admin
screen. What was missing was the unattended half, and any evidence a dump could
be restored.

**Still outstanding:** none of this has run on a production box, because there
isn't one yet (B-02). Get a copy off the box too — see
[DEPLOYMENT-ORACLE.md](DEPLOYMENT-ORACLE.md) §7.

### B-07 54 students are probably 54 fewer people — NEW

`php artisan records:duplicates` reports **54 suspected duplicates**. Example:

```
143 | Sara       | +92 333 0291114 | admission on course 8
363 | Sara Sadiq | +92 333 0291114 | admission on course 8
364 | Sara Sadiq | +92 333 0291114 | admission on course 23
365 | Sara Sadiq | +92 333 0291114 | admission on course 24
```

One woman, one phone, four student records.

**This is deliberate, not a defect.** `RollPersister::student()` refuses to match
on name because the roll contains 59 names shared by more than one row and no
CNIC to separate them. Merging would fuse two strangers and pool their fees,
which cannot be unpicked once money lands. Splitting can be corrected later.
The importer chose the recoverable direction, and said so in the code.

**But "correctable later" is a promise nobody has kept yet.** Two things are open:

1. Nobody has walked the 54. Until someone does, a student can hold several
   balances and see only one.
2. **There is no merge tool.** The code comment assumes "a person can merge two
   records"; no such feature exists. So the pass ends in hand-written SQL —
   the same hole as GAP-07.

Not a launch blocker for billing correctness — each record's money is right.
It is a blocker for a counter clerk trusting what is on screen.

---

## 3. What I verified today, with evidence

Everything re-run against `cbd273f`, not read off the tracker.

| Question | Answer | Evidence |
| --- | --- | --- |
| Is the suite green? | Yes — **332 / 1092 assertions** | `phpunit`, 14.2s |
| Did the data migration run? | **Yes** — 447 imported, 31 rejected | `roll:import` dry-run now reports 447 "already imported" |
| Is there a receipt? | **Yes** — new since last revision | `routes/web.php:93-99`, `ReceiptController`, tested |
| Is the database configured? | **Local only** — `sqlite`, `local`, debug on | `.env` — see B-02 |
| Are migrations current? | Yes — 35 files, all ran | `migrate:status` |
| Any QA junk left? | **No** | 0 students matching QA/Test |
| Duplicate check? | 54 flagged — see B-07 | `records:duplicates` |

**What the database holds now:** 427 students, 24 contacts, 36 courses, 9 users,
478 payments, 458 challans, **0 installments**.

Two of those deserve a note:

- **36 courses and 9 users** are the fix landing: the 27 missing catalogue
  entries and the 6 CSR accounts were created, exactly as §5 of the last
  revision recommended.
- **0 installments** is still expected, not a failure. Every importable row was
  fully paid, so the schedule path had nothing to build. The importer tests are
  the proof that schedules work — not this run.

---

## 4. The import: what is left

The nine decisions are down to two. Current `roll:import` dry-run:

```
Read 478 rows · Already imported 447 · Duplicate lines collapsed 2 · Rejected 31
```

### Resolved since last revision — no action needed

| # | Was refused for | Rows | How it was closed |
| --- | --- | --- | --- |
| 1 | Unknown course (27 distinct) | 339 | Catalogue entries + aliases created (9 → 36 courses) |
| 2 | No phone number | 72 | `students.phone` made nullable (`..._000003_allow_legacy_students_without_a_phone_number`) |
| 3 | Unknown CSR (6 accounts) | 43 | Accounts created (`..._000002_create_accounts_for_the_officers_named_in_the_roll`) |
| 4 | Instalment owed, no due date | 33 | Balance imports unscheduled (`..._000004_allow_a_legacy_balance_with_no_due_date`) |
| 5 | Not a course | 32 | Now billable as a charge (`..._000005_let_a_challan_bill_something_that_is_not_a_course`) |
| 7 | Phone not a PK mobile | 25 | Foreign numbers accepted, junk rejected |

### Deliberate exclusions — 23 rows, correct as they stand

- **21 — no course named.** Unimportable by definition.
- **2 — no discounted price.** Nothing to bill.

These should stay refused. A clean run means *only* these remain.

### Still needs the institute — 8 rows

**#6 · Two lines claim the same student on the same course, and disagree (2 rows)**

> lines 292, 354 — same person, different money:
> fee 6,000 / received 6,000 **vs** fee 12,000 / received 12,000

Somebody paid either Rs 6,000 or Rs 12,000. There is no rule that picks
correctly; the file contradicts itself. **Ask which line is authoritative.**

**#9 · Collected more than the fee (6 rows)**

| Collected | Fee | Excess |
| --- | --- | --- |
| 36,000 | 35,000 | 1,000 |
| 11,500 | 8,500 | 3,000 |
| 22,500 | 17,500 | 5,000 |
| 55,000 | 54,996 | 4 |
| 13,000 | 12,080 | 920 |
| 25,000 | 24,960 | 40 |

Two shapes here, and they probably want different answers. The bottom three
(Rs 4, Rs 40, Rs 920) look like **rounding at the counter** — cash paid to the
nearest note. The top three (Rs 1,000–5,000) are too large for that and more
likely a **fee recorded wrong**.

Either the fee is corrected upward, or the excess is recorded as credit.
The system overpayment rule refuses the second today, so **correcting the fee is
the only path that currently exists.** Six questions, one per row.

**The safe order, unchanged:** answer these 8 → re-run the dry-run until only the
23 deliberate exclusions remain → **take a backup** → `--commit`.

---

## 5. Security: what is actually implemented

Unchanged from the last revision and re-confirmed present:

**Authentication** — password sign-in with strength rules; two-counter throttling
(5/account, 20/IP); **TOTP 2FA** (RFC 6238, offline), required for admins;
timestep burnt after use so a code cannot be replayed; recovery codes hashed,
shown once, single-use; forced reset on first login; deactivation kills the live
session; **step-up challenge** before destructive acts; password reset replies
identically for real and unknown accounts.

**Authorisation** — 18-key permission catalogue; route middleware **and** in-view
`can()`; officers scoped to their own students; **anti-escalation** (an admin
cannot grant what they do not hold); last-administrator protection; branded
403/404.

**Money integrity** — `payments` append-only; audit log append-only, enforced in
`AuditLog::booted()`; purge and cancel refuse once money is collected; one live
enrolment per student per course via a **generated-column unique index**;
overpayment refused by design; idempotency guard keyed
`sha256(operation | actor | token)` on the three writes with no natural key; an
architecture test fails if a new screen writes one without the guard.

**Known security-relevant gaps** — `APP_DEBUG=true` / `APP_ENV=local` (B-02); no
refund/reversal path, so corrections happen in the database by hand (GAP-07); no
cashier shift/drawer reconciliation, the only **external** witness that could
catch a phantom payment (GAP-06).

---

## 6. The plan

**Next — the critical path**
1. **Create the Oracle Always Free instance** and run `deploy/provision.sh`
   (B-02). Home region Mumbai/Dubai/Singapore — it cannot be changed later.
   Everything else in this step is now scripted.
2. ~~Do a restore drill~~ — **done, and automated weekly.** It runs on the
   production box from the first deploy onward.
3. Answer the **8 rows** in §4 (one duplicate pair, six overcollections)

**Then — the production import**
4. Migrate a clean production database, re-run the dry-run until only the 23
   deliberate exclusions remain
5. **Take a backup**, then `roll:import --commit`
6. Run `records:duplicates` against production
7. Smoke-test on production data: register a student, collect a part payment,
   print a challan **and a receipt**, open Reports, confirm Dashboard and Reports agree

**Before real counter use**
8. Walk the **54 duplicates** (B-07) — and decide whether a merge tool is built
   or the pass is hand-written SQL
9. Write the **two-connection concurrency test** on the MySQL leg (B-04 / RISK-01)

**Next**
10. GAP-06 cashier shift & drawer reconciliation, GAP-07 refunds/reversals,
    GAP-03a counter-facing installment plans

---

## 7. Questions still unanswered

These were asked in the last revision and are still open. They are not blocking
the import, but each one is somebody waiting on somebody.

1. **"The frontend is different from the designed frontend."** There is still no
   design file, Figma export or prototype in this repository.
   `FRONTEND_CONVENTIONS.md` refers to "the prototype" but it is not committed.
   Point me at the design and I will diff it screen by screen.
2. **"Sign-in and sign-up logic needs to be implemented."** Sign-in **is**
   implemented and working. There is deliberately **no public sign-up** — this is
   a staff POS; accounts are created by an admin on Staff & Roles. A public
   self-registration route would let anyone create an account next to the
   institute's money. Confirm whether you want something different, and for whom.
3. **"Email OTP needs to be implemented."** The second factor is TOTP today, and
   `MAIL_MAILER=log` means no mail is sent at all. Should email OTP **replace**
   TOTP, or be a **fallback** when an officer has no authenticator app? That
   changes the design — and it depends on B-02 landing a real mail transport first.

---

## 8. Honest limits of this round

- **Admin-side screens still carry no browser pass.** Reports, Staff & Roles,
  Courses, Settings and `/superadmin` have never been driven in a browser,
  because generating a TOTP requires decrypting a stored 2FA secret and the
  environment blocks it. Every round that *has* done a browser pass found defects
  the suite could not see. This is the largest unexamined surface in the project.
- **Everything here was measured against the dev SQLite database.** The import
  succeeded there. It has never run on MySQL, which is the production engine and
  the one where B-04's locking actually matters.
- **The 54 duplicates were found, not resolved.** No merge has been attempted.
