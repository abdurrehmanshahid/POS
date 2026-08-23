# Project tracker: Institute Management System (POS)

Living map of what exists, which layer owns it, what stage it is at, and what is
still open. Built by reading every service, model, migration and screen in the
repository, running the suite, querying the live SQLite database, and driving the
running app in a browser.

- Branch: `main`, plus the double-submit guard (GAP-08)
- Date: 2026-08-09 (previous audit 2026-08-08 from `281601f`)
- Suite: **262 passed, 760 assertions, 0 failed** (189 at the previous audit)
- Style: `vendor/bin/pint` clean
- Build: `npm run build` clean
- Companion document: [STATUS-REPORT.md](STATUS-REPORT.md) for the narrative
- Delivery history: `docs/backlog.md` on the `docs/delivery-backlog` branch

## How to read this

**Stage** is what the code actually does, not what was intended:

| Stage | Meaning |
| --- | --- |
| `SHIPPED` | Built, wired end to end, covered by tests, verified working |
| `SHIPPED-DEFECT` | Built and reachable, but provably wrong in a named case |
| `PARTIAL` | Some layers exist, one or more layers missing |
| `SCHEMA-ONLY` | Table and model exist, nothing reads or writes them |
| `DEAD` | Rendered to the user but wired to nothing |

**Layer** is `FE` (Blade, Livewire, Alpine, CSS), `BE` (services, controllers,
middleware, models) or `DB` (migrations, indexes, constraints).

Every bug carries an ID. Use it in commits and branches, for example
`fix/BUG-01-reporting-reads-payments`.

## Status at a glance

| | Cumulative | 2026-08-09 round |
| --- | --- | --- |
| Defects found | 28 | 2 (BUG-27, BUG-28) |
| Defects fixed | 28 | 2 |
| Defects still open | 0 | 0 |
| Feature gaps found | 10 | 1 (GAP-03a, split out) |
| Feature gaps closed | 5 | 3 (GAP-03, GAP-04, GAP-08) |
| Feature gaps open | 5 (GAP-03a, GAP-05 … GAP-07, GAP-09) | — |
| Open production risks | 1 (RISK-01) | 1 |
| Open data decisions | 2 (DATA-01 blocking, DATA-02) | 2 |
| Tests added | 107 | 73 |

**The 2026-08-09 round ran in two parts.** The first built the roll importer and
the schedule caller (GAP-03, GAP-04). The second closed GAP-08, the double-submit
guard, and turned up two pre-existing defects on the way — BUG-27, money
collectable against a cancelled registration and then invisible to every report,
and BUG-28, a double-click showing "You do not have access to this screen" over a
payment that had just succeeded.

**Three defects were caught by review before reaching `main` and are recorded
here rather than in the bug register**, because they were never shipped. Each is
worth knowing about:

- `admissions.status => 'active'`, a value absent from
  `enum('validated','pending','cancelled')` — would have failed **every** import
  row on MySQL while passing on SQLite.
- `Operations::once()` wrapping the marker and the work in one `try`, so a unique
  violation from inside the work was reported as a replay: a hard failure
  presented to the officer as success.
- The idempotency token stored verbatim, making one global namespace of every
  operation, so a token burnt on one operation silenced another.

The pattern across all three is the same, and it is worth stating plainly: the
suite is good at proving that intended behaviour works and poor at noticing that
a failure has been dressed up as a success.

Of the 9 defects in the 2026-08-08 round, 4 came from driving the app in a browser
and 5 from the review pass over the diff. **None of the 9 was caught by the
suite**, which was green at 180 before that round started.

GAP-05 to GAP-09 and RISK-01 came from a later benchmark of the money workflow
against how established POS products model it. They are **product and production
gaps, not defects**: nothing listed under them is known to be broken, and none
of that round re-opened or re-tested repository code. Where a claim in this
document says code was verified, it was verified on the date given.

---

## Part 1: The system map

### 1.1 Authentication and account security

| Capability | Layer | Files | Stage |
| --- | --- | --- | --- |
| Password sign in | FE+BE | `livewire/pages/auth/login.blade.php` | SHIPPED |
| Two counter throttling (5/account, 20/IP) | BE | `Support/LoginThrottle.php` | SHIPPED |
| Password strength rules | BE | `Support/PasswordStrength.php` | SHIPPED |
| TOTP enrolment | FE+BE | `pages/auth/two-factor-setup.blade.php`, `Services/TwoFactor.php` | SHIPPED |
| TOTP challenge before session | BE | `Services/TwoFactorChallenge.php` | SHIPPED |
| Replay protection (timestep burn) | BE | `Services/TwoFactor.php` | SHIPPED |
| Recovery codes (hashed, shown once) | BE | `Models/Concerns/HasTwoFactorAuth.php` | SHIPPED |
| First login forced password reset | BE | `Middleware/RequirePasswordReset.php` | SHIPPED |
| Admin issued temporary passwords | FE+BE | `pages/staff.blade.php` | SHIPPED |
| Self service reset via emailed token | FE+BE | `pages/auth/reset-password.blade.php` | SHIPPED, off by config |
| Deactivation kills a live session | BE | `Middleware/EnsureActiveUser.php` | SHIPPED |
| Step up challenge for destructive acts | BE | `Services/StepUp.php` | SHIPPED |
| Page titles on guest screens | FE | `components/layouts/guest.blade.php` | SHIPPED (was BUG-12) |
| Sign out from the forced enrolment screen | FE | `pages/auth/two-factor-setup.blade.php` | SHIPPED (was BUG-16) |
| Remember me default | FE | `pages/auth/login.blade.php` | SHIPPED (was BUG-15) |

### 1.2 Access control

| Capability | Layer | Files | Stage |
| --- | --- | --- | --- |
| 18 key permission catalog | BE | `Support/Permissions.php` | SHIPPED |
| `can()` resolves to role grants | BE | `Providers/AppServiceProvider.php` | SHIPPED |
| Route level permission gate | BE | `Middleware/EnsurePermission.php` | SHIPPED |
| Officer vs admin data scope | BE | `Models/Admission.php`, `Models/Student.php` (`scopeVisibleTo`) | SHIPPED |
| Anti escalation delegation rules | BE | `Services/PrivilegeGuard.php` | SHIPPED |
| Last administrator protection | BE | `Services/PrivilegeGuard.php` | SHIPPED |
| Permission aware nav and landing screen | FE+BE | `Support/Nav.php` | SHIPPED |
| Scope applied to the wizard student search | BE | `pages/registrations.blade.php` | SHIPPED (was BUG-10) |
| Branded 403 and 404 screens | FE | `resources/views/errors/` | SHIPPED (was BUG-13) |

### 1.3 Institute core

| Capability | Layer | Files | Stage |
| --- | --- | --- | --- |
| Student records, soft deletable | DB+BE | `create_students_table`, `Models/Student.php` | SHIPPED |
| Course catalog with fee and capacity | DB+BE | `create_courses_table`, `Models/Course.php` | SHIPPED |
| Teachers | DB+BE | `create_teachers_table`, `Models/Teacher.php` | SHIPPED |
| Admissions (student x course) | DB+BE | `create_admissions_table`, `Models/Admission.php` | SHIPPED |
| One live enrolment per student per course | DB+BE+FE | `one_live_enrolment_per_student_per_course` | SHIPPED (was BUG-17) |
| Cohorts / batches, one open per course | DB+BE | `create_cohorts_table`, `Services/Cohorts.php` | SHIPPED |
| Auto join the open batch on enrolment | BE | `Services/RegistrationService.php` | SHIPPED |
| Backward adoption of unbatched admissions | BE | `Services/Cohorts.php::adoptUnbatched` | SHIPPED |
| Atomic serial allocation with row locks | BE | `Services/Sequences.php` | SHIPPED |
| Institute prefix on every identifier | DB+BE | `prefix_identifiers_with_institute_code` | SHIPPED |
| 3 step registration wizard | FE | `partials/registration-wizard.blade.php` | SHIPPED |
| Live per field wizard validation | FE+BE | `pages/registrations.blade.php` | SHIPPED |
| Returning student detection by CNIC | FE+BE | `pages/registrations.blade.php` | SHIPPED |
| Discount bounded 0 to 100 with reason | BE | `Services/RegistrationService.php` | SHIPPED |
| Standalone student create and edit | FE+BE | `Services/StudentService.php` | SHIPPED |
| Course capacity enforced on enrolment | BE | `Services/RegistrationService.php` | SHIPPED (was BUG-05) |
| Course active state enforced on enrolment | BE | `Services/RegistrationService.php` | SHIPPED (was BUG-05) |
| Cohort capacity enforced | BE | `Services/Cohorts.php` | SHIPPED (was BUG-06) |
| Stale course id handling | BE | `Services/RegistrationService.php` | SHIPPED (was BUG-07) |
| "Generate fee challan(s)" toggle | FE+BE | `partials/registration-wizard.blade.php` | SHIPPED (was BUG-09) |

### 1.4 Fees, challans and the ledger

