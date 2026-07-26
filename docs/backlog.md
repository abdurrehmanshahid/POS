# Delivery backlog: Institute Management System (POS)

Reconstructed from the git history of this repository so that every unit of
delivered work is visible as a story and traceable back to the commit that
delivered it.

- Repository: `GhazanfarSheikh/POS`
- Target board: Azure DevOps, organization `bigbinarytech`, project `bigbinarytech-POS`
- History covered: all 13 commits, `78d73b2` (2026-07-05) through `f37cda7` (2026-07-22)
- Totals: 8 epics, 37 stories

## How to read a story

Every story carries the same block, in the same order:

- **Tier** and **State** are recorded as *semantic* values (`epic` / `story`,
  and `done` / `in-progress` / `proposed`), never as literal Azure DevOps type
  or state names. The literal names differ per process (Basic uses To Do,
  Doing, Done; Agile uses New, Active, Resolved, Closed) and are resolved
  against the project's own metadata at creation time. This file therefore
  stays correct whichever process the project turns out to run.
- **Commits** is the traceability line and the entire point of the exercise. It
  is what lets the board and the git history be checked against each other.
- **As / I want / So that** states the story in stakeholder terms.
- **What / How / Why / Acceptance** records the change, the technique, the
  reason, and the observable condition that proves it is finished.

## Provenance and its one honest limitation

This history is **squash merged**. Pull requests #1, #2, #3, #4 and #9 each
landed as a single commit and there are no merge commits anywhere in the
history, so only post squash hashes are cited.

One commit, `c921dcb`, is a 186 file, 29,148 insertion squash containing the
entire application built to spec. Splitting it by theme is what makes this
board useful rather than a single story reading "build the product", but it
means many stories legitimately cite the same hash. To keep each one
independently checkable, every story derived from `c921dcb` also names the
**key paths** it covers. Verify such a story with:

```bash
git show --stat c921dcb -- <the paths listed on the story>
```

## Writing standard

No em dashes, en dashes, figure dashes or horizontal bars anywhere in this
file or in any work item generated from it. This is enforced mechanically
before anything is written to the board, as a hard failure rather than a
warning.

## Scope honesty

Two things a reader might expect to find here and will not:

- **Attendance capture is not implemented.** An `Attendance` model and its
  migration exist as part of the schema, but there is no capture screen and no
  attendance reporting. The reports page previously rendered hardcoded 92% and
  78% attendance figures; those were removed rather than left to look real. No
  story claims this feature.
- **Self service password reset is switched off by design**, not unfinished.
  See US-3.3.

---

## Epic 1: Repository governance and continuous integration

Establish the repository as a governed, CI gated project before any product
code lands, so that every later change arrives through a reviewed flow.

State: `in-progress` (US-1.3 is still open)

### US-1.1: Establish the repository under governance and CI

