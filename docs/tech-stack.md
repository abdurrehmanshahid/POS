# Architecture Decision Register. Institute Management System

A cost-first, performance-adequate stack for course registration, attendance,
role-based access, automatic registration validation, ID assignment, and
fee-challan generation.

**Constraints:** single institute · <2,000 users · small team · low cost first.

**Thesis:** one codebase, one server, one language. A Laravel monolith. The
alternatives below lose mostly on *cost of complexity*, not raw capability.

## The stack at a glance

| Layer          | Choice                                | Cost            |
| -------------- | ------------------------------------- | --------------- |
| Backend        | Laravel (PHP 8.3+)                     | $0 open source  |
| Frontend       | Blade + Livewire + Alpine + Tailwind  | $0 in-repo      |
| Database       | PostgreSQL (SQLite for local/tests)   | $0 on VPS       |
| Auth           | Fortify + Breeze (session)            | $0 open source  |
| Access control | spatie/laravel-permission             | $0 open source  |
| Fee challan    | barryvdh/laravel-dompdf               | $0 per doc      |
| Audit trail    | owen-it/laravel-auditing              | $0 open source  |
| Jobs & cron    | Database queue + Scheduler            | $0 no Redis     |
| Notifications  | SMS gateway + Postmark/SES            | ~$0 + per-SMS   |
| Hosting        | Hetzner/Contabo VPS + Ploi            | $6–15 + $8      |
| Backups        | spatie/laravel-backup → Backblaze B2  | ~$0.50          |
| Monitoring/CI  | Sentry (free) + GitHub Actions        | $0 free tiers   |

**Baseline running cost:** ~$15–27/month + per-SMS. All software is $0.

## Decisions

Each decision records the pick and why the alternatives lost.

### 01 · Backend. Laravel (PHP 8.3+)

The feature set is CRUD + business rules; Laravel ships ORM, validation, queues,
scheduler, and PDF handling built in, and is the cheapest local talent pool.

- **Django**, close 2nd; equally capable, slightly more assembly for RBAC/PDF/auth.
- **Node/NestJS**, a toolkit, not a framework; hand-build auth/RBAC/validation.
- **.NET**, fine, but pricier hosting and a smaller local hiring pool.
- **Rails**, comparable, but a thinner local talent market.

### 02 · Frontend. Blade + Livewire + Alpine + Tailwind

One codebase, one deploy. Livewire gives live validation and reactive fields
without a separate API. Best for CRUD-heavy internal screens.

- **Inertia + Vue/React**, close 2nd; single-repo but adds a JS layer you don't need yet.
- **React/Vue SPA**, two codebases + an API; needs a frontend specialist.
- **HTMX**, similar idea, but Livewire is the first-party path.

### 03 · Database. PostgreSQL

Transactions, foreign keys, and unique constraints guarantee the registration↔challan
integrity. Stricter SQL, better reporting joins, JSONB headroom.

- **MySQL/MariaDB**, close 2nd; equally adequate at this scale.
- **SQLite**, used for local dev/tests; weak under concurrent writes for production.
- **MongoDB**, trades away the relational constraints that are the whole point.

### 04 · Auth. Fortify + Breeze (session)

Breeze scaffolds editable auth code (needed for 4-role redirects); Fortify adds
optional TOTP 2FA for Admins. Credentials stay in your DB.

- **Jetstream**, heavier/more opinionated than a 4-role tool needs.
- **Sanctum**, for SPA/mobile tokens; unnecessary in a monolith.
- **Auth0/Clerk**, recurring per-user cost + offsite login data.

### 05 · Access control, spatie/laravel-permission

Enforced at route, controller, and Blade layers (hiding a button alone is not
security). Fine-grained permissions like `approve-discount`, `mark-attendance`.

- **silber/bouncer**, solid; Spatie wins on adoption/docs.
- **Hand-rolled**, reinvents a solved problem.
- **External IAM**, recurring cost + network call per check.

