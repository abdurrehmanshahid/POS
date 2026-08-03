# Status report: Institute Management System (POS)

**Date:** 2026-08-03
**Branch:** `fix/cancel-guard-and-login-scope`
**Audited from:** `59c116c`
**Companion:** [PROJECT-TRACKER.md](PROJECT-TRACKER.md) for the trackable map and
the per-bug register

---

## 1. Summary

The application is in good shape. The architecture is sound, the security model
is genuinely well built and its reasoning is documented in the code rather than
assumed, and almost everything the backlog claims is delivered actually is.

What it had was a **drift problem**, not a design problem. Two features landed
late in the build and changed what a core concept means, and not every caller was
updated to match:

1. **Part payments** (`91acbbf`) made "how much has been collected" a question
   about the `payments` table rather than a boolean on the challan. The dashboard
   was rewritten to match. The Reports screen, its Excel export, the super admin
   analytics, the student CSV export and the purge guard were not.

2. **Cohorts and capacity** shipped with `isFull()` and `seatsLeft()` used for
   display, but nothing enforcing them at the point of writing an enrolment.

Both categories share a signature: **the code is correct in the case the seed
data happens to exercise, and wrong the first time a real operator does something
slightly different.** That is why 131 tests passed against a system whose
Dashboard and Reports screens disagreed by Rs 10,000 on the same data.

**17 defects found, all 17 fixed. 3 feature gaps found, 2 built, 1 left open for
your decision. 24 tests added. Suite went from 131 to 158, all green.**

---

## 2. What was actually wrong

### 2.1 The big one: money reported two different ways

This is the finding that mattered most, so it is worth showing rather than
describing.

I recorded a Rs 10,000 cash advance against `BBT-CH-2026-1084` (net Rs 20,000),
inside a transaction that was rolled back, and asked each surface the same
question:

| Surface | Reported received | Correct? |
| --- | --- | --- |
| Dashboard (`Ledger::received`) | 129,000 | yes |
| Reports (`Reporting::summary`) | 119,000 | no |
| Super admin (`Analytics::ledger`) | 119,000 | no |

And the knock-on effects:

- **Dues ageing** claimed Rs 95,000 outstanding against a true Rs 85,000. It aged
  the full face value of every unpaid challan, so a student who had handed over
  half their fee was still reported as owing all of it.
- **The by-payment-method breakdown** grouped on `challans.paid_via`, which
  records only the *last* method used. A fee paid half in cash and half by card
  reported the whole amount against Card and nothing against Cash. That table
  exists specifically so cash in the drawer can be reconciled against the Cash
  row, so it was wrong at exactly the job it was built for.
- **Officer scorecards** computed collection rate from the paid flag, so an
  officer diligently collecting advances scored as though they had collected
  nothing.
- **The student CSV export**, which is the file that goes to accounts, showed a
  student owing their full fee while the drawer next to it on screen showed the
  balance.

The reason none of this was caught: the seed data contains no part-paid challan.
`SUM(payments.amount)` and `SUM(net_amount WHERE status = 'paid')` both read
119,000 today. The bug was dormant and would have activated the first time an
officer took an advance through the UI, which is a headline feature of the
product.

**Fixed** by making `Ledger::scopedPayments()` the single entry point every money
figure starts from, and deriving all of `Reporting` and `Analytics` from it.
Collections are now dated by `payments.received_at`, methods grouped by
`payments.method`, and dues aged on `Challan::balance()`.

### 2.2 Purge could destroy collected money

`RecordRemoval::purgeBlocker()` refused to purge a student with a **paid**
challan. A student carrying a part payment has no paid challan, so the purge went
ahead. It hard-deletes their challans, and `payments.challan_id` is
`cascadeOnDelete`, so every collection row went with them. The audit snapshot
covers only the student row, so the money vanished from every report with nothing
recording that it ever existed.

