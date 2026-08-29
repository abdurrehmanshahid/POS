# Superseded

This document described a stack that was never built — PostgreSQL,
spatie/laravel-permission, Fortify, owen-it/laravel-auditing, Sentry, and a
Hetzner + Ploi host. None of it is in `composer.json`. It is kept as a stub
rather than deleted so that anyone who follows a link to it stops here instead
of provisioning the wrong database at midnight.

What was actually built, and where it is written down:

| For | Read |
| --- | --- |
| The production deployment | [DEPLOYMENT.md](DEPLOYMENT.md) |
| Provisioning a box from bare | [`deploy/provision.sh`](../deploy/provision.sh) |
| Releasing a commit | [`deploy/deploy.sh`](../deploy/deploy.sh) |
| What CI proves | [`.github/workflows/ci.yml`](../.github/workflows/ci.yml) |

The short version: Laravel 13 on PHP 8.4, Blade + Livewire 3 + Volt + Tailwind +
Vite, MySQL 8 on the same box, a hand-rolled permission catalogue, and
`sessions`/`cache`/`queue` all on the `database` driver. No Redis, no Docker, no
external services.