### 06 · Fee challan PDF, barryvdh/laravel-dompdf

Blade → PDF in pure PHP, rendered from the same record, so the printed ID can
never drift from the registration. No binary, no per-doc fee.

- **Snappy/wkhtmltopdf**, browser-grade CSS, but needs a system binary. Upgrade path.
- **Browsershot**, memory-heavy; overkill on a small VPS.
- **PDF SaaS**, per-doc cost + offsite financial data.
- **jsPDF**, client-side, disconnected from the DB source of truth.

### 07 · Audit trail, owen-it/laravel-auditing

Money-touching changes (discounts, fees) need old→new history. Not covered by
RBAC (who *may*) or backups (restore state, not history).

- **spatie/activitylog**, close 2nd; more manual for field-diff auditing.
- **Custom / nothing**, inconsistent or untraceable; unacceptable for money.

### 08 · Jobs & cron. Database queue + Scheduler

Offloads slow work (email/SMS, PDF) with retries; no Redis needed at this scale.
One cron line runs all recurring tasks.

> Concurrency safety (unique IDs, seat capacity) comes from **DB transactions +
> row locking + unique constraints**, not the queue. The queue only offloads slow work.

- **Redis + Horizon**, faster + dashboard, but adds a service. Later.
- **SQS**, usage-priced + external dependency.
- **Synchronous**, makes users wait, loses retries.

### 09 · Notifications. SMS gateway + Postmark/SES

SMS reaches parents more reliably than email in-region; email for receipts.
Both plug into Laravel's Notification system.

- **Twilio**, great, but higher per-SMS than a local gateway for one country.
- **Email-only / in-app-only**, poor reach for fee reminders.

### 10 · Hosting. VPS (Hetzner/Contabo) + Ploi

Flat $6–15/mo for <2,000 users; Ploi ($8) automates SSL, deploys, queue/cron.
Whole stack on one box; scale vertically.

- **Shared hosting**, no queue workers/root; false economy.
- **Vapor / PaaS**, usage-priced; more moving parts than needed.
- **Kubernetes**, massive overhead for a single-server workload.

### 11 · Backups, spatie/laravel-backup → Backblaze B2

Encrypted, offsite, nightly, monitored, with a retention policy and periodic
restore tests. ~$0.50/mo.

- **Provider snapshots**, coarse safety net; layer on top.
- **Same-server backup**, dies with the server.
- **S3**, works, but B2 is cheaper per GB.

### 12 · Monitoring & CI. Sentry (free) + GitHub Actions

Know when something breaks before a parent reports it. Tests on every push +
automated deploy, at $0.

- **Self-hosted Flare/Sentry**, data stays in-house, but adds a service.
- **No monitoring**, silent failures on money flows; unacceptable.

## How the stack meets each requirement

| Requirement                        | How it's guaranteed                                                        |
| ---------------------------------- | -------------------------------------------------------------------------- |
| Registration ID assignment/consistency | Transactional counter + UNIQUE index; challan reads ID via relation    |
| Automatic registration validation  | Livewire (instant) + queued jobs (prerequisites, seat capacity)            |
| Fee challan + discount field       | Rendered from the record; discount audited old→new; `approve-discount` gate |
| Role-based access · 4 roles        | spatie/permission at route + controller + Blade                            |
| Attendance of students             | Livewire tables, permission-scoped to the teacher's classes                |
| Financial accountability           | Audit trail + encrypted offsite backups + optional Admin 2FA               |

## Accepted tradeoffs

- **Single server = single point of failure.** Backups protect data, not uptime;
  rebuild from backup in ~1–3h. Add a replica only if downtime becomes costly.
- **"Real-time" is near-real-time.** Livewire uses normal requests, not websockets.
- **DomPDF has limited CSS** (no flexbox/grid). Fine for challans; Snappy is the upgrade.
- **No native mobile app.** Responsive web; add Sanctum + a native client later if needed.