This is notable because commit `1548693` fixed **exactly this hole** for
cancellation two commits earlier, switching that guard from the paid flag to
`hasCollections()`. The reasoning was correct and was written down in the code.
It just was not carried across to the other path that destroys the same data.

### 2.3 Enrolment invariants were displayed but not enforced

Three related cases, all with the same shape: the UI showed a rule, and nothing
made it true.

- **Course capacity.** The wizard blocks a full course at selection time, which
  is real server-side enforcement and works. But selection and submission are
  separate requests, so a course with one seat left could fill in between, and
  any caller that is not the wizard had no guard at all.
- **Cohort capacity.** `Cohort::isFull()` and the "Full" badge existed on the
  batches screen. `Cohorts::openFor()` returned the open cohort regardless, so a
  batch capped at 20 quietly took its 21st student.
- **Stale course ids.** If the selected courses no longer resolved, the fan-out
  loop simply ran fewer times. With one course selected that produced a student
  record with no admission at all, plus a notification announcing an enrolment
  that never happened.

### 2.4 The duplicate enrolment you found in QA

You found this one while testing: nothing stopped the same student being
registered onto the same course twice. Two admissions, two challans, two fees for
one seat. I reproduced it immediately (`BBT-ADM-0012` and `BBT-ADM-0013` for one
student on SHOP-101).

It is an easy mistake to make at a counter: the officer is not sure the first
registration saved, so they do it again.

I fixed it at three layers, because each catches a case the others do not, and
the design decision worth flagging is **what exactly to forbid**. A blanket
unique index on `(student_id, course_id)` would have been wrong: re-taking a
course in a later batch is legitimate, and so is enrolling again after a
cancellation. What is never legitimate is holding two live enrolments at once.

- **Database:** a VIRTUAL generated column that is `1` while the admission is
  live and `NULL` once cancelled, with `UNIQUE (student_id, course_id,
  active_slot)`. Both MySQL 8 and SQLite exclude NULLs from uniqueness, so any
  number of cancelled rows is allowed and only one live row is not. I chose a
  generated column over a maintained one so it cannot drift out of step with
  `status` even under a mass update that fires no model events, and VIRTUAL over
  STORED because SQLite only permits VIRTUAL to be added by `ALTER TABLE`. I
  verified the semantics on SQLite before committing to the approach.