| Capability | Layer | Files | Stage |
| --- | --- | --- | --- |
| One challan per admission, derived net | DB+BE | `create_challans_table`, `Models/Challan.php` | SHIPPED |
| Payments ledger, one row per collection | DB+BE | `create_payments_table`, `Models/Payment.php` | SHIPPED |
| Part payment with running balance | FE+BE | `Services/ChallanActions.php`, `partials/challan-drawer.blade.php` | SHIPPED |
| Overpayment refused, not clamped | BE | `Services/ChallanActions.php` | SHIPPED |
| Cancellation refused once money collected | BE | `Services/ChallanActions.php::cancel` | SHIPPED |
| Dashboard money (billed / received / outstanding) | BE | `Services/Ledger.php` | SHIPPED |
| Overdue detection against `Clock::today()` | BE | `Services/Ledger.php`, `Models/Challan.php` | SHIPPED |
| Challan list filters and row actions | FE | `pages/challans.blade.php` | SHIPPED |
| Three copy voucher PDF | FE+BE | `challans/pdf.blade.php`, `ChallanController.php` | SHIPPED |
| Concurrency safety on collection | BE | `Services/ChallanActions.php` | SHIPPED, unproven by test (RISK-01) |
| Collection refused on a cancelled registration | BE | `Services/ChallanActions.php` | SHIPPED (was BUG-27) |
| Idempotent collection (double-submit safety) | FE+BE+DB | `Services/Operations.php`, `Support/Concerns/CollectsPayments.php`, `create_operations_table` | SHIPPED (was GAP-08) |
| Receipt for a collection | — | — | **MISSING (GAP-05, open)** |
| Refund / reversal / correction | — | — | **MISSING (GAP-07, open)** |
| Cashier shift and cash reconciliation | — | — | **MISSING (GAP-06, open)** |
| One invoice billing several enrolments | DB+BE | `let_one_challan_bill_several_enrolments`, `Models/Challan.php` | SHIPPED |
| Per-course apportionment of one invoice | BE | `Support/RevenueShare.php`, `Admission::netShare()` | SHIPPED |
| Installment schedule: model and reconcile | BE | `Services/Installments.php` | SHIPPED |
| Installment schedule: a caller that creates one | BE | `Services/Import/RollPersister.php::schedule` | SHIPPED (was GAP-03) |
| Installment schedule: an operator-facing way to create one | FE | — | **MISSING (GAP-03a, open)** |

### 1.5 Reporting, analytics and exports

| Capability | Layer | Files | Stage |
| --- | --- | --- | --- |
| Period selector resolving to real dates | BE | `Support/Period.php` | SHIPPED |
| Dues ageing on the balance | BE | `Services/Reporting.php::duesAgeing` | SHIPPED (was BUG-01) |
| Daily / weekly / monthly collection series | BE | `Services/Reporting.php::collectionSeries` | SHIPPED (was BUG-01) |
| Collections by payment method | BE | `Services/Reporting.php::byPaymentMethod` | SHIPPED (was BUG-01) |
| Revenue by course | BE | `Services/Reporting.php::revenueByCourse` | SHIPPED (was BUG-01) |
| Officer performance scorecards | BE | `Services/Reporting.php::officerPerformance` | SHIPPED (was BUG-01) |
| Report export, xlsx + csv + zip of csv | BE | `ReportExportController.php`, `ReportBook.php` | SHIPPED (was BUG-01; csv/zip added 2026-08-23 for US-7.3) |
| Students CSV export | BE | `StudentExportController.php` | SHIPPED (was BUG-03) |
| Step up ticket on institute exports | BE | — | NOT BUILT (US-7.3 acceptance, see backlog) |
| Super admin institute analytics | BE | `Services/Analytics.php` | SHIPPED (was BUG-02) |
| Reconciliation assertion | BE | `Services/Analytics.php::ledger` | SHIPPED (was BUG-14) |
| Dashboard revenue trend | BE | `Services/Ledger.php::revenueTrend` | SHIPPED (was GAP-02) |
| Attendance rates per course | BE | `Services/Attendances.php::ratesByCourse` | SHIPPED (was GAP-01) |

### 1.5a Interface and responsive behaviour

Landed in `281601f` and previously unmapped.

| Capability | Layer | Files | Stage |
| --- | --- | --- | --- |
| Fluid type scale, 11 clamp() steps | FE | `resources/css/app.css` | SHIPPED |
| Permission-gated dashboard quick actions | FE+BE | `pages/dashboard.blade.php` | SHIPPED |
| `?new=1` deep links to the wizard and student form | BE | `pages/registrations.blade.php`, `pages/students.blade.php` | SHIPPED |
| Recent registrations panel, scoped, money-free | FE+BE | `pages/dashboard.blade.php` | SHIPPED |
| Tables become cards below 640px | FE | `app.css` `.table-cards`, challans + students | SHIPPED |
| Responsive sign-in screen | FE | `app.css` `.login-split`, `100dvh` | SHIPPED (was BUG-20) |
| Keyboard-navigable guardian suggestions | FE | `partials/registration-wizard.blade.php` | SHIPPED |
| Identifiers never wrap mid-token | FE | `app.css` `.rec-id` | SHIPPED (was BUG-21) |

### 1.6 Attendance

| Capability | Layer | Files | Stage |
| --- | --- | --- | --- |
| Register capture screen | FE | `livewire/pages/attendance.blade.php` | SHIPPED |
| Roster derived from live enrolments | BE | `Services/Attendances.php::roster` | SHIPPED |
| Idempotent save and correction | BE+DB | `Services/Attendances.php::record` | SHIPPED |
| One mark per student per course per day | DB | `make_attendance_marks_unique_per_student_day` | SHIPPED |
| Future dates refused | BE | `Services/Attendances.php::record` | SHIPPED |
| Per register audit row | BE | `Services/Audit.php` | SHIPPED |
| `attendance.manage` permission | BE | `Support/Permissions.php` | SHIPPED |

### 1.7 Super admin console

| Capability | Layer | Files | Stage |
| --- | --- | --- | --- |
| Separate guard, table and login | DB+BE | `create_super_admins_table`, `routes/superadmin.php` | SHIPPED |
| Mandatory TOTP | BE | `Middleware/EnsureTwoFactorEnrolled.php` | SHIPPED |
| Impersonation with attribution kept | BE | `Services/Impersonation.php` | SHIPPED |
| Append only audit trail | DB+BE | `create_audit_logs_table`, `Services/Audit.php` | SHIPPED |
| Immutability enforced in code | BE | `Models/AuditLog.php::booted` | SHIPPED |
| Tiered removal: remove vs purge | BE | `Services/RecordRemoval.php` | SHIPPED |
| Purge blocked on any collection | BE | `Services/RecordRemoval.php::purgeBlocker` | SHIPPED (was BUG-04) |
| Single use download tickets | BE | `Support/DownloadTicket.php` | SHIPPED |
| SQL and CSV backups | BE | `Services/DatabaseBackup.php` | SHIPPED |
| Platform performance screen | FE | `superadmin/performance.blade.php` | SHIPPED |

### 1.8 Database

27 migrations. Verified against the live SQLite file.

| Table | Notes | Stage |
| --- | --- | --- |
| `users` | soft deletes, 2FA columns, `must_reset_password` | SHIPPED |
| `roles`, `role_permissions` | `requires_2fa` on the role | SHIPPED |
| `super_admins` | isolated from `users` by design | SHIPPED |
| `teachers` | | SHIPPED |
| `students` | unique `cnic`, unique `student_code`, soft deletes | SHIPPED |
| `courses` | `capacity` nullable = unlimited, soft deletes | SHIPPED |
| `cohorts` | unique `(course_id, name)`, soft deletes | SHIPPED |
| `admissions` | unique `reg_no`; **unique live enrolment per (student, course)**; nullable unique `import_key` | SHIPPED |
| `challans` | unique `admission_id` still holds; it names the **anchor** enrolment. The other enrolments on a grouped invoice join back through `admissions.challan_id`, which is why the unique survived | SHIPPED |
| `payments` | `cascadeOnDelete` on `challan_id`, backfilled from the paid flag | SHIPPED |
| `installments` | written by `Services/Installments.php`, now called by the importer. Still 0 rows on the development database because no `--commit` run has happened there | SHIPPED |
| `attendances` | **unique `(course_id, student_id, session_date)`**, nullable `cohort_id` | SHIPPED |
| `audit_logs` | deliberately no FKs, polymorphic actor and subject | SHIPPED |
| `settings` | holds `next_challan_serial` under a row lock | SHIPPED |
| `app_notifications` | | SHIPPED |
| `counters` | one row per series | SHIPPED |
| `operations` | one row per completed write, keyed by `sha256(name\|actor\|token)`. Nothing reads it; the UNIQUE index rejecting the second insert IS the mechanism | SHIPPED |

### 1.8a Double-submit safety

Landed 2026-08-09. Which mechanism applies is a hierarchy, cheapest first, and
reaching for the last one where an earlier one works is a mistake:

