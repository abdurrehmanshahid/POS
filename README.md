# Big Binary Tech. Institute Management System

A staff-only portal for student registration, course catalog, fee challans,
payments, staff/role administration, reporting and audit.

## Status

![CI](https://github.com/GhazanfarSheikh/POS/actions/workflows/ci.yml/badge.svg)

## Overview

Students are assigned a unique identifier on enrolment, `R26-####` for regular
courses and `T26-####` for tracks, and every action in the system is scoped by
permission and signed under the acting user's name. Money is always **derived**,
never hand-typed, and every change to it writes an immutable audit row.

**Stack:** Laravel 13 · Livewire 3 (Volt) · Alpine · MySQL 8 / MariaDB ·
hand-written design system. PDF challans via dompdf.

### Two portals

|  | Staff portal | Super admin |
| --- | --- | --- |
| URL | `/login` | `/superadmin` |
| Who | Administrators & Admission Officers | the platform owner |
| Access | 16 permission keys, least-privilege | unconditional, TOTP-gated |

The super admin lives in its own table behind its own auth guard, so the
institute's own administrators cannot see, edit or delete it. It can reset any
password, reset two-factor, remove or permanently purge records, view per-officer
performance analytics, read the full activity log, impersonate a staff member for
support, and download a database backup.

**Two-factor** is Google Authenticator (TOTP, RFC 6238), free, offline, no SMS
and no vendor. Mandatory for the super admin and for the Administrator role;
required for every destructive action.

## Getting started

```bash
git clone https://github.com/GhazanfarSheikh/POS.git
cd POS

composer install
npm ci && npm run build

cp .env.example .env
php artisan key:generate
php artisan migrate:fresh --seed      # prints the super admin password ONCE
php artisan serve
```

Demo staff logins (local environment only, the buttons and the server actions
are both gated on `APP_ENV=local`):

| Role | Username | Password |
| --- | --- | --- |
| Administrator | `adminansar` | `Bbt@Admin1` |
| Admission Officer | `aliraza` | `Bbt@Officer1` |

`INSTITUTE_TODAY=2026-07-15` in `.env` pins "today" so the demo reproduces the
prototype's exact figures (billed 214,000 = received 119,000 + outstanding
95,000). **Leave it blank in production** or overdue dates freeze.

```bash
php artisan test        # 86 tests
vendor/bin/pint         # code style
```

## Documentation

| Document | Contents |
| --- | --- |
| [docs/SECURITY.md](docs/SECURITY.md) | Access model, the holes that were closed, what is deliberately still open |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | cPanel / MySQL deployment, backups, cron, email |
| [docs/FRONTEND_CONVENTIONS.md](docs/FRONTEND_CONVENTIONS.md) | Blade, Livewire and design-system conventions |

## Development workflow

1. Create a branch off `main` using the naming convention below.
2. Open a pull request early; keep it focused and small.
3. Ensure CI is green and at least one approval is granted.
4. Resolve all review conversations, then squash-merge.

### Branch naming

| Prefix | Purpose |
| ----------- | ------------------------------ |
| `feature/` | New functionality |
| `fix/` | Bug fixes |
| `chore/` | Tooling, deps, housekeeping |
| `docs/` | Documentation only |
| `refactor/` | Internal changes, no behaviour |
| `hotfix/` | Urgent production fixes |

### Commits

This project uses [Conventional Commits](https://www.conventionalcommits.org/):

```text
feat(cart): add split-tender payment support
fix(receipt): correct tax rounding on discounts
```

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) and [SECURITY.md](SECURITY.md).

## License

Copyright © 2026 Ghazanfar Sheikh. All rights reserved.

This is proprietary software. No part of this codebase may be copied, modified,
distributed, or used without prior written permission from the copyright holder.