- **Service:** a readable refusal ("Maha Asim is already enrolled on Shopify
  (SHOP-101)") so the officer does not meet a driver-level integrity error, and
  so the whole registration is refused before anything is written rather than
  part way through a multi-course fan-out.
- **Wizard:** those course cards now read "Already enrolled" and refuse
  selection. Verified in the browser.

The migration refuses to run if duplicates already exist, naming them, rather
than guessing which of two enrolments is the real one. Your data had none.

### 2.5 A control that lied

The registration wizard's review step has a checkbox: "Generate fee challan(s) on
submit". It was bound to a real property, rendered correctly, and completely
inert. `submit()` never passed it through, and the service created a challan
unconditionally. Unticking it changed nothing.

I confirmed this in the browser before fixing it, because a dead control is the
kind of thing that is easy to assert and embarrassing to get wrong.

### 2.6 A scope hole in the wizard

The wizard's "existing student" search ran an unscoped query, and the service
resolved the chosen student with a bare `findOrFail()`. An Admission Officer
could therefore search every student in the institute by name or CNIC and enrol
any of them.

What makes this clearly a bug rather than a decision: the same component scopes
`mount()` and `useExistingStudent()` correctly, and the comment on the latter
explicitly says it is scoped "so this cannot be used to reach a student the
officer is not allowed to see". The plain search path bypassed the rule the file
itself documents.

### 2.7 Smaller things

- Concurrent collections on one challan could both pass the balance check and
  both insert, because the balance was read outside the transaction with no row
  lock.
- `Analytics::ledger()` reported a `reconciles` flag computed as
  `$billed === $received + ($billed - $received)`, which is true for every
  possible input. It read like an invariant check on the owner dashboard and
  verified nothing.
- Every guest screen was titled "Sign in", so the two-factor setup page announced
  itself as a sign-in form and the super admin console carried the staff portal's
  branding.
- A permission-denied navigation returned the raw framework 403 page.
- An Administrator pinned to two-factor enrolment had no way to sign out, even
  though the middleware deliberately permits it.
- "Keep me signed in" defaulted to on, on a portal used from shared counter
  machines.
- `Period::days()` emitted a PHP deprecation on every Reports page load.

---

## 3. The feature gaps

### 3.1 Attendance: built

The `attendances` table and its model shipped with the original schema and then
sat completely inert for the whole build. No screen, no service, no permission
key, no route, no nav entry, no reporting. The Reports page had once rendered
invented 92% and 78% figures against it, and those were deleted rather than left
to look real, which was the right call.

I built the missing half:

- **`App\Services\Attendances`**: roster derived from live enrolments (so there
  is no class list to keep in sync, and a cancelled enrolment drops off the
  register the same day), idempotent record-and-correct, and per-course rates.
- **The capture screen**: pick a course, optionally a batch, and a date; mark
  each student present, absent or on leave; bulk "all present" / "all absent"; a
  live tally. Re-opening a day that was already taken loads the existing marks
  and saving corrects them.
- **A unique index** on `(course_id, student_id, session_date)`. Without it,
  saving a register twice writes a second set of rows and doubles the denominator
  of every attendance percentage the table will ever produce.
- **One audit row per register**, not per student. Thirty rows a day would bury
  every other event in the activity log.

Two decisions worth surfacing. Students default to **present**, because marking
the exceptions is the job: in a class of thirty, two are away, and defaulting to
absent would mean twenty-eight taps to record a normal day. And **`leave` is
excluded from the rate's denominator** rather than counted against it, so
authorised absence does not punish a course's attendance figure.

I did **not** seed any attendance history. The table is empty until somebody
takes a register, which is the honest state, and inventing a backlog of marks
would repeat exactly the mistake the deleted 92% card represented.

### 3.2 The dashboard revenue chart was mostly invented: fixed

`config/institute.php` carried six hardcoded monthly figures which the dashboard
rendered as January to June, with only the current month computed live. Same
category as the fabricated attendance card, and arguably worse: those invented
bars sat directly beside the reconciled billed / received / outstanding totals,
which lent them credibility they had not earned.

The live bar was also wrong in its own right. It showed **all-time** received
rather than the current month, so the final column of the chart silently answered
a different question from every column before it.

Every month is now derived from `payments.received_at`, scoped to the viewer,
with quiet months rendered as visible zeros. The chart is shorter until real
history accumulates, which is the correct thing for it to be.

### 3.3 Split payment plan: left open, and I want your decision

`challans.plan` has a `split` value and `installments` is modelled, but nothing
creates installment rows and no UI offers the choice. Every challan is `full`.

I left this open deliberately rather than building it, because **it may be
redundant**. The `installments` table predates the `payments` ledger. Part
payments already let a student pay a fee in stages, with a running balance and a
row per handover, which is strictly more flexible than two fixed installments.

The real question is whether the institute needs **scheduled** installments with
their own due dates, which payments genuinely do not cover, or whether
`installments` has been superseded and should be deleted. That is a product call
rather than a defect, so it is yours. Building it silently or deleting it
silently would both have been me making your decision for you.

---

## 4. What I checked and found healthy

Worth recording so nobody re-audits it:

- Money is integer PKR everywhere, `net_amount` is derived and never accepted
  from the client, and the discount is bounded server-side with a mandatory
  audited reason.
- `Sequences` allocates every serial under `lockForUpdate()` inside a
  transaction, with UNIQUE indexes as the backstop, and previews never consume a
  number.
- The `payments` backfill migration correctly reconstructs history from the paid
  flag, so no revenue was lost when the ledger changed shape.
- Audit rows are genuinely append-only, enforced in `AuditLog::booted()`, with no
  foreign keys and snapshotted names so the trail outlives its subject. Purge
  writes its audit row, including a full record snapshot, before deleting, in the
  same transaction.
- Impersonation holds both identities and never launders attribution.
- `PrivilegeGuard` implements the full delegation model and protects the last
  administrator.
- The challan drawer and the record-payment dialog were **already** fully
  payments-aware. Collected, balance, the part-payment warning and the max were
  all derived correctly. The drift was confined to reporting.
- Officer scoping holds on the dashboard, challans list, students list, PDF
  download and both exports.

I also investigated two things that looked like bugs and **were not**, recorded
so they do not get re-raised:

- `Period::days()` returns the correct number of days (365 for a year, 31 for
  July). Only the deprecation warning was real.
- `installments()->update(...)` does not throw on a table without timestamp
  columns, because `Installment::$timestamps = false` means Eloquent never adds
  `updated_at`.

---

## 5. Verification

Everything below was run after the changes.

| Check | Result |
| --- | --- |
| `php artisan test` | 158 passed, 478 assertions, 0 failed |
| `vendor/bin/pint` | clean |
| `php artisan migrate` | both new migrations applied |
| Demo figures | still reconcile: 214,000 = 119,000 + 95,000 |
| Browser, officer portal | dashboard, challans, students, registrations, attendance: no console errors |
| Browser, guest titles | `/login`, `/forgot-password`, `/superadmin` all distinct and correctly branded |
| Browser, 403 | branded screen with "Back to Dashboard" |
| Browser, duplicate enrolment | cards read "Already enrolled", selection refused |
| Browser, attendance | marks save, reload, and correct on re-save |

One test was **changed rather than kept**, and it is worth explaining. 
`ReportsTest::test_collections_are_dated_by_payment_not_by_issue` set up its
scenario by writing `status = 'paid'` and `paid_at` directly onto a challan with
`forceFill()`, with no payment row behind it. Under a payments-derived ledger that
state is not "a settled fee", it is inconsistent data the application itself
cannot produce. The test's *intent* was right and is preserved; its *setup* now
records the collection through `ChallanActions` like the real system does.

---

## 6. Two changes to your development database

Both were required for the new work to be reachable, and both match what a fresh
`migrate:fresh --seed` now produces:

1. Two migrations ran: the attendance unique index, and the live-enrolment
   constraint.
2. `attendance.manage` was granted to the `admin` and `officer` roles. The seeder
   does this for new installs; your database predated the key.

Attendance marks I wrote while testing the screen were deleted afterwards, so the
table is empty.

---

## 7. What I would do next

In order, and none of it is urgent:

1. **Decide GAP-03** (split plan: build scheduled installments, or delete the
   table). It is the only open item.
2. **Consider a seeded part-paid challan.** The entire BUG-01 family survived 131
   tests because no fixture exercised a partial collection. One seeded part-paid
   challan would have made the divergence visible on the dashboard on day one.
   This is the single highest-value change to the test data.
3. **Officer 2FA.** `docs/SECURITY.md` correctly flags that officers start
   password-only. Worth revisiting once every officer has the app.
4. **`APP_DEBUG=false` before production**, already noted in the security doc.
5. **Attendance reporting surface.** The rates are computed and tested
   (`Attendances::ratesByCourse`), but I did not add a card to the Reports screen.
   Once there is a week or two of real registers, that is a small addition, and
   doing it now would only display an empty state.

---

## 8. Servers

Left running for your QA:

- Application: <http://127.0.0.1:8000>
- Vite dev server: <http://localhost:5173>

| Role | Username | Password | Note |
| --- | --- | --- | --- |
| Administrator | `adminansar` | `Bbt@Admin1` | role requires 2FA, lands on enrolment |
| Admission Officer | `aliraza` | `Bbt@Officer1` | no 2FA, signs straight in |

The fastest way to see the main fix: open any unpaid challan, record a part
payment, then compare the Dashboard total against the Reports page. Before today
those two numbers disagreed.