| Tier | Mechanism | Used by |
| --- | --- | --- |
| 1 | Make the write idempotent — send the state to move **to**, never "flip" | `courses.setActive`, cohort open/close, the superadmin account toggle |
| 2 | Let a natural unique key reject the second row | courses (`code`), staff (`username`), cohorts (`course_id,name`), attendance, re-enrolment (BUG-17) |
| 3 | A client-minted token in `operations` | payments, new students, new-student registrations — the only three writes with no natural key |

### 1.9 Importing the existing roll

Landed in `c336fc3`. Four stages, each testable without the one before it.

| Capability | Layer | Files | Stage |
| --- | --- | --- | --- |
| Read the sheet by header name, not column position | BE | `Services/Import/RollReader.php` | SHIPPED |
| Normalise money, dates and phones into a typed row | BE | `Services/Import/RollReader.php`, `RollRow.php` | SHIPPED |
| Resolve course, CSR and batch from free text | BE | `Services/Import/RollResolver.php`, `config/roll-import.php` | SHIPPED |
| Reject on zero matches **and** on multiple matches | BE | `RollResolver.php` | SHIPPED |
| Reject duplicates within the same file | BE | `RollResolver::rejectDuplicatesWithinTheFile` | SHIPPED |
| Persist one row per transaction, rolled back whole | BE | `Services/Import/RollPersister.php` | SHIPPED |
| Backdate the record to the registration date | BE | `RollPersister::backdate` | SHIPPED |
| Apportion a grouped invoice across its courses | BE | `RollPersister::apportion` | SHIPPED |
| Idempotency: fingerprint + UNIQUE `admissions.import_key` | BE+DB | `RollResolver::fingerprint`, `give_admissions_an_import_key` | SHIPPED |
| Dry run by default; writing needs an explicit `--commit` | BE | `Console/Commands/ImportRoll.php` | SHIPPED |
| Rejection CSV for the operator | BE | `ImportRoll::writeRejects` | SHIPPED |
| An operator-facing screen for the import | FE | — | not built, and deliberately not planned |

**Three decisions worth not re-litigating**, because each one was reached by
checking the roll rather than by preference:

- **The base is `Original Price`, not the course catalogue fee.** Original Price
  is ≥ Discounted Price on all 478 rows with no exceptions, so it is a coherent
  base. The catalogue is not: Super Kid Camp is seeded at 15,000, and of the 32
  rows selling it only **3 bill 15,000** — 17 bill 20,000 and the remainder range
  from 10,000 to 40,000.
- **`Second Installment` never creates a `payments` row.** All 33 `Pending` rows
  have Second Installment equal to the outstanding Balance *and* Total Amount
  equal to the Advance — that is a schedule, not a collection. Treating the
  column as money received would have invented **Rs 450,688** of revenue.
- **The fingerprint is phone + course code**, deliberately not name and not
  registration date. Names are re-typed and dates get corrected; a fingerprint
  over either would let the same student in twice on the second run.

---

## Part 2: Bug register

All reproduced before fixing and verified after. Ordered by severity within each
round. BUG-27 to BUG-28 are the 2026-08-09 round; BUG-18 to BUG-21 are the
2026-08-08 round; BUG-01 to BUG-17 are the 2026-08-03 round and are kept for the
reasoning, not because they are open.

### BUG-27 Money could be collected against a cancelled registration, and no report would show it

- **Layer:** BE **Severity:** High **Status:** FIXED (2026-08-09)
- **Where:** `app/Services/ChallanActions.php::recordPayment`
- **What:** `cancel()` refuses once money has been collected. Nothing refused the
  reverse. `recordPayment()` checked only `status !== 'paid'` and
  `amount <= balance`, so a payment against a cancelled registration was written,
  audited and notified as normal.
- **Why it matters more than it looks:** every money query in `Ledger` requires
  at least one live admission, so that payment is then absent from `billed`,
  `received`, `outstanding`, `revenueByCourse`, `revenueTrend` and all of
  `Reporting`. Verified: `received` stayed at 119,000 with the payment sitting in
  the table. Cash in the drawer that no total in the system knows about — the
  exact failure `Ledger::scopedChallans`'s own docblock warns of.
- **Fix:** refuse when no admission on the invoice is live. Checked on
  `admissions`, not the anchor `admission`, so a grouped invoice stays
  collectable while any one of its courses lives; there is a test for that too.
- **Found by the security review of the GAP-08 diff, not by the change itself.**
  It was pre-existing. It surfaced because that diff promoted the two screens'
  list queries into a shared `scopedChallans()` contract described as "the
  challans this viewer may collect against", which made the gap between the two
  meanings visible.

### BUG-28 A double-click on Confirm payment showed "You do not have access to this screen"

- **Layer:** FE + BE **Severity:** Medium **Status:** FIXED (2026-08-09)
- **Where:** `confirmPay` and `confirmCancel` on Challans and Registrations
- **What:** When the first response landed before the second click — a fast
  connection, which is most of them — the dialog had already closed and `payId`
  was null. That fell through to `abort(403)`, and Livewire renders a 403 as a
  full-screen error dialog. The officer was told they lacked permission for a
  payment that had just succeeded correctly.
- **Fix:** a null id means there is nothing to confirm, so return quietly. A
  non-null id for a record outside the viewer's scope is still a 403, and there
  is a test for each.
- **Only a browser could find this.** The suite asserted `assertStatus(403)` and
  read as the guard working; the modal is client-side. Same lesson as the four
  browser-only defects in the 2026-08-08 round.

### BUG-18 The PDF icon threw a JS error and opened the drawer anyway

- **Layer:** FE **Severity:** High **Status:** FIXED (2026-08-08)
- **Where:** `resources/views/livewire/pages/challans.blade.php`, the challan
  PDF link in the row actions
- **What:** The link carried `wire:click.stop` with **no expression**. Livewire
  compiles a valueless `wire:` directive into an empty `$wire.` call, so every
  click threw `Alpine Expression Error: Unexpected token '}'` into the console
  — and because the handler died before the modifier was applied, `.stop` never
  ran. The row's `wire:click="select(...)"` fired regardless, so opening a
  voucher also opened the drawer behind it.
- **Reproduced:** in the browser, as the officer, on `BBT-CH-2026-1084`. One
  console error per click plus an unwanted drawer, every time.
- **Why the suite never caught it:** the expression is evaluated by Alpine in a
  real browser. `Livewire::test()` renders the markup and never executes it, so
  a broken client-side directive is invisible to the whole test suite.
- **Fixed by:** `@click.stop`. Stopping propagation is a browser concern with no
  server round trip, so it belongs to Alpine, not Livewire.
- **Swept:** this was the only valueless `wire:` directive in the codebase.

### BUG-19 The attendance register leaked guardian details across officer scope

- **Layer:** FE + BE **Severity:** Medium **Status:** FIXED (2026-08-08)
- **Where:** `App\Services\Attendances::roster()` is unscoped, and
  `pages/attendance.blade.php` rendered `guardian_name` for every row it returned
- **What:** Officer `aliraza` sees 6 students on the Students screen, but the
  AI-201 register listed `BBT-R26-0004 Mohsin Iqbal` — enrolled by someone else
  — together with his guardian's name. Same hole as BUG-10 in the wizard search,
  in a screen that was built after that fix landed.
- **Deliberately NOT fixed by scoping the roster.** A course taught to students
  enrolled by three officers has one register, and a register showing a third of
  the class is not a register. This is the one list in the app that is correctly
  unscoped, and it is now pinned by a test saying so.
- **Fixed by:** scoping the *contact line* instead of the roster. The whole class
  is listed and markable; the guardian name renders only for students the viewer
  could open on the Students screen. `scope.all` sees every one.
- **Tests:** `AttendanceTest::test_the_register_lists_students_the_officer_cannot_otherwise_see`,
  `::test_the_register_hides_the_guardian_of_a_student_outside_the_officers_scope`,
  `::test_an_admin_sees_every_guardian_on_the_register`

### BUG-20 The sign-in screen was unusable on a phone

- **Layer:** FE **Severity:** Medium **Status:** FIXED (2026-08-08)
- **Where:** `pages/auth/login.blade.php`, the outer two-column grid
- **What:** `grid-template-columns:1.05fr .95fr` was written **inline**, where no
  media query can reach it. At 390px the pitch panel computed to an **8px**
  column: the headline rendered a letter or two per line, the body copy ran one
  word per line, and the form was squeezed against the edge of the screen.
- **Not a regression from the fluid type scale**, which was the obvious suspect.
  Verified: the h1 computes to 38px at 390px, byte-identical to the hardcoded
  38px it replaced. The bug is as old as the screen.
- **Scope:** `login` only. Every other guest screen is a centred flex column and
  was already correct.
- **Fixed by:** moving the layout into `.login-split` / `.login-hero` in
  `app.css` and collapsing to one column below 900px, where the panel keeps the
  logo and drops the sales copy. The signed-in shell needed no change; it has
  collapsed correctly since it was built.

### BUG-21 Student codes wrapped mid-token in the challans table

