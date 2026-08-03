# Project tracker: Institute Management System (POS)

Living map of what exists, which layer owns it, what stage it is at, and what is
still open. Built by reading every service, model, migration and screen in the
repository, running the suite, querying the live SQLite database, and driving the
running app in a browser.

- Branch: `fix/cancel-guard-and-login-scope`, audited from `59c116c`
- Date: 2026-08-03
- Suite: **158 passed, 478 assertions, 0 failed** (131 before this session)
- Style: `vendor/bin/pint` clean
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

| | Count |
| --- | --- |
| Defects found | 17 |
| Defects fixed this session | 17 |
| Defects still open | 0 |
| Feature gaps found | 3 |
| Feature gaps closed | 2 |
| Feature gaps open (needs your decision) | 1 (GAP-03) |
| Tests added | 24 |

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
| Concurrency safety on collection | BE | `Services/ChallanActions.php` | SHIPPED (was BUG-08) |
| Split / installment plan | DB | `create_installments_table` | SCHEMA-ONLY (GAP-03, open) |

### 1.5 Reporting, analytics and exports

| Capability | Layer | Files | Stage |
| --- | --- | --- | --- |
| Period selector resolving to real dates | BE | `Support/Period.php` | SHIPPED |
| Dues ageing on the balance | BE | `Services/Reporting.php::duesAgeing` | SHIPPED (was BUG-01) |
| Daily / weekly / monthly collection series | BE | `Services/Reporting.php::collectionSeries` | SHIPPED (was BUG-01) |
| Collections by payment method | BE | `Services/Reporting.php::byPaymentMethod` | SHIPPED (was BUG-01) |
| Revenue by course | BE | `Services/Reporting.php::revenueByCourse` | SHIPPED (was BUG-01) |
| Officer performance scorecards | BE | `Services/Reporting.php::officerPerformance` | SHIPPED (was BUG-01) |
| Multi sheet xlsx report export | BE | `ReportExportController.php` | SHIPPED (was BUG-01) |
| Students CSV export | BE | `StudentExportController.php` | SHIPPED (was BUG-03) |
| Super admin institute analytics | BE | `Services/Analytics.php` | SHIPPED (was BUG-02) |
| Reconciliation assertion | BE | `Services/Analytics.php::ledger` | SHIPPED (was BUG-14) |
| Dashboard revenue trend | BE | `Services/Ledger.php::revenueTrend` | SHIPPED (was GAP-02) |
| Attendance rates per course | BE | `Services/Attendances.php::ratesByCourse` | SHIPPED (was GAP-01) |

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

22 migrations. Verified against the live SQLite file.

| Table | Notes | Stage |
| --- | --- | --- |
| `users` | soft deletes, 2FA columns, `must_reset_password` | SHIPPED |
| `roles`, `role_permissions` | `requires_2fa` on the role | SHIPPED |
| `super_admins` | isolated from `users` by design | SHIPPED |
| `teachers` | | SHIPPED |
| `students` | unique `cnic`, unique `student_code`, soft deletes | SHIPPED |
| `courses` | `capacity` nullable = unlimited, soft deletes | SHIPPED |
| `cohorts` | unique `(course_id, name)`, soft deletes | SHIPPED |
| `admissions` | unique `reg_no`; **unique live enrolment per (student, course)** | SHIPPED |
| `challans` | unique `admission_id` enforces one per admission | SHIPPED |
| `payments` | `cascadeOnDelete` on `challan_id`, backfilled from the paid flag | SHIPPED |
| `installments` | no writer | SCHEMA-ONLY (GAP-03) |
| `attendances` | **unique `(course_id, student_id, session_date)`**, nullable `cohort_id` | SHIPPED |
| `audit_logs` | deliberately no FKs, polymorphic actor and subject | SHIPPED |
| `settings` | holds `next_challan_serial` under a row lock | SHIPPED |
| `app_notifications` | | SHIPPED |
| `counters` | one row per series | SHIPPED |

---

## Part 2: Bug register

All 17 reproduced before fixing and verified after. Ordered by severity.

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

### GAP-03 Split / installment plan: OPEN, needs your decision

- **Layer:** DB **Size:** Small **Status:** OPEN
- `challans.plan` has a `split` value and `installments` is modelled, but nothing
  creates installment rows and no UI offers the choice. Every challan is `full`.
  The settle path (`installments()->update(...)`) was tested and does work, so
  the gap is purely creation and UI.
- **Left open on purpose, because it may be redundant.** `installments` predates
  the `payments` ledger. Part payments already let a student pay a fee in stages,
  with a running balance and a row per handover, which is strictly more flexible
  than two fixed installments. The open question is whether the institute needs
  *scheduled* installments with their own due dates (a genuine feature payments
  do not cover) or whether `installments` is superseded and should be deleted.
  That is a product call, not a defect, so it is yours rather than something to
  silently build or silently drop.

---

## Part 4: Verified healthy

Checked and found correct, so nobody re-audits them:

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

Servers are running:

- Application: <http://127.0.0.1:8000>
- Vite dev server: <http://localhost:5173>

Demo logins (local only, gated on `APP_ENV=local` in both the template and the
action):

| Role | Username | Password | Note |
| --- | --- | --- | --- |
| Administrator | `adminansar` | `Bbt@Admin1` | role requires 2FA, lands on enrolment |
| Admission Officer | `aliraza` | `Bbt@Officer1` | no 2FA, signs straight in |

`INSTITUTE_TODAY=2026-07-15` is pinned in `.env`, so overdue math and this month
counts are measured against that date and the demo figures reproduce
(billed 214,000 = received 119,000 + outstanding 95,000).

### Two changes were made to your development database

Both were required for the new work to be reachable, and both match what a fresh
`migrate:fresh --seed` now produces:

1. Two migrations ran: the attendance unique index, and the live enrolment
   constraint.
2. `attendance.manage` was granted to the `admin` and `officer` roles. The
   seeder does this for new installs; your database predated the key.

Attendance marks written while testing the screen were deleted afterwards, so
the table is empty.

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