- Tier: `story`
- State: `done`
- Commits: `78d73b2`, `cd38c8d` (PR #1)

**As** the repository owner
**I want** contribution rules, templates and a CI gate in place from the first PR
**So that** no product code can land unreviewed or untested

- **What:** Created the repository and immediately added a stack agnostic CI
  workflow running on pull requests to `main`, plus `CONTRIBUTING.md`,
  `SECURITY.md`, `CHANGELOG.md`, issue and pull request templates, `CODEOWNERS`,
  Dependabot configuration, `.editorconfig`, `.gitattributes` and `.gitignore`.
- **How:** GitHub Actions for the CI gate, with branch protection on `main`
  requiring review. README documents the branch naming scheme and Conventional
  Commits.
- **Why:** Governance added after the fact is governance nobody applies
  retroactively. PR #1 was deliberately routed through the newly protected
  branch to prove the flow worked.
- **Acceptance:** A pull request to `main` triggers CI and cannot merge without
  review; the contribution and security policy documents are present at the
  repository root.

### US-1.2: Keep CI action versions current automatically

- Tier: `story`
- State: `done`
- Commits: `1d3719d` (PR #2), `f8433be` (PR #3), `f6ace58` (PR #4), `18f6b63` (PR #8)

**As** the repository owner
**I want** CI action versions bumped for me and gated by the same CI
**So that** the pipeline does not quietly rot on unmaintained action releases

- **What:** Dependabot raised and merged four action upgrades:
  `actions/setup-python` 5 to 6, `actions/checkout` 4 to 7, and
  `actions/setup-node` 4 to 6 then 6 to 7.
- **How:** The Dependabot configuration added in US-1.1, with each bump passing
  through the same reviewed, CI gated flow as a human change.
- **Why:** Pinned major versions of third party actions stop receiving fixes;
  automating the bump keeps the gate trustworthy at no ongoing cost.
- **Acceptance:** Each bump is its own merged pull request with CI green, and
  the workflow files reference the raised versions.

### US-1.3: Land the outstanding setup-python upgrade

- Tier: `story`
- State: `proposed`
- Commits: none yet (open branch `origin/dependabot/github_actions/actions/setup-python-7`)

**As** the repository owner
**I want** the final outstanding action bump reviewed and merged
**So that** no dependency upgrade is left hanging unnoticed

- **What:** A Dependabot branch raising `actions/setup-python` from 6 to 7 is
  pushed to the remote and not yet merged into `main`.
- **How:** Review the branch, confirm CI is green, merge through the standard
  flow.
- **Why:** It is the one piece of genuinely open work in this repository. A
  board that showed everything complete would be misreporting.
- **Acceptance:** The branch is merged or explicitly closed with a reason, and
  no unmerged Dependabot branch remains on the remote.

---

## Epic 2: Application platform and design system

Stand up the Laravel and Livewire Volt application, the shared interface
vocabulary every screen is built from, and the operational documentation.

State: `done`

### US-2.1: Stand up the Laravel and Livewire Volt application skeleton

- Tier: `story`
- State: `done`
- Commits: `c921dcb`
- Key paths: `artisan`, `bootstrap/`, `config/`, `composer.json`, `package.json`,
  `vite.config.js`, `tailwind.config.js`, `postcss.config.js`, `public/`,
  `storage/`, `phpunit.xml`, `routes/`

**As** a developer
**I want** a conventional, fully configured application skeleton
**So that** feature work starts on known ground rather than on setup decisions

- **What:** A complete Laravel application: bootstrap and provider wiring, the
  full `config/` set (app, auth, cache, database, filesystems, logging, mail,
  queue, services, session, dompdf), the Vite, Tailwind and PostCSS front end
  build, `public/` entry point with `.htaccess` and `robots.txt`, storage
  scaffolding and a PHPUnit configuration.
- **How:** Laravel with Livewire Volt for single file reactive pages, so screens
  are one Blade file holding both state and markup rather than a component class
  plus a template.
- **Why:** Volt keeps a screen's logic next to its markup, which suits an
  application that is mostly forms and tables over a relational core.
- **Acceptance:** `php artisan serve` boots the application, the asset pipeline
  builds, and the test suite runs.

### US-2.2: Establish the shared UI component library and application shell

- Tier: `story`
- State: `done`
- Commits: `c921dcb`
- Key paths: `resources/views/components/`, `resources/views/partials/`,
  `resources/css/app.css`, `resources/js/app.js`, `app/Support/Nav.php`,
  `app/Support/SuperNav.php`, `app/Support/Format.php`

**As** an officer using the system all day
**I want** every screen to look and behave the same way
**So that** a control learned on one page works identically on the next

- **What:** A shared component set (buttons, inputs, labels, input errors,
  modal, dropdown, nav links, icon set, avatar, pill, action message), three
  application layouts (authenticated app, guest, super admin), a drawer and
  dialog partial vocabulary, and the stylesheet underpinning them.
- **How:** Blade components with a single icon component covering the whole set,
  plus `Nav` and `SuperNav` deriving navigation from the viewer's permissions so
  the menu cannot advertise a screen the user cannot open.
- **Why:** A shared vocabulary is what keeps twelve screens from becoming twelve
  dialects, and deriving nav from permissions removes a whole class of dead link.
- **Acceptance:** Every screen renders through one of the three layouts and uses
  the shared components; navigation shows only permitted destinations.

### US-2.3: Document the stack, deployment contract and security posture

- Tier: `story`
- State: `done`
- Commits: `c921dcb`, `aca78f7` (closes issue #13)
- Key paths: `docs/tech-stack.md`, `docs/DEPLOYMENT.md`, `docs/SECURITY.md`,
  `docs/FRONTEND_CONVENTIONS.md`, `.env.example`, `README.md`

**As** whoever deploys or inherits this system
**I want** the stack, the deploy steps and the security posture written down
**So that** going to production does not depend on asking the original author

- **What:** Four documents covering the technology stack, the deployment
  procedure, the security model and the front end conventions. `.env.example`
  was later amended to state plainly which keys must change before a production
  deploy and why.
- **How:** `.env.example` names the required changes inline, including why
  `APP_DEBUG=true` on a public host is dangerous, rather than leaving the reader
  to infer it from a framework default.
- **Why:** The dangerous configuration values are exactly the ones a hurried
  deploy leaves at their development defaults.
- **Acceptance:** A reader following `docs/DEPLOYMENT.md` can deploy without
  outside help, and no key needing a production value is left unmarked in
  `.env.example`.

### US-2.4: Tighten layout density and make the grids genuinely responsive

- Tier: `story`
- State: `done`
- Commits: `3e617de`

**As** an officer working on a laptop or a phone
**I want** layouts that collapse when content stops fitting
**So that** the interface stays usable below a desktop width

- **What:** Card grids became auto fit rather than fixed column counts, a phone
  breakpoint turns the detail drawer into a full width sheet, the course card
  Deactivate control became an icon, and `wire:navigate` now draws a progress
  bar after 120ms.
- **How:** CSS auto fit grid tracks collapse on content width instead of at a
  guessed viewport breakpoint. The spelled out Deactivate label cost roughly
  90px and pushed the fee onto two lines on anything narrower than a desktop.
- **Why:** Breakpoints guessed from viewport width break whenever content
  changes; a slow query with no feedback reads as a dead click.
- **Acceptance:** Columns collapse as the window narrows rather than at fixed
  widths, the drawer becomes a full width sheet on a phone, and a navigation
  slower than 120ms shows progress.

---

## Epic 3: Authentication and account security

Prove who is at the keyboard, with a second factor, throttling and a step up
challenge in front of anything dangerous.

State: `done`

### US-3.1: Sign in with password, throttling and strength rules

- Tier: `story`
- State: `done`
- Commits: `c921dcb`
- Key paths: `resources/views/livewire/pages/auth/login.blade.php`,
  `app/Support/LoginThrottle.php`, `app/Support/PasswordStrength.php`,
  `app/Http/Middleware/EnsureActiveUser.php`, `tests/Feature/AuthSecurityTest.php`

**As** the institute
**I want** sign in resistant to guessing, and deactivation to take effect at once
**So that** a stolen or retired credential is not a way in

- **What:** Password sign in with rate limiting, a password strength policy, and
  an `active` middleware that runs on every authenticated request.
- **How:** `EnsureActiveUser` on every request rather than only at sign in, so a
  deactivated account loses its live session immediately instead of at next
  sign in.
- **Why:** Checking activation only at the door leaves an already open session
  valid for as long as the user keeps clicking, which is precisely the window
  that matters when revoking access.
- **Acceptance:** Repeated failed attempts are throttled; deactivating a signed
  in user ends their session on their next request; weak passwords are refused.

### US-3.2: Enrol and challenge a second factor

- Tier: `story`
- State: `done`
- Commits: `c921dcb`
- Key paths: `app/Services/TwoFactor.php`, `app/Services/TwoFactorChallenge.php`,
  `app/Models/Concerns/HasTwoFactorAuth.php`,
  `app/Http/Middleware/EnsureTwoFactorEnrolled.php`,
  `resources/views/livewire/pages/auth/two-factor-setup.blade.php`,
  `resources/views/livewire/pages/auth/two-factor-challenge.blade.php`,
  `tests/Feature/TwoFactorTest.php`

**As** the institute
**I want** TOTP enrolment enforced and challenged between password and session
**So that** a leaked password alone does not grant access

- **What:** TOTP enrolment with recovery, a challenge step, and middleware
  forcing enrolment before the application is reachable.
- **How:** The challenge route sits in the guest group deliberately, because the
  second factor is verified before a session exists. Enrolment is the single
  destination an un-enrolled user is permitted to reach.
- **Why:** A second factor validated after session creation is not a second
  factor, it is a screen you can navigate away from.
- **Acceptance:** A correct password alone does not reach the dashboard; an
  un-enrolled user can reach only the enrolment screen.

### US-3.3: Reset passwords by administrator issue, with self service held off

- Tier: `story`
- State: `done`
- Commits: `c921dcb`
- Key paths: `resources/views/livewire/pages/auth/set-password.blade.php`,
  `resources/views/livewire/pages/auth/forgot.blade.php`,
  `resources/views/livewire/pages/auth/reset-password.blade.php`,
  `app/Http/Middleware/RequirePasswordReset.php`, `config/institute.php`

**As** the institute
**I want** first login to force a password change, and resets to be issued by an admin
**So that** no reset path exists that cannot prove who owns the account

- **What:** A forced first login password change, administrator issued one time
  temporary passwords, and a self service reset flow present but switched off by
  default behind `INSTITUTE_SELF_SERVICE_RESET`.
- **How:** The reset route always exists so the password broker can generate
  URLs, but the component 404s while the flag is false.
- **Why:** With no mail server configured there is no way to prove someone owns
  an address, and a reset flow that cannot prove ownership is an account
  takeover form. This is a deliberate default, not an unfinished feature.
- **Acceptance:** A new user must set a password before reaching any screen;
  with the flag off the self service reset page 404s while the route still
  resolves.

### US-3.4: Require a step up challenge for dangerous actions and data egress

- Tier: `story`
- State: `done`
- Commits: `c921dcb`, `aca78f7` (closes issue #12)
- Key paths: `app/Services/StepUp.php`,
  `app/Support/Concerns/ConfirmsDangerously.php`,
  `app/Http/Controllers/SuperAdmin/BackupController.php`,
  `app/Http/Controllers/StudentExportController.php`,
  `app/Http/Controllers/ReportExportController.php`

**As** the institute
**I want** a fresh credential check in front of destructive acts and bulk exports
**So that** an unattended session cannot be used to purge or exfiltrate records

- **What:** A step up re-authentication challenge guarding destructive actions,
  extended so that database dumps and per table CSV exports require the same
  challenge as a record purge.
- **How:** A download cannot carry the challenge in its own request, so clearing
  the challenge mints a single use 60 second ticket naming one artefact, and the
  route refuses to stream without it.
- **Why:** A bulk export of student records is a data breach in the same way a
  purge is a data loss, so it earns the same friction. Bounding the ticket to one
  artefact and 60 seconds stops it becoming a general purpose bypass.
- **Acceptance:** Destructive actions and both export routes each demand a fresh
  challenge; a ticket works once, for one named artefact, and expires after 60
  seconds.

---

## Epic 4: Role based access control and privilege safety

Decide what each role may do, enforce it on the server, and stop the
permission system being used to escalate itself.

State: `done`

### US-4.1: Define roles and permissions and gate every screen server side

- Tier: `story`
- State: `done`
- Commits: `c921dcb`
- Key paths: `app/Support/Permissions.php`, `app/Models/Role.php`,
  `app/Models/RolePermission.php`, `app/Http/Middleware/EnsurePermission.php`,
  `database/seeders/RolePermissionSeeder.php`, `routes/web.php`

**As** an administrator
**I want** a single authoritative permission catalog enforced on the server
**So that** access does not depend on what the navigation happens to show

- **What:** A 17 key permission catalog grouped for the role editor, two seeded
  roles (Administrator and Admission Officer), and `permission:` middleware on
  every route.
- **How:** Access is derived from permission keys everywhere, never from role
  names, so adding a role never requires touching authorization logic. Keys are
  additive by contract: adding is safe, renaming is not.
- **Why:** Checking role names scatters policy across the codebase and breaks the
  moment a role is renamed. Hiding a screen in the nav is presentation, not
  authorization.
- **Acceptance:** Requesting a screen without its permission is refused by the
  server even when the URL is entered directly; the access matrix renders the
  full catalog in group order.

### US-4.2: Prevent privilege escalation through the permission system

- Tier: `story`
- State: `done`
- Commits: `c921dcb`
- Key paths: `app/Services/PrivilegeGuard.php`,
  `tests/Feature/PrivilegeEscalationTest.php`

**As** the institute
**I want** users unable to grant themselves or others more than they hold
**So that** `staff.manage` is not effectively a route to every permission

- **What:** A guard preventing a user from escalating their own or another
  user's privileges beyond what they themselves hold, with a dedicated test
  suite.
- **How:** Every grant is checked against the granting user's own permission
  set before it is written.
- **Why:** Without this, the permission to manage staff silently becomes the
  permission to award oneself everything else, which makes the whole catalog
  decorative.
- **Acceptance:** An attempt to grant a permission the actor does not hold is
  refused, for self and for others, and the escalation tests pass.

### US-4.3: Guard record removal behind an explicit dangerous confirmation

- Tier: `story`
- State: `done`
- Commits: `c921dcb`
- Key paths: `app/Services/RecordRemoval.php`,
  `app/Support/Concerns/ConfirmsDangerously.php`,
  `resources/views/partials/danger-dialog.blade.php`

**As** an administrator
**I want** irreversible removals to require a deliberate, typed confirmation
**So that** a misclick cannot destroy student or financial records

- **What:** A record removal service and a shared dangerous confirmation dialog
  used consistently wherever data is destroyed.
- **How:** A reusable trait so every destructive call site inherits the same
  confirmation contract rather than each screen inventing its own.
- **Why:** Inconsistent confirmation is worse than none, because users learn to
  click through whichever pattern they meet most often.
- **Acceptance:** Every destructive action routes through the dialog and cannot
  complete without the explicit confirmation.

---

## Epic 5: Institute core: students, courses and enrolment

The domain the institute actually runs on: the schema, registering a student,
the course catalog, batches, and the identifiers printed on documents.

State: `done`

### US-5.1: Model the institute domain and migrate the schema

- Tier: `story`
- State: `done`
- Commits: `c921dcb`
- Key paths: `database/migrations/`, `app/Models/`, `app/Support/Period.php`,
  `app/Support/Clock.php`, `resources/views/livewire/pages/datamodel.blade.php`

**As** a developer
**I want** the institute's entities and relationships defined once, in migrations
**So that** every feature reads the same shape of the world

- **What:** Migrations and Eloquent models for counters, roles, role
  permissions, teachers, students, courses, admissions, challans, installments,
  audit logs, attendances, settings, notifications and super admins, plus a
  data model screen rendering the ERD.
- **How:** An injectable `Clock` so "today" is a configurable value rather than
  a call to the system clock, which is what makes the demo reproducible and the
  time dependent tests deterministic.
- **Why:** Overdue challans and this month counts are measured against a date;
  pinning it lets the prototype's exact dashboard figures be reproduced.
- **Acceptance:** Migrations run clean on SQLite and MySQL; the data model screen
  renders every entity and relationship.
- **Note:** `Attendance` exists here as schema only. No capture screen or
  attendance reporting is delivered.

### US-5.2: Register a student through a guided wizard

- Tier: `story`
- State: `done`
- Commits: `c921dcb`
- Key paths: `app/Services/RegistrationService.php`,
  `resources/views/livewire/pages/registrations.blade.php`,
  `resources/views/partials/registration-wizard.blade.php`,
  `app/Models/Admission.php`

**As** an admission officer
**I want** one guided flow from student details to an issued fee challan
**So that** enrolling somebody is a single task rather than four screens

- **What:** A multi step registration wizard creating the student, the admission
  and the resulting fee challan in one flow, with the identifier to be assigned
  previewed before commit.
- **How:** A dedicated `RegistrationService` holds the transaction so the wizard
  stays presentational and the same logic is reachable from seeders and tests.
- **Why:** Registration touches three tables and money; doing it across separate
  screens invites half finished enrolments.
- **Acceptance:** Completing the wizard yields a student, an admission and a
  challan consistent with each other; abandoning it leaves no partial records.

### US-5.3: Validate registration as it is typed and surface returning students

- Tier: `story`
- State: `done`
- Commits: `3e617de`

**As** an admission officer
**I want** errors and duplicates caught while I type, not after I finish
**So that** I am not sent back through a completed form by a database error

- **What:** Step 1 validates as it is typed rather than on Continue. Phone and
  CNIC are masked into the exact shapes the server stores. A CNIC already on
  file surfaces the student behind it with an "enrol this student" action, and
  Continue is refused while the clash stands.
- **How:** Masking to the stored shape means what the officer reads back is what
  gets written, and the duplicate check runs before the unique index does.
- **Why:** Somebody returning for another course is overwhelmingly why a known
  CNIC is re-entered, so the useful response is to offer that student, not to
  reject the form. Failing at the unique index after the whole form is filled in
  is the worst possible moment to find out.
- **Acceptance:** Field errors appear during entry; a known CNIC offers the
  existing student and blocks Continue until resolved.

### US-5.4: Bound the discount so billing cannot be driven negative

- Tier: `story`
- State: `done`
- Commits: `aca78f7` (closes issue #10)

**As** the institute
**I want** the discount percentage bounded on the server
**So that** a tampered request cannot invert a fee or corrupt the money invariant

- **What:** `discount_pct` is now bounded to 0 to 100 in both
  `RegistrationService` and the wizard.
- **How:** Server side validation in the service, not only the slider in the UI.
- **Why:** The slider was the only constraint, so a tampered Livewire request
  could drive `net_amount` negative into an `unsignedInteger` column and break
  the `billed = received + outstanding` invariant.
- **Acceptance:** A request carrying a discount outside 0 to 100 is refused, and
  the billing invariant holds.

### US-5.5: Manage the student directory within the viewer's scope

- Tier: `story`
- State: `done`
- Commits: `c921dcb`, `3e617de`
- Key paths: `app/Services/StudentService.php`, `app/Models/Student.php`,
  `resources/views/livewire/pages/students.blade.php`,
  `app/Http/Controllers/StudentExportController.php`,
  `app/Support/Matcher.php`, `tests/Feature/StudentManagementTest.php`

**As** an officer
**I want** to search, view and export the students I am allowed to see
**So that** the directory answers counter questions without exposing the rest

- **What:** A searchable student directory with a detail drawer and CSV export,
  scoped by the `scope.all` permission so an officer without it sees only their
  own enrolled students. A later fix stopped the drawer rendering blank.
- **How:** The drawer shell and its contents were gated on different things (an
  open flag versus the resolved record), so when the two drifted the page
  rendered an unclosable white panel with no header and therefore no close
  button. Both now hang off the record, and `viewStudent` refuses to open for a
  student outside the viewer's scope.
- **Why:** Scope is an authorization boundary, so the open path has to enforce it
  too, not just the list query.
- **Acceptance:** Search and export respect scope; opening a student outside
  scope is refused; the drawer always renders with a working close control.

### US-5.6: Manage the course catalog and its fee structure

- Tier: `story`
- State: `done`
- Commits: `c921dcb`
- Key paths: `app/Models/Course.php`,
  `resources/views/livewire/pages/courses.blade.php`

**As** an administrator
**I want** to define courses, their fees and whether they are running
**So that** registration prices itself from the catalog

- **What:** Course creation and editing with fee structure and an activation
  state, presented as cards.
- **How:** Deactivation rather than deletion, so a course referenced by historic
  admissions and challans stays resolvable.
- **Why:** Deleting a course that issued challans would orphan financial records.
- **Acceptance:** A course can be created, edited and deactivated; a deactivated
  course stops appearing for new registrations while its history stays intact.

### US-5.7: Run courses in batches that enrolments join automatically

- Tier: `story`
- State: `done`
- Commits: `8ba9c55`

**As** an administrator
**I want** each course to run in named batches that enrolments join by themselves
**So that** the batch number printed on a fee voucher is right without hand maintenance

- **What:** A cohort is one run of one course: the same syllabus taught to a
  group who start together. This is the "Batch # 11" the legacy fee invoice
  prints and the system previously had no concept of. Adds a Batches screen
  behind a new `cohorts.manage` permission granted to Administrator only, and
  surfaces the batch on the challan drawer.
- **How:** Membership hangs off the admission, not the student, and assignment
  works in both directions: a new admission joins whichever batch of its course
  is open at the moment it is created, and opening a batch adopts every existing
  live enrolment on that course that has no batch yet. Exactly one batch per
  course is open at a time.
- **Why:** The same person can sit in Batch # 11 for Shopify and Batch # 3 for
  Web Development at once, so a `cohort_id` on students would force one of those
  to be a lie. Two open batches would make "which batch does this enrolment
  join?" depend silently on row order. Opening a batch deliberately does not
  steal students already in another one, because a student who finished Batch #
  10 is not retroactively in Batch # 11.
- **Acceptance:** A new admission lands in the open batch on creation; opening a
  batch adopts unbatched live enrolments on that course only; a second
  concurrent open batch on one course is refused; the batch appears on the
  challan drawer.

### US-5.8: Manage teaching staff, roles and permissions

- Tier: `story`
- State: `done`
- Commits: `c921dcb`
- Key paths: `app/Models/Teacher.php`, `app/Models/User.php`,
  `resources/views/livewire/pages/staff.blade.php`

**As** an administrator
**I want** to manage staff accounts and what each role may do
**So that** access follows people joining, changing role and leaving

- **What:** Staff administration covering accounts, role assignment, the
  permission matrix editor and activation state.
- **How:** The editor renders from the permission catalog in US-4.1 and writes
  through the escalation guard in US-4.2.
- **Why:** Access control is only real if changing it is routine rather than a
  code change.
- **Acceptance:** Staff can be created, assigned a role, edited and deactivated;
  permission edits are refused where they would escalate.

### US-5.9: Stamp the institute prefix on every generated identifier

- Tier: `story`
- State: `done`
- Commits: `9923abd`

**As** the institute
**I want** every generated identifier to carry the `BBT-` prefix
**So that** anything printed on a document is recognisably this institute's

- **What:** Student codes, admission numbers and challan numbers all now carry
  the prefix. Existing records are rewritten in place, including the audit log's
  `subject_label`.
- **How:** The prefix lives in `config/institute.php` rather than inside
  `Sequences`, because the seeder, the wizard's "ID to assign" preview and the
  backfill migration must produce byte for byte the same string. The wizard
  previously carried its own copy of the `sprintf` and now asks `Sequences`.
  Identifier columns are widened first: `student_code` was `string(12)` and
  `BBT-R26-0009` is exactly 12, so the prefix fit with zero headroom and the next
  year roll would have truncated. The backfill is idempotent and speaks both
  SQLite and MySQL.
- **Why:** A duplicated `sprintf` is exactly how a preview drifts from what
  actually gets assigned. `||` is string concatenation in SQLite but logical OR
  in MySQL, where the naive backfill would have written `0` into every identifier
  in the database. The rewrite has a real cost worth stating: a challan printed
  yesterday names `R26-0009` while the database now says `BBT-R26-0009`. One
  clean series everywhere was judged better than a permanent split between pre
  and post prefix records.
- **Acceptance:** New and existing identifiers all carry the prefix; the wizard
  preview matches what is assigned; the backfill is safe to run twice and
  correct on both database engines.

---

## Epic 6: Fees, challans and the ledger

Bill an enrolment, take money at the counter in more than one movement, and
print the voucher the institute already hands over.

State: `done`

### US-6.1: Issue and manage fee challans against an admission

- Tier: `story`
- State: `done`
- Commits: `c921dcb`
- Key paths: `app/Models/Challan.php`, `app/Models/Installment.php`,
  `app/Services/ChallanActions.php`, `app/Services/Ledger.php`,
  `app/Services/Sequences.php`,
  `resources/views/livewire/pages/challans.blade.php`,
  `resources/views/partials/challan-drawer.blade.php`

**As** a fee counter officer
**I want** each enrolment to produce a numbered challan I can act on
**So that** what a student owes is a record rather than a calculation

- **What:** Challan issue with installments, sequential numbering, a detail
  drawer, status tracking and a ledger reconciling billed, received and
  outstanding.
- **How:** A `Sequences` service owns identifier allocation via a counters table
  so numbering does not depend on row counts or insertion order.
- **Why:** Fee documents need stable, gapless, human quotable numbers.
- **Acceptance:** Every admission yields a uniquely numbered challan; the ledger
  reconciles `billed = received + outstanding`.

### US-6.2: Record collections as payments so a challan can be part paid

- Tier: `story`
- State: `done`
- Commits: `91acbbf`

**As** a fee counter officer
**I want** to take an advance now and the balance later
**So that** the system records what actually happens at the counter

- **What:** Collections became their own rows, each naming the amount, the
  method, who took it and when, so a challan carries its collection history
  instead of collapsing it into a single flag. `markPaid()` became
  `recordPayment()` for the full balance, so every existing caller keeps its
  behaviour.
- **How:** `challans.status` stays as a denormalised marker of "balance reached
  zero" because the status pill, the overdue query and every existing report read
  it. `Ledger::received()` and `revenueByCourse()` now sum payments rather than
  the net of challans flagged paid. The migration backfills a payment row for
  every challan already flagged paid, and the seeder does the same.
- **Why:** A challan was paid or it was not, which could not express taking an
  advance on admission and the balance later. That is also why the legacy invoice
  has Advance Payment and Balance lines this system had no way to fill in. The
  two figures agree whenever a challan was settled in one movement and diverge
  exactly when an advance has been taken. Without the backfill every historical
  revenue figure would read zero. Overpayment is refused rather than clamped,
  because money that reconciles against nothing is worse than a rejection.
- **Acceptance:** A challan accepts several payments and reports a running
  balance; `billed = received + outstanding` reconciles mid collection;
  overpayment is refused; historical revenue figures are unchanged by the
  migration.

### US-6.3: Refuse cancellation of a registration that has been paid

- Tier: `story`
- State: `done`
- Commits: `aca78f7` (closes issue #11)

**As** the institute
**I want** a paid registration to be uncancellable
**So that** banked revenue cannot silently vanish from every report

- **What:** A paid registration can no longer be cancelled.
- **How:** The cancel path now refuses when any payment exists against the
  enrolment.
- **Why:** Every money query excludes cancelled admissions, so cancelling a paid
  one silently erased revenue the institute had actually banked, from every
  report, with no refund record. Cancellation is for uncollected enrolments;
  refunds are a separate, explicit act.
- **Acceptance:** Cancelling an enrolment with any payment against it is refused;
  cancelling an uncollected one still works and reports stay reconciled.

### US-6.4: Rebuild the challan PDF as the three copy voucher

- Tier: `story`
- State: `done`
- Commits: `f37cda7`
- Key paths: `resources/views/challans/pdf.blade.php`,
  `app/Http/Controllers/ChallanController.php`

**As** a fee counter officer
**I want** the printed challan to match the voucher we already hand over
**So that** the system's output is the document the counter and bank recognise

- **What:** Student, Head Office and Campus copies side by side on one landscape
  A4 sheet, each a self contained voucher with the bank block, student details,
  fee breakdown, signature line and its own status stamp. Advance Payment and
  Balance are now real figures. A part collected challan stamps PART PAID.
- **How:** Advance is the sum of collections and balance is what remains, both
  from US-6.2. Discount prints as the percentage the counter recognises, derived
  from the stored amount rather than kept as a second number that could drift.
  Batch comes from the cohort, and the seed opens a batch on each running course
  so the line is populated in the demo. Landscape because three portrait columns
  would be 60mm wide.
- **Why:** PART PAID is neither of the two states the old single copy voucher
  could show, and the Advance and Balance lines were previously something the
  schema could not express.
- **Acceptance:** One landscape sheet carries three complete, independently
  valid copies; a part collected challan stamps PART PAID with correct advance
  and balance; the batch line is populated.

### US-6.5: Filter and act on challans directly from the list

- Tier: `story`
- State: `done`
- Commits: `3e617de`

**As** a fee counter officer
**I want** status filters and a per row PDF action on the challan list
**So that** the common questions are one click rather than a typed search

- **What:** Paid, unpaid and overdue filter chips with counts, plus a per row PDF
  download action.
- **How:** Chips over the existing status field, with counts computed alongside
  the filtered query.
- **Why:** Both were previously reachable only by typing a status into the search
  box or by opening the drawer first, which is discoverable by nobody.
- **Acceptance:** Each chip filters the list and shows an accurate count; a
  challan PDF downloads without opening the drawer.

---

## Epic 7: Reporting, analytics and exports

Turn the operational record into the figures the institute manages by.

State: `done`

### US-7.1: Show the institute's headline figures on a dashboard

- Tier: `story`
- State: `done`
- Commits: `c921dcb`
- Key paths: `resources/views/livewire/pages/dashboard.blade.php`,
  `app/Services/Analytics.php`, `resources/views/livewire/pages/settings.blade.php`,
  `app/Models/Setting.php`

**As** an administrator
**I want** enrolment and collection figures on one screen
**So that** the state of the institute is visible without running a report

- **What:** A dashboard of headline enrolment, collection and outstanding
  figures, behind `dashboard.view`, with money totals additionally gated by
  `revenue.view`. Institute settings are managed alongside.
- **How:** Figures are measured against the configurable institute "today" from
  US-5.1 so the demo reproduces the prototype's exact numbers.
- **Why:** Money visibility is a separate grant from screen visibility, so an
  officer can use the dashboard without seeing revenue.
- **Acceptance:** The dashboard renders for a permitted user; money totals are
  absent without `revenue.view`; pinning the institute date reproduces the
  documented figures.

### US-7.2: Report on revenue and enrolment across periods

- Tier: `story`
- State: `done`
- Commits: `c921dcb`
- Key paths: `app/Services/Reporting.php`, `app/Support/Period.php`,
  `resources/views/livewire/pages/reports.blade.php`,
  `tests/Feature/ReportsTest.php`

**As** an administrator
**I want** revenue and enrolment broken down and compared between periods
**So that** I can see trend rather than only today's total

- **What:** Reporting with period selection and comparison, revenue by course
  and enrolment breakdowns.
- **How:** A `Period` value type owns range arithmetic and comparison, so every
  report derives its window the same way.
- **Why:** Ad hoc date maths per report is how two reports come to disagree about
  what "this month" means. The attendance card that rendered hardcoded 92% and
  78% was removed, because inventing numbers on a financial report is worse than
  omitting them.
- **Acceptance:** Reports agree with the ledger for the same window; period
  comparison is consistent across reports; no figure is displayed that is not
  computed from data.

### US-7.3: Export reports and student records as CSV

- Tier: `story`
- State: `done`
- Commits: `c921dcb`
- Key paths: `app/Http/Controllers/ReportExportController.php`,
  `app/Http/Controllers/StudentExportController.php`

**As** an administrator
**I want** to export the figures and the directory
**So that** numbers can be worked on outside the system

- **What:** CSV export of report output and of student records, permission gated
  and, per US-3.4, behind a step up challenge.
- **How:** Exports stream and reuse the same query layer as the on screen
  reports so an export cannot disagree with the screen.
- **Why:** An export computed by a second code path is an export that drifts.
- **Acceptance:** Exports match the on screen figures for the same parameters and
  refuse to stream without a valid step up ticket.

---

## Epic 8: Super admin console and platform operations

A separate operator identity above the institute, with impersonation, audit,
backups and monitoring.

State: `done`

### US-8.1: Provide a separate super admin identity and console

- Tier: `story`
- State: `done`
- Commits: `c921dcb`
- Key paths: `app/Models/SuperAdmin.php`, `routes/superadmin.php`,
  `resources/views/livewire/superadmin/`,
  `resources/views/components/layouts/super.blade.php`,
  `app/Support/SuperNav.php`, `database/seeders/SuperAdminSeeder.php`,
  `tests/Feature/SuperAdminTest.php`

**As** the platform operator
**I want** an identity and console separate from institute users
**So that** operating the platform is not the same as being an institute admin

- **What:** A distinct super admin model, guard, sign in, two factor enrolment,
  layout and console covering dashboard, staff and students.
- **How:** A separate table, guard and route file rather than a flag on `users`.
- **Why:** A super flag on the institute user table makes every institute query a
  potential privilege leak and blurs who is accountable for an action.
- **Acceptance:** Super admin sign in is separate and two factor enrolled;
  institute users cannot reach console routes and vice versa.

### US-8.2: Impersonate an institute user for support

- Tier: `story`
- State: `done`
- Commits: `c921dcb`
- Key paths: `app/Services/Impersonation.php`

**As** the platform operator
**I want** to see the application as a given user, reversibly and on the record
**So that** a reported problem can be reproduced rather than guessed at

- **What:** Impersonation of an institute user from the console, with a clear
  return path.
- **How:** The impersonating identity is retained for the return trip and the act
  is written to the audit log in US-8.3.
- **Why:** Untracked impersonation destroys attribution: actions would appear to
  be the user's own.
- **Acceptance:** An operator can impersonate and exit back to their own
  identity; both transitions appear in the audit log.

### US-8.3: Audit every privileged action

- Tier: `story`
- State: `done`
- Commits: `c921dcb`
- Key paths: `app/Models/AuditLog.php`, `app/Services/Audit.php`,
  `resources/views/livewire/superadmin/activity.blade.php`

**As** the platform operator
**I want** an immutable record of who did what
**So that** a disputed change can be traced to an actor and a time

- **What:** An audit log capturing privileged actions with actor, subject and
  time, plus an activity screen over it.
- **How:** Entries capture a `subject_label` as free text at write time, so the
  log still reads correctly after the referenced record changes or goes away.
- **Why:** A log of foreign keys becomes unreadable exactly when it is needed
  most. This choice is why US-5.9 had to rewrite `subject_label` explicitly: it
  does not follow the key.
- **Acceptance:** Privileged actions appear in the log with actor and timestamp,
  and entries stay readable after their subject changes.

### US-8.4: Notify users in the application

- Tier: `story`
- State: `done`
- Commits: `c921dcb`
- Key paths: `app/Models/AppNotification.php`,
  `resources/views/livewire/notifications-bell.blade.php`

**As** an officer
**I want** to be told about things needing attention inside the application
**So that** notice does not depend on a mail server that is not configured

- **What:** Persisted in application notifications with a read state and a bell
  in the application shell.
- **How:** Stored in the database and rendered in the shell, deliberately not
  dependent on outbound mail.
- **Why:** The deployment has no mail server configured (see US-3.3), so in
  application delivery is the only channel that actually works.
- **Acceptance:** Notifications are raised, listed, and marked read per user.

### US-8.5: Back up and restore the database from the console

- Tier: `story`
- State: `done`
- Commits: `c921dcb`, `aca78f7` (issue #12)
- Key paths: `app/Services/DatabaseBackup.php`,
  `app/Http/Controllers/SuperAdmin/BackupController.php`,
  `resources/views/livewire/superadmin/backups.blade.php`

**As** the platform operator
**I want** to take and download a database backup
**So that** recovery does not require server access

- **What:** Backup creation, listing and download from the console, with the
  download guarded by the single use step up ticket from US-3.4.
- **How:** The service handles dump creation; the controller refuses to stream
  without a valid ticket naming that artefact.
- **Why:** A full database dump is the single highest value artefact in the
  system, so it earns the strongest gate.
- **Acceptance:** A backup can be created and listed; downloading requires a
  fresh challenge and a ticket valid for that one artefact.

### US-8.6: Monitor platform performance and activity

- Tier: `story`
- State: `done`
- Commits: `c921dcb`
- Key paths: `resources/views/livewire/superadmin/performance.blade.php`,
  `resources/views/livewire/superadmin/activity.blade.php`

**As** the platform operator
**I want** performance and activity visible in the console
**So that** a degrading system is noticed before it is reported

- **What:** Performance and activity screens in the super admin console.
- **How:** Both read existing operational data rather than adding a telemetry
  dependency.
- **Why:** A monitoring feature that needs new infrastructure to be useful tends
  not to get deployed.
- **Acceptance:** Both screens render current figures for a signed in super
  admin and are unreachable by institute users.

---

## Traceability summary

| Commit | Date | Stories |
| --- | --- | --- |
| `78d73b2` | 2026-07-05 | US-1.1 |
| `cd38c8d` | 2026-07-06 | US-1.1 |
| `1d3719d` | 2026-07-06 | US-1.2 |
| `f8433be` | 2026-07-06 | US-1.2 |
| `f6ace58` | 2026-07-06 | US-1.2 |
| `18f6b63` | 2026-07-20 | US-1.2 |
| `c921dcb` | 2026-07-22 | US-2.1, US-2.2, US-2.3, US-3.1, US-3.2, US-3.3, US-3.4, US-4.1, US-4.2, US-4.3, US-5.1, US-5.2, US-5.5, US-5.6, US-5.8, US-6.1, US-7.1, US-7.2, US-7.3, US-8.1, US-8.2, US-8.3, US-8.4, US-8.5, US-8.6 |
| `aca78f7` | 2026-07-22 | US-2.3, US-3.4, US-5.4, US-6.3, US-8.5 |
| `3e617de` | 2026-07-22 | US-2.4, US-5.3, US-5.5, US-6.5 |
| `91acbbf` | 2026-07-22 | US-6.2 |
| `8ba9c55` | 2026-07-22 | US-5.7 |
| `9923abd` | 2026-07-22 | US-5.9 |
| `f37cda7` | 2026-07-22 | US-6.4 |
| open branch | pending | US-1.3 |

Every commit in the history maps to at least one story, and every story cites at
least one commit or names the open branch it is waiting on.

## Keeping this true

Going forward, every change gets one work item, created in the project's initial
state, moved through states as it progresses, and closed with its commit hash
written onto the item. This file is updated in the same commit. A backlog that is
accurate for one day is a report; a backlog that stays accurate is an audit
trail.