- **Layer:** FE **Severity:** Low **Status:** FIXED (2026-08-08)
- **What:** `BBT-R26-0008` rendered as `BBT-R26-` above `0008`, which reads as a
  different and shorter ID. The existing `td.tnum { white-space: nowrap }` rule
  had exactly the right reasoning but could not reach an identifier nested
  *beside* a name rather than alone in a cell.
- **Fixed by:** a `.rec-id` class, applied to the code line in the challans and
  registrations tables. Deliberately not applied to every nested `.tnum`: the
  activity log's value columns are prose that legitimately wraps. Holding the
  code whole also gives the column a sane minimum width, so the name beside it
  stopped wrapping mid-person (Student column 168px → 185px).

### BUG-01 Reports and the xlsx export ignored the payments ledger

- **Layer:** BE **Severity:** High **Status:** FIXED
- **Where:** `app/Services/Reporting.php` (all aggregate methods),
  `app/Http/Controllers/ReportExportController.php`
- **What:** When part payments landed (`91acbbf`), `Ledger` was rewritten to sum
  the `payments` table. `Reporting` was not. It still read
  `challans.status = 'paid'` and summed `challans.net_amount`, so a collection
  that had not settled a challan in full was invisible, and a challan that
  finally settled dumped its entire net into the period it settled in rather
  than the periods the money actually arrived in.
- **Reproduced:** a Rs 10,000 cash advance against `BBT-CH-2026-1084` (net
  Rs 20,000), inside a rolled back transaction:

  | Surface | Before | After |
  | --- | --- | --- |
  | Dashboard (`Ledger::received`) | 129,000 | 129,000 |
  | Reports (`Reporting::summary`) | 119,000 | 129,000 |
  | Super admin (`Analytics::ledger`) | 119,000 | 129,000 |
  | Dues ageing total | 95,000 | 85,000 |
  | Cash row in the method breakdown | advance missing | advance present |

- **Why CI never caught it:** the seed data contains no part paid challan, so
  `SUM(payments.amount)` and the paid flag both read 119,000. The defect
  activated the first time an officer took an advance through the UI.
- **Fixed by:** adding `Ledger::scopedPayments()` as the single entry point, and
  deriving every `Reporting` figure from it. Collections are dated by
  `payments.received_at`, methods grouped by `payments.method`, dues aged on
  `Challan::balance()`.
- **Tests:** `ReportsTest::test_a_part_payment_is_reported_the_day_it_arrives`,
  `::test_dues_ageing_counts_the_balance_not_the_face_value`

### BUG-02 Super admin analytics carried the same stale arithmetic

- **Layer:** BE **Severity:** High **Status:** FIXED
- **Where:** `app/Services/Analytics.php`
- **What:** Identical `CASE WHEN challans.status = 'paid'` arithmetic in
  `ledger()`, `staffPerformance()`, `revenueByCourse()` and `monthlyRevenue()`.
  The owner console under reported institute revenue and overstated every
  officer's outstanding debt.
- **Fixed by:** the same principle, plus a `collectedByOfficer()` aggregate
  computed in its own pass. Joining `payments` into the main query would have
  repeated a challan once per part payment and doubled that officer's `billed`.

### BUG-03 The student CSV export contradicted the application

- **Layer:** BE **Severity:** High **Status:** FIXED
- **Where:** `app/Http/Controllers/StudentExportController.php`
- **What:** `$received` summed `net_amount` of challans flagged `paid`, so a
  student who had paid Rs 20,000 of a Rs 40,000 fee exported as owing the full
  Rs 40,000 with status `Owes`, while the drawer beside it on screen said
  Rs 20,000. This file is what goes to accounts.
- **Fixed by:** `Σ challan.balance()`, with `challan.payments` eager loaded so
  the export does not fire a query per row.

### BUG-04 Purge could destroy collected money

- **Layer:** BE + DB **Severity:** High **Status:** FIXED
- **Where:** `app/Services/RecordRemoval.php::purgeBlocker()`
- **What:** The blocker only counted challans with `status = 'paid'`. A student
  carrying part payments had no paid challan, so the purge was permitted. The
  purge hard deletes their challans, and `payments.challan_id` is
  `cascadeOnDelete`, so every collection row went with them. The audit snapshot
  covers only the student row, so the money left no trace anywhere.
- **Relationship to prior work:** `1548693` fixed exactly this hole for
  *cancellation* by switching to `hasCollections()`. The reasoning was never
  carried across to purge.
- **Fixed by:** blocking on `Σ payments > 0` for the student, and naming the
  amount at risk in the message.
- **Tests:** `SuperAdminTest::test_a_student_with_only_a_part_payment_cannot_be_purged`,
  `::test_the_purge_blocker_names_the_amount_at_risk`

### BUG-17 The same student could be enrolled on one course twice

- **Layer:** DB + BE + FE **Severity:** High **Status:** FIXED
- **Found by:** you, during QA of this session's build
- **Where:** `admissions` had no uniqueness on `(student_id, course_id)`
- **What:** Registering the same student onto the same course twice produced two
  admissions, two challans and two fees for one seat. At the counter it is an
  easy mistake: the officer is not sure the first registration saved, so they do
  it again. Reproduced: `BBT-ADM-0012` and `BBT-ADM-0013` for one student on
  SHOP-101, billed twice.
- **Fixed at three layers**, because each catches a different case:
  - **DB:** a VIRTUAL generated column `active_slot` that is `1` while the
    admission is live and `NULL` once cancelled, with
    `UNIQUE (student_id, course_id, active_slot)`. Both MySQL 8 and SQLite
    exclude NULLs from uniqueness, so any number of cancelled rows is fine and
    only one live row is allowed. Generated rather than maintained, so it cannot
    drift out of step with `status` even under a mass update that fires no model
    events. This is what holds under two officers submitting at the same moment.
  - **BE:** `RegistrationService::assertNotAlreadyEnrolled()`, so the officer
    reads "Maha Asim is already enrolled on Shopify (SHOP-101)" instead of a
    driver level integrity error, and the whole registration is refused before
    anything is written rather than part way through a multi course fan out.
  - **FE:** the wizard marks those course cards "Already enrolled" and refuses
    to select them. Verified in the browser.
- **Deliberately still allowed:** re-enrolling after a cancellation, and taking
  the course again in a later batch. The constraint is on holding two live
  enrolments at once, not on the pair ever occurring twice.
- **Migration safety:** the migration refuses to run if duplicates already exist,
  naming them, rather than guessing which of two enrolments is real. Your data
  had none.
- **Tests:** `InstituteCoreTest::test_a_student_cannot_be_enrolled_on_the_same_course_twice`,
  `::test_the_database_refuses_a_duplicate_live_enrolment`,
  `::test_a_cancelled_enrolment_frees_the_student_to_take_the_course_again`,
  `::test_the_wizard_marks_courses_the_student_already_holds`

### BUG-22 A student without a guardian could be created and then never edited

- **Layer:** FE **Severity:** High **Status:** FIXED (2026-08-08)
- **Where:** `resources/views/livewire/pages/students.blade.php::editStudent()`
- **What:** `ba24f42` made guardian and CNIC optional in the wizard. The Students
  screen assigns both into `public string` properties, so opening such a student
  for editing threw `Cannot assign null to property …::$fGuardian of type
  string` **before the form rendered**. The one screen whose job is correcting a
  record was a 500 for exactly the records most likely to need correcting.
- **Fixed by:** coalescing to `''` on load, and turning blank back into NULL on
  save.
- **Test:** `StudentManagementTest::test_a_student_registered_without_a_guardian_can_still_be_edited`

### BUG-23 The two doors that create a student disagreed about what a student is

- **Layer:** BE **Severity:** High **Status:** FIXED (2026-08-08)
- **Where:** `app/Services/StudentService.php::validate()`
- **What:** The same commit relaxed the wizard but not this validator, which
  still required a guardian and a well-formed CNIC. So even with BUG-22 fixed, a
  wizard-created student could be opened and never saved: the form demanded two
  fields the record was legitimately allowed to omit.
- **Fixed by:** making both optional here too — optional but still *checked*, so
  a half-typed CNIC is refused rather than stored — and routing both writers
  through `Contact::optional()` so blank reaches the column as NULL, never `''`.
  That rule now has one implementation instead of a closure in one service and
  nothing in the other.
- **Tests:** `::test_a_student_without_a_guardian_can_be_saved_from_the_students_form`,
  `::test_the_students_form_can_create_two_students_without_a_cnic`

### BUG-24 The phone field nagged while the number was still being typed

- **Layer:** FE **Severity:** Low **Status:** FIXED (2026-08-08)
- **Where:** `pages/registrations.blade.php::checkPhone()`
- **What:** The "complete attempt" threshold counted **characters**, at `>= 12`.
  The input is masked, so `+92 300 1234` is twelve characters carrying only nine
  of the ten digits: the error appeared with three digits still to type. That is
  precisely the nagging the rest of the method was written to avoid, so the
  guard defeated its own stated purpose.
- **Fixed by:** counting digits, at `>= 10`. `3001234567`, `03001234567` and
  `+92 300 1234567` are one number written three ways and now behave alike.
- **Test:** folded into `::test_step_one_reports_wrongness_at_once_but_emptiness_only_on_leaving`

### BUG-25 The guardian suggestion list had no way out

- **Layer:** FE **Severity:** Low **Status:** FIXED (2026-08-08)
- **Where:** `partials/registration-wizard.blade.php`
- **What:** The list closed only when the typed name became an exact match.
  Otherwise it sat open over the Cancel button and the CNIC-clash card, where a
  click picked a guardian instead of doing what was aimed at.
- **Fixed by:** Escape and click-outside dismiss it; typing brings it back.
  Dismissal is local browser state, so it is Alpine's. The list stays
  server-rendered rather than moving into a `<template>`, which races the
  Livewire morph — the mistake recorded in the build notes.

### BUG-26 The guardian lookup was unbounded and ran on every step

- **Layer:** BE **Severity:** Low **Status:** FIXED (2026-08-08)
- **What:** `guardianMatches()` had no SQL `LIMIT` — `take(5)` ran in PHP after
  hydrating every LIKE hit, so two characters against a full roll would load
  every match to display five. It also re-ran on steps 2 and 3, where the
  guardian field is not on screen and the result is discarded.
- **Fixed by:** a step-1 guard and `LIMIT 50`. Not `LIMIT 5`: de-duplication is
  per distinct guardian and happens in PHP, so five rows can collapse to one
  sibling's parent and the query has to leave the list something to work with.

### BUG-05 Registration ignored course capacity and active state

- **Layer:** BE **Severity:** Medium **Status:** FIXED
- **Where:** `app/Services/RegistrationService.php::register()`
- **What:** The service resolved courses with a bare
  `Course::whereIn('id', $courseIds)->get()`, with no `isFull()` check and no
  `is_active` filter. The wizard guarded selection, but selection and submission
  are separate requests: a course with one seat left can fill in between, and any
  caller that is not the wizard had no guard at all.
- **Fixed by:** `assertCoursesAreEnrollable()` inside the transaction, with the
  course rows held under `lockForUpdate()` so the capacity check means something.
- **Tests:** `InstituteCoreTest::test_a_full_course_refuses_further_enrolments`,
  `::test_an_inactive_course_refuses_enrolments`

### BUG-06 Cohort capacity was never enforced

- **Layer:** BE **Severity:** Medium **Status:** FIXED
- **Where:** `app/Services/Cohorts.php::openFor()`
- **What:** `Cohort::isFull()` and `seatsLeft()` existed and were rendered on the
  batches screen, but `openFor()` returned the open cohort regardless. A batch
  capped at 20 silently accepted its 21st student and the "Full" badge was
  decoration.
- **Fixed by:** returning null once the open cohort is full, so the admission is
  created unbatched rather than overfilling. The student is still enrolled on the
  course; which intake they sit in is an administrative decision the batches
  screen can already make.
- **Test:** `CohortTest::test_a_full_batch_stops_taking_students`

### BUG-07 A registration against stale course ids half succeeded

- **Layer:** BE **Severity:** Medium **Status:** FIXED
- **Where:** `app/Services/RegistrationService.php::register()`
- **What:** The guard checked the incoming array was non empty, then resolved it.
  If the ids no longer resolved, the loop body never ran: a `Student` row was
  still created, zero admissions existed, and an `AppNotification` announced an
  enrolment that had not happened.
- **Fixed by:** asserting the resolved count matches the requested count, and
  resolving courses *before* creating the student so a failed registration leaves
  no orphan person behind.
- **Test:** `InstituteCoreTest::test_a_stale_course_id_aborts_instead_of_half_registering`

### BUG-08 Concurrent collections could overshoot the balance

- **Layer:** BE **Severity:** Medium **Status:** FIXED
- **Where:** `app/Services/ChallanActions.php::recordPayment()`
- **What:** `$balance = $challan->balance()` was read *before* `DB::transaction()`
  and the challan row was never locked. Two officers collecting on the same
  challan at the same moment both read the same balance, both passed the
  `$amount > $balance` check, and both inserted.
- **Fixed by:** moving the status check and the balance read inside the
  transaction, against a row held with `lockForUpdate()`.

### BUG-09 The "Generate fee challan(s) on submit" checkbox did nothing

- **Layer:** FE + BE **Severity:** Medium **Status:** FIXED
- **Where:** `partials/registration-wizard.blade.php` line 169,
  `pages/registrations.blade.php::submit()`
- **What:** `genChallans` was a real bound property rendered on the review step,
  but `submit()` never put it in `$data` and the service created a challan
  unconditionally. Unticking it changed nothing. Confirmed inert in the browser.
- **Fixed by:** passing it through as `generate_challans` and honouring it. The
  enrolment is still real, the fee is simply not billed yet.
- **Test:** `InstituteCoreTest::test_unticking_generate_challans_enrols_without_billing`

### BUG-10 The wizard student search was not scope filtered

- **Layer:** BE **Severity:** Medium **Status:** FIXED
- **Where:** the `matches` query in `pages/registrations.blade.php`, and
  `RegistrationService::resolveStudent()`
- **What:** The search ran `Student::where(...)` with no `visibleTo()`, and
  `resolveStudent()` used a bare `findOrFail()`. An Admission Officer could
  search every student in the institute by name or CNIC and enrol any of them.
  The same component scoped `mount()` and `useExistingStudent()` correctly, so
  this was an inconsistency rather than a decision.
- **Fixed by:** scoping the search, the picked student, and the service resolve.
- **Test:** `InstituteCoreTest::test_an_officer_cannot_enrol_a_student_outside_their_scope`

### BUG-11 Float to int deprecation in the period helper

- **Layer:** BE **Severity:** Low **Status:** FIXED
- **Where:** `app/Support/Period.php`
- **What:** Carbon 3 returns a float from `diffInDays()`, so `+ 1` against an
  `int` return type emitted `Implicit conversion from float 365.9999999999884 to
  int loses precision` on every Reports page load. The value truncated correctly
  (365 for a year, 31 for July), so this was log noise today and a hard error
  under a future PHP.
- **Fixed by:** comparing start of day to start of day and casting explicitly.

### BUG-12 Every guest screen was titled "Sign in"

- **Layer:** FE **Severity:** Low **Status:** FIXED
- **Where:** `components/layouts/guest.blade.php`
- **What:** The layout fell back to `{{ $title ?? 'Sign in' }}` and no guest
  component ever passed a title, so the two factor setup screen announced itself
  as a sign in form and the super admin console carried the staff portal's name.
- **Fixed by:** deriving the title and the suffix from the route inside the
  layout, so a new guest screen cannot forget. Verified: `/login` gives
  "Sign in · Big Binary Tech", `/forgot-password` gives "Forgot password · Big
  Binary Tech", `/superadmin` gives "Sign in · Super Admin".

### BUG-13 No branded 403 or 404 screens

- **Layer:** FE **Severity:** Low **Status:** FIXED
- **What:** Navigating to a permission gated screen returned the raw framework
  "403 Forbidden" page. Enforcement was correct; only the presentation was bare.
- **Fixed by:** `resources/views/errors/403.blade.php` and `404.blade.php`, each
  resolving the identity per guard (the two portals are separate guards, and on
  a `/superadmin` route the signed in identity is a `SuperAdmin`, which
  `Nav::firstScreen()` cannot take) and offering a route back. Verified in the
  browser as the officer: "You do not have access to this screen" with a "Back to
  Dashboard" button.

### BUG-14 The reconciliation assertion was a tautology

- **Layer:** BE **Severity:** Low **Status:** FIXED
- **Where:** `app/Services/Analytics.php::ledger()`
- **What:** `'reconciles' => $billed === $received + ($billed - $received)` is
  true for every possible input. It read like an invariant check on the super
  admin dashboard and verified nothing.
- **Fixed by:** asserting `$received <= $billed`, which can actually fail.

### BUG-15 "Keep me signed in" defaulted to on

- **Layer:** FE **Severity:** Low **Status:** FIXED
- **What:** The remember box was pre-checked on a staff portal used from shared
  counter machines, which sat oddly beside an access model that treats a
  persistent token as consequential enough to clear on removal.
- **Fixed by:** defaulting it off.

### BUG-16 No sign out from the forced enrolment screen

- **Layer:** FE **Severity:** Low **Status:** FIXED
- **What:** `EnsureTwoFactorEnrolled` deliberately allows `logout` from that
  screen, but nothing there reached it. Anyone pinned to enrolment (every
  Administrator, since that role requires a second factor) had no way off it but
  clearing cookies.
- **Fixed by:** a "Sign out instead" control that posts to the correct logout
  route for the guard.

---

## Part 3: Feature gaps

### GAP-01 Attendance capture: CLOSED

- **Layer:** FE + BE + DB **Status:** BUILT
- **Was:** the `attendances` table and `App\Models\Attendance` existed with
  correct FKs, and nothing read or wrote either. No screen, no service, no
  permission key, no route, no nav entry, no reporting. The Reports page had
  previously rendered invented 92% and 78% figures against it, deleted for being
  fiction.
- **Now:**
  - `App\Services\Attendances`: roster from live enrolments, idempotent
    record/correct, per course rates.
  - `resources/views/livewire/pages/attendance.blade.php`: pick course, optional
    batch, date; mark present / absent / leave per student; bulk "all present" /
    "all absent"; live tally; re-opening a taken day loads and corrects it.
  - Migration adding `UNIQUE (course_id, student_id, session_date)` plus a
    nullable `cohort_id`. Without it, saving a register twice doubles every
    attendance percentage's denominator.
  - `attendance.manage` permission, granted to both seeded roles.
  - One audit row per register, not per student.
  - Future dates refused. Students not on the course are ignored, not marked.
  - `leave` is excluded from the rate's denominator rather than counted against
    it, so authorised absence does not punish a course.
  - 13 tests in `tests/Feature/AttendanceTest.php`.
- **Deliberately not seeded.** No fabricated attendance history was added. The
  table is empty until somebody takes a register, which is the honest state.

### GAP-02 Invented dashboard revenue history: CLOSED

- **Layer:** BE **Status:** FIXED
- **Was:** `config/institute.php` carried
  `'revenue_history' => [820000, 540000, 310000, 690000, 910000, 760000]`, and
  `Ledger::revenueTrend()` rendered those as Jan to Jun on the dashboard chart,
  with only the current month live. Six invented bars sitting beside reconciled
  totals, borrowing their credibility. The live bar was also wrong in its own
  right: it showed all time received rather than the current month, so the final
  column answered a different question from every column before it.
- **Now:** every month derived from `payments.received_at`, scoped to the viewer,
  with quiet months rendered as visible zeros. The config key is removed, with a
  comment explaining why. The chart is shorter until real history accumulates,
  which is the correct thing for it to be.

### GAP-03 Split / installment plan: CLOSED

- **Layer:** BE **Size:** Small **Status:** CLOSED 2026-08-09 in `c336fc3`
- **How it closed:** the importer calls `Installments::schedule()` for every row
  that arrives with an outstanding balance, building a one- or two-part plan from
  the roll's *Advance Payment* and *Pending Payment Due Date*. The service had
  been complete and uncalled since `896339e`; what it lacked was a caller, and the
  import is the caller the schedule was designed for.
- **What is left, tracked as GAP-03a:** there is still no way for an officer to
  offer a split plan at the counter. That is a screen, and it is a smaller and
  better-understood job now that a caller exists and its output is under test.

The reasoning that got here, kept because it records why a schedule is not
redundant beside the payments ledger:

- **The product question is settled.** The previous audit left this open asking
  whether a schedule was redundant beside the payments ledger. It is not, and
  the institute's own exported roll is the proof: it carries an *Advance
  Payment* and a *Second Installment* against a *Pending Payment Due Date*.
  `payments` is a ledger of what happened; `installments` is a schedule of what
  is supposed to happen. A challan's single `due_date` cannot express a second
  deadline, so without a schedule there is no way to answer "who owes me money
  *today*", only "who owes me money".
- **What was built** (in `896339e`, after the previous audit was written):
  `App\Services\Installments` with `schedule()`, `reconcile()` and
  `defaultPlan()`. Status is *derived* from the payments ledger rather than
  maintained beside it, so the two cannot disagree. `ChallanActions` reconciles
  on every collection, and the challan drawer and list read the schedule.
  13 tests in `tests/Feature/InstallmentTest.php`.
- **What was missing until 2026-08-09:** anything that calls `schedule()`.

### GAP-03a No counter-facing way to offer a split plan: OPEN

- **Layer:** FE **Size:** Small **Status:** OPEN (split out of GAP-03 on
  2026-08-09, when the import closed the backend half)
- **What:** `Installments::schedule()` now has a caller, but only the importer.
  An officer taking a new admission at the counter still cannot offer "half now,
  half by the 15th" — the registration wizard has no plan step and the challan
  drawer has no way to add one after the fact.
- **Required shape:** a plan step on the wizard's review screen and an "add a
  plan" action on the challan drawer, both calling the existing `schedule()`.
  No new service, no new table, no second source of truth for status —
  `reconcile()` already derives it from the ledger.

### GAP-04 No importer for the institute's existing roll: CLOSED

- **Layer:** BE **Size:** Medium **Status:** CLOSED 2026-08-09 in `c336fc3`
- **What was built:** `php artisan roll:import <file> [--commit] [--rejects=]`,
  over the four stages mapped in §1.9, with 39 tests in
  `tests/Feature/RollImportTest.php`.
- **The safety properties that were asked for, and where each is proven:**

| Property | Proof |
| --- | --- |
| Nothing is written without `--commit` | All 478 real rows dry-run, database counts unchanged before and after |
| No fuzzy matching | Zero matches and multiple matches are both rejections, `#[DataProvider('rejections')]` |
| A schedule never becomes a payment | `payments` are driven by *Total Amount* alone; the 33 `Pending` rows produce installments and no payment |
| A failed row leaves nothing behind | One transaction per row; a forced failure mid-row leaves no student, admission, challan or payment |
| Re-running is a no-op | Second run over the same fixture adds 0 students, 0 admissions, 0 challans, 0 payments, 0 installments |
| The ledger reconciles afterwards | `sum(billed) = sum(received) + sum(balance)` asserted after import |

- **The schema had been prepared for this and the loader never built.** Migration
  `2026_08_08_000001_allow_imported_students_without_cnic` exists specifically so
  an imported roll can carry "name, course, batch, phone and money, nothing
  else", and `Installments` was written against these exact columns.
- **The code is done; the data is not.** See the next entry — the importer is
  ready to run and running it is blocked on decisions only the institute can
  make.

### DATA-01 The roll cannot be imported until the institute answers ten questions

- **Layer:** Data **Size:** Small per item **Status:** OPEN, blocking the
  `--commit` run (raised 2026-08-09)

A real dry-run of `student_details_report (45).xlsx` on merged `main` (2026-08-09)
read all 478 rows and resolved **93 ready, 385 refused, 0 written**. Every
refusal is a question for the institute, not a bug in the importer. Counts are
reason instances, taken from the rejection CSV; a row can carry several, so they
sum above 385.

| Refused for | Count | The question |
| --- | --- | --- |
| Unknown course | 339 (27 distinct) | Which are new catalogue entries (DevOps, Cyber Security, UI/UX Designing) and which are levels of a course already seeded (`DMM (Level-1 Part-B)` beside `Part-A`)? The second answer changes the fee model, so this is a product decision, not a mapping chore |
| No phone number | 72 | `students.phone` is NOT NULL. Either the numbers are found, the column becomes nullable, or these rows stay out |
| Unknown CSR | 43 (6 accounts) | Do Sofia, Mariyam, Shumail Altaf, Eman Ashraf and two others get `users` rows, or does the enrolment go to an "imported" account? Attribution drives officer scoping and the performance scorecards, so a placeholder is not free |
| Instalment outstanding, no due date | 33 | A balance is owed but the roll gives no date, so nothing can say whether it is overdue. Import with a chosen date, or leave the balance unscheduled? |
| Not a course | 32 (3 distinct) | `Recovery (Batch 4)`, `Co-working Space`, `Certificate Fee (Batch# 02)` — real money against something that is not an enrolment. The system has no non-course charge, so these need one or need excluding |
| Duplicate line within the file | 28 | The same student on the same course appears two or three times (lines 10–12, 145–147, 53–54 and others). Which line is authoritative? |
| Phone is not a PK mobile | 25 (21 distinct) | Includes `.`, `Digital Media` in the phone column, and one genuine Turkish number `+905355170955`. Correct the roll, or relax the rule for foreign students |
| No course named | 21 | The Course column is empty |
| No discounted price | 2 | Nothing to bill |
| Collected more than the fee | 6 | e.g. 36,000 against 35,000, and 55,000 against 54,996. The system refuses overpayment by design (see Part 4), so each needs the fee corrected or the excess explained |

**The 93 importable rows carry received 1,544,000 and outstanding 0.** Every one
of them is fully paid, which means the first `--commit` would create **no
installments at all** — the schedule path GAP-03 just closed would not be
exercised by it. Worth knowing before reading a clean first import as proof the
schedule works; the tests are the proof, not that run.

**The order that keeps this safe:** answer the questions → re-run the dry-run
until the rejection list contains only rows that were consciously excluded →
take a backup → `--commit`. The first `--commit` against real data has not
happened and should not happen without an explicit decision and a backup taken
immediately beforehand.

---

## Part 3b: The money workflow after collection

GAP-05 to GAP-09 come from benchmarking the fee workflow against how established
POS products model the same job. The system is a good institute-management and
billing application; what it does not yet close is the operational loop **after
the cashier takes the money**:

`student → admission → fee/installment plan → challan → collect → receipt → close shift → reconcile → report`

Everything up to and including `collect` is shipped. Everything after it is
missing. Each entry below was checked against the codebase on 2026-08-08 and the
absence confirmed by search, not assumed from the map.

### GAP-05 No receipt after money is collected: OPEN

- **Layer:** FE + BE **Size:** Small **Status:** OPEN
- **Confirmed absent:** no match for `receipt` anywhere in `app/`,
  `resources/views/` or `routes/`.
- **What:** `challans/pdf.blade.php` is a three-copy **voucher** — a demand for
  payment, produced before any money moves. It is not evidence that a particular
  collection happened, and a student who pays an advance currently leaves the
  counter with nothing that says so.
- **Required shape:** an immutable document generated from a `payments` row —
  receipt number, payment id, student, challan, amount, method, who collected
  it, when, the balance left afterwards, and a link to any later reversal.
  Reprinting must reproduce the original facts rather than recompute them from
  today's ledger, or a reprint after a later payment will contradict the copy
  the student is holding.
- **Keep it small:** one route, one controller, one Blade template over the
  ledger that already exists. It must not introduce a second money table.

### GAP-06 No cashier shift or end-of-day cash reconciliation: OPEN

- **Layer:** FE + BE + DB **Size:** Medium **Status:** OPEN
- **Confirmed absent:** no `cashier_session`, `cash_movement`, `opening_balance`
  or shift concept in `app/` or `database/migrations/`.
- **What:** `Reporting::byPaymentMethod()` already answers "how much cash was
  recorded today", and its own docblock says the point is so the drawer can be
  reconciled against the Cash row. Nothing completes that thought: there is no
  session to open, no opening float, no cash in/out, no counted-cash entry at
  close, and therefore no variance.
- **Why this is the POS boundary:** the system can currently say what was
  *recorded*. It cannot say whether the money in the drawer agrees, which is the
  question an end-of-day close exists to answer and the one that catches both
  mistakes and theft.
- **Required shape:** `cashier_sessions` plus an append-only `cash_movements`;
  one open session per cashier; expected = opening + cash collections + cash in
  − refunds − cash out; counted cash entered at close; a variance requires a
  reason and a permission; a closed session is immutable.
- **GAP-08 made the case for this concrete, added 2026-08-09.** The phantom
  payment a double-click used to create was invisible to every check the database
  can perform: it reduced the outstanding balance by exactly the amount it
  invented, so `billed = received + outstanding` still held and every report
  stayed internally consistent. Two of the three near-misses caught in review
  had the same property — a failure the system reported as a success, with no
  internal witness. `Ledger::outstanding()` is `billed − received`, so that
  invariant is an identity and can never catch this class of thing at all. **A
  counted drawer is the only external witness the system could have**, and it is
  the one thing not built.

### GAP-07 No refund, reversal or payment correction: OPEN

- **Layer:** FE + BE + DB **Size:** Medium **Status:** OPEN
- **Confirmed absent:** no `refund`, `reversal` or `void` anywhere in `app/`.
- **What:** A payment entered against the wrong student, for the wrong amount,
  or by the wrong method has no correction path. The only tools are cancelling
  the admission — which `ChallanActions::cancel()` correctly refuses once money
  is collected — or editing the row by hand in the database.
- **Required invariant:** never edit or delete a collected payment. A correction
  is an append-only reversal row linked to the original, with a reason, a
  permission, and step-up for the sensitive cases. Installments, balances,
  reports and any future session totals all derive from the **net** ledger.
- **Precedent already in the codebase:** `audit_logs` is append-only and
  enforced in `AuditLog::booted()`, and `Installments::reconcile()` derives
  status from the ledger rather than maintaining it alongside. The same two
  ideas are exactly what a reversal needs.

### GAP-08 Collection has no idempotency boundary: CLOSED

- **Layer:** FE + BE + DB **Size:** Small **Status:** CLOSED 2026-08-09
- **What it was.** BUG-08 fixed two officers colliding on one challan. This was
  **one** officer's request arriving twice, and `lockForUpdate()` made it worse
  rather than better: the lock serialises the two writes so cleanly that each
  passes its own balance check and both commit. Reproduced before any fix — a
  student handing over Rs 10,000 against a Rs 20,000 fee was recorded as having
  paid the lot, with the challan flipped to **paid** and the balance at zero.
- **The exposure was precisely part payments.** Settling in full was accidentally
  safe, because the second click hit `status === 'paid'` and threw. Only amounts
  at or below half the balance got through — which, per the institute's own roll
  (an Advance and a Second Installment on every row), is the common transaction.
- **What was built:** `operations`, one row per completed write keyed by a token
  the form mints when it opens, so both halves of a double-click carry the same
  one and the second loses on a UNIQUE index. `App\Services\Operations::once()`,
  `App\Support\Concerns\GuardsDoubleSubmit` and `CollectsPayments`, 34 tests in
  `tests/Feature/DoubleSubmitTest.php`.
- **Three properties that took some getting right**, each now pinned by a test:
  - The marker and the work share one transaction, so a **failed** operation
    rolls the token back. Persisting it on failure would be worse than the bug —
    one dropped connection would lock an officer out of recording a payment that
    never happened.
  - Only a clash on the **marker** counts as a replay. The first version wrapped
    the marker and the work in one `try`, so a collision on `students.student_code`
    came back as "already on file" with no student created: a hard failure
    reported as success.
  - The stored key is `sha256(name | actor | token)`, not the token. The token
    has to be client-supplied to survive the round trip, and storing it raw made
    one global namespace of every operation — a token burnt on `student.create`
    then made `once()` report a payment as recorded, writing nothing while the
    screen said "Part payment received · Rs 5,000 · Cash".
- **`toggleActive` was a different failure with the same cause** and is now
  `setActive($id, $to)`: two clicks on a flip cancel out and leave the course as
  it was, while both toasts report a change. The superadmin account toggle had
  the same shape and now refuses if the row moved since the dialog opened.
- **Where the guard was NOT needed, and why.** Cohorts, courses, staff users and
  roles are all protected by user-supplied unique columns; attendance by
  `(course_id, student_id, session_date)`; an existing student's re-enrolment by
  BUG-17's live-enrolment index. Only payments, new students and new-student
  registrations had no natural key. Confirmed by probe, not assumed.
- **What the ledger could not tell us.** `billed = received + outstanding` holds
  perfectly with a phantom payment in place, because the phantom reduces the
  balance by exactly the amount it invents. `Ledger::outstanding()` is defined as
  `billed − received`, so the invariant is an identity and can never witness
  this. The only disagreeing party is the cash drawer — which is what GAP-06 is
  for, and the strongest argument yet for building it.

### DATA-02 Records an earlier double-click may already have created

- **Layer:** Data **Size:** Small **Status:** OPEN, nothing found so far
- `php artisan records:duplicates [--seconds=15]` reports payments, students and
  admissions written within seconds of an identical one. Run against the
  development database on 2026-08-09: **nothing found**, which is expected of a
  seeded database and proves nothing about production.
- **It reports and never deletes, deliberately.** Removing a collected payment is
  exactly what the rest of this system refuses to do — `payments` is append-only
  and `cancel()` will not touch a challan with money against it. A row that turns
  out to be phantom is corrected by an offsetting reversal, which is GAP-07's
  job, with a reason and a trail. A `--fix` that quietly deleted money rows would
  be a worse bug than the one it cleaned up after.
- **The output is candidates, not verdicts.** A student really can pay the same
  amount twice in a minute. Run it against production after deploying, and check
  each hit against what was actually collected.

### GAP-09 Backups are generated; restore is barely specified: OPEN

- **Layer:** BE + Ops **Size:** Small **Status:** OPEN
- **Partially present, and the tracker should say so:** `DEPLOYMENT.md` does
  document a restore — "Restore through phpMyAdmin → Import" — and
  `tech-stack.md` mentions restore tests. So this is thinner than it should be,
  not absent.
- **What is genuinely missing:** any evidence a backup has been restored. There
  is no restore command, no drill, no checksum, no retention policy, and no
  post-restore assertion. A backup is a hypothesis until something has been
  rebuilt from it.
- **Required shape:** restore into an empty database, then assert the same
  invariants the application already knows how to check — payments reconcile
  against challans, audit rows survive, counters resume without colliding, and
  permissions still resolve. `Analytics::ledger()` already computes the
  reconciliation assertion this would reuse.

### RISK-01 The concurrency guarantees are untested, on every driver

- **Layer:** DB + Test **Severity:** High **Status:** MUST VERIFY
- **What:** Four invariants rest on `lockForUpdate()` — serial allocation in
  `Sequences`, course capacity in `RegistrationService`, collection in
  `ChallanActions`, and the settings row holding `next_challan_serial`. **No
  test anywhere exercises two simultaneous connections.** Searching `tests/` for
  `lockForUpdate`, `DB::connection(`, `reconnect`, `pcntl` or `proc_open`
  returns nothing. Every test runs in one process on one connection, where a row
  lock is a no-op by construction.
- **This is not the SQLite problem it first looks like.** CI already runs the
  suite against **MySQL 8.4** as well as SQLite — see the matrix `include` in
  `.github/workflows/ci.yml` and commit `9df777b`, "actually run the PHP suite,
  on three versions and both drivers". Adding a MySQL job would change nothing,
  because the gap is the single-process test, not the engine underneath it. It
  is worth stating plainly, because "run it against MySQL" is the obvious
  recommendation here and it is already done.
- **What is true about SQLite:** it permits one writer at a time per database
  file, so a concurrency test written against it would prove less than the same
  test on InnoDB even if one existed. That makes the driver a reason to run such
  a test on MySQL — not a reason to believe the guarantee is currently proven
  anywhere.
- **Required proof:** a test that opens two genuinely independent connections and
  drives both at the same challan, the same course's last seat, and the same
  sequence, asserting one winner. Run it on the MySQL leg. Until that exists,
  every "concurrency safe" line in Part 1 and Part 4 of this document rests on
  code review, not on a passing test.

### Scope guard: what this deliberately is not

Recorded so the gaps above are not read as licence to grow the product.

Do **not** add inventory, restaurant tables, retail SKUs, offline financial
writes, microservices, a SPA rewrite or an accounting ERP. The lean path is the
one at the top of this section and nothing more.

Blade + Livewire + Alpine is the right stack at this scale. When a surface gets
slow, optimise it rather than replacing the stack: paginate long lists, index the
predicates that reporting and search actually use, debounce type-ahead, eager
load known relations, defer heavy dashboard panels, and queue large exports,
imports and backups while keeping validation and the dry-run response immediate.
The performance risks here are N+1 queries, unbounded searches like BUG-26 and
synchronous exports — not the framework.

If card payments arrive, use a terminal or processor abstraction and keep
cardholder data out of this application entirely; PCI DSS reaches any system that
stores, processes or transmits it, and any system that can affect one that does.

### On the benchmark itself

GAP-05 to GAP-09 were derived on 2026-08-08 from public documentation for Odoo
Point of Sale (session open/close, cash in/out, receipts, refunds), Square cash
drawer shifts, and Stripe's refund and idempotency guidance, alongside PCI DSS
and the SQLite and MySQL/InnoDB locking documentation. The comparison supplied
the *shape* of each gap; the *absence* of each one was then confirmed against
this repository by search, and those searches are quoted in each entry above.

---

## Part 4: Verified healthy

Checked and found correct, so nobody re-audits them.

**One qualification, added 2026-08-08.** Every claim below about locking and
concurrency rests on reading the code, not on a passing test — no test drives two
simultaneous connections. See RISK-01. The claims are believed correct and are
not known to be wrong; they are simply not proven by the suite, and this document
should not be read as saying they are.

- Money is integer PKR everywhere, `net_amount` derived, never client supplied.
- Discount bounded 0 to 100 server side, reason mandatory, audited.
- `Sequences` allocates every serial under `lockForUpdate()` inside a
  transaction, with UNIQUE indexes as the backstop, and previews never consume a
  number.
- The `payments` backfill migration correctly reconstructs history from the paid
  flag, so no revenue was lost when the ledger changed shape.
- Cancellation refuses once any money is collected, testing `hasCollections()`.
- Overpayment is refused rather than clamped.
- Audit rows are append only, enforced in `AuditLog::booted()`, with no foreign
  keys and snapshotted names so the trail outlives its subject.
- Purge writes its audit row, including a full record snapshot, before deleting,
  inside the same transaction.
- Impersonation keeps both identities and never launders attribution.
- `PrivilegeGuard` implements the full delegation model and protects the last
  administrator.
- The challan drawer and the record payment dialog were already fully payments
  aware: collected, balance, part payment warning and max all derived correctly.
- Officer scoping holds on the dashboard, challans list, students list, PDF
  download and both exports.
- Browser pass as the Admission Officer across dashboard, challans, students,
  registrations and attendance: no console errors, no layout breakage, correct
  permission driven nav, money blocks correctly hidden.

Two suspicions were investigated and **dropped as false positives**, recorded so
they are not re-raised:

- `Period::days()` returns the correct number of days. Only the deprecation was
  real (BUG-11).
- `installments()->update(...)` does not throw on a table without timestamps.
  `Installment::$timestamps = false`, so Eloquent never adds `updated_at`.

---

## Part 5: QA notes

To bring the app up: `php artisan serve` and `npm run dev`, giving
<http://127.0.0.1:8000> and <http://localhost:5173>. Earlier revisions of this
document said "servers are running", which was true on the afternoon it was
written and has been misleading ever since.

Demo logins (local only, gated on `APP_ENV=local` in both the template and the
action):

| Role | Username | Password | Note |
| --- | --- | --- | --- |
| Administrator | `adminansar` | `Bbt@Admin1` | role requires 2FA, lands on enrolment |
| Admission Officer | `aliraza` | `Bbt@Officer1` | no 2FA, signs straight in |

`INSTITUTE_TODAY=2026-07-15` is pinned in `.env`, so overdue math and this month
counts are measured against that date and the demo figures reproduce
(billed 214,000 = received 119,000 + outstanding 95,000).

### Changes made to the development database

**2026-08-03.** Two migrations ran (the attendance unique index and the live
enrolment constraint) and `attendance.manage` was granted to the `admin` and
`officer` roles. Both match what a fresh `migrate:fresh --seed` now produces.

**2026-08-08.** Two attendance rows were written while the register screen was
being driven, and deleted afterwards; `attendances` is back to 0. The state
reconciles: 12 students, 11 admissions, 11 challans, 6 payments, and
214,000 = 119,000 + 95,000.

`audit_logs` grew from 32 to 38 rows across that session and **cannot be cleaned
up**, because the table is append-only by design and `AuditLog::booted()`
enforces it. Six rows therefore remain: sign-ins, one "Attendance recorded" for
the register that was later deleted, and three "Two-factor failed" entries for
`adminansar` at 11:38 that are most likely a browser autofill submitting the
prefilled sign-in form. Recorded here rather than left to be discovered.

**2026-08-09.** One migration ran, `give_admissions_an_import_key` (batch 5),
adding a nullable UNIQUE column. It matches what a fresh `migrate:fresh --seed`
now produces.

**The importer has never written to this database.** After merging PR #33 and
running the real 478-row roll in dry-run, the counts are unchanged from
2026-08-08: 12 students, 11 admissions, 11 challans, 6 payments, 0 installments,
and `admissions.import_key` is null on all 11 rows. That is the dry run working
as specified, and it is the state the next person should expect to find.
`audit_logs` is at 40, two above 2026-08-08, from sign-ins during the round.

Also removed on 2026-08-09: a stray `Invoice #1077 | Huzaifa.pdf` left in the
repository root by the 2026-08-08 browser pass. It was gitignored, so it never
reached a commit. `.playwright-mcp/` still holds ~8.5 MB of screenshots and
downloaded PDFs from browser QA; it is gitignored tool output and safe to delete
whenever the space is wanted.

**2026-08-09, second part (GAP-08).** One migration ran,
`create_operations_table`. The browser verification for the double-submit guard
writes real payments, so the SQLite file was copied to a scratch directory
beforehand and **restored from that copy afterwards** rather than the rows being
deleted — which also puts `audit_logs` back, a table that cannot be cleaned up
any other way. Verified after restoring: 12 students, 11 admissions, 11 challans,
6 payments, 40 audit rows, 0 operations, and 214,000 = 119,000 + 95,000.

`php artisan records:duplicates` was run against the development database and
found nothing. That is what a seeded database should say and is not evidence
about production; see DATA-02.

### Worth exercising by hand

| What | How | Expect |
| --- | --- | --- |
| BUG-01 | Record a part payment, compare Dashboard against Reports | The same number on both |
| BUG-17 | Try to enrol a student on a course they already hold | Card reads "Already enrolled", refuses selection |
| BUG-09 | Untick "Generate fee challan(s)" on the review step | Admission created, no challan |
| BUG-13 | As the officer, visit `/reports` | Branded screen with a way back |
| BUG-16 | Sign in as `adminansar` | "Sign out instead" on the enrolment screen |
| GAP-01 | Attendance, mark a class, save, reopen the same day | Marks load back, banner says already taken |
| GAP-02 | Dashboard revenue chart | Real months only, no invented Jan to Jun |
| GAP-04 | `php artisan roll:import "student_details_report (45).xlsx"` | 93 ready, 385 refused, and the counts in §Part 5 unchanged afterwards |
| GAP-04 | Add `--rejects=/tmp/r.csv` and open the file | One line per refused row, carrying the Excel line number |
| DATA-01 | Read the rejection CSV's `reasons` column | Only the ten categories in DATA-01, nothing unexplained |
| GAP-08 | Double-click **Confirm payment** on a part payment | One payment row, no error dialog, and the toast's amount matches what you typed |
| GAP-08 | Do it again on the same challan, deliberately, from a reopened dialog | A second payment lands — the guard must not eat a real collection |
| BUG-27 | Cancel a registration, then try to collect against its challan | Refused, with a message naming the cancellation |
| BUG-28 | Double-click **Confirm payment** and watch for a full-screen error | No "You do not have access to this screen" |
| DATA-02 | `php artisan records:duplicates` after deploying | Candidates listed, nothing deleted |
