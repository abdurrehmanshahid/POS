<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Backups
|--------------------------------------------------------------------------
|
| The dump runs HOURLY, not nightly, and that is the single change here that
| the institute would actually feel. Worst-case data loss is whatever has
| happened since the last dump. Nightly made that up to twenty-four hours of
| counter transactions — a full day of cash handed over, receipts printed, and
| no record of any of it. Hourly makes it one.
|
| It buys that with the mechanism already proved every Sunday below. No binlog
| point-in-time recovery, no second restore methodology to learn under pressure,
| no new failure mode: the same command, the same artefact, the same drill,
| twenty-four times as often. Whether an hour is still too long is a question
| for after go-live, once there is real transaction volume to measure.
|
| Two things had to change with it, and both are in config/backup.php:
|
|   BACKUP_KEEP is a COUNT. At one a night, 14 was a fortnight; at one an hour
|   it is fourteen HOURS, and nothing would have said so — the command succeeds,
|   the drill passes, the directory looks full, and yesterday is gone.
|   Production sets 168, and deploy.sh refuses to release without it.
|
|   The lock moved into the command itself. `withoutOverlapping` below only
|   covers the scheduler, and the pre-deploy dump is not the scheduler.
|
| Times are the institute's, via `->timezone()`. It matters for the weekly drill
| rather than the hourly dump: the application stores UTC deliberately
| (config/app.php, and the MySQL connection's `+00:00`), so a bare
| `weeklyOn(0, '03:00')` is 03:00 UTC — 08:00 in Karachi, an hour into the
| counter's morning rather than the quiet window it was written for. Storage
| stays UTC; only the wall clock these two read is local.
|
| `withoutOverlapping` matters more than it looks, and more now than it did:
| if a dump ever runs long enough to still be going when the next fires, two
| writers appending to the same directory turn one slow hour into a pile of
| half-written files. At hourly, the window for that is twenty-four times wider.
|
| Its lock is given an explicit expiry, now 55 minutes rather than 120 — the
| expiry must be shorter than the interval, or a killed run holds the lock past
| the next tick and silently skips it. The default is 24 hours, so a run that is
| killed rather than returning — an OOM, a reboot mid-dump — would leave the
| lock held and the next backup silently skipped. A skipped event never runs, so
| `onFailure` never fires and the alert below never sounds: exactly the
| stay-green-for-months failure these commands exist to prevent.
|
*/

Schedule::command('backup:run')
    ->hourly()
    ->timezone(config('institute.timezone'))
    ->withoutOverlapping(55)
    ->onFailure(fn () => logger()->critical('Hourly database backup FAILED — the institute has no dump from this hour.'));

/*
| The drill, weekly. This is the half that closes B-05: taking a backup proves
| nothing, restoring it proves everything. Sunday 03:00 is after that morning's
| dump exists and long before anyone opens the counter, and a failure here is a
| quiet Sunday problem rather than a Monday-at-the-till one.
|
| Note what this drill still does NOT prove, and what the runbook therefore has
| to: it restores the schema and the rows and compares them against the dump's
| manifest. It does not prove anyone can log in afterwards. Every stored TOTP
| secret is encrypted with APP_KEY, so a database restored beside a lost or
| rotated APP_KEY locks out every enrolled account, /superadmin included, while
| passing this drill perfectly. Recovery is proved by a login, not by row
| counts — see docs/DEPLOYMENT.md §7.4.
*/

Schedule::command('backup:verify')
    ->weeklyOn(0, '03:00')
    ->timezone(config('institute.timezone'))
    ->withoutOverlapping(180)
    ->onFailure(fn () => logger()->critical('Weekly restore drill FAILED — the latest backup could not be replayed.'));

/*
| The only table in this database that grows without anything ever removing a
| row from it, and that nobody would notice growing.
|
| `failed_jobs` is written by the queue and read by a person, once, when they
| are investigating. Laravel never prunes it. On a healthy box it stays empty
| and this does nothing; the case it exists for is the unhealthy one — a mail
| host refusing connections, a job throwing on every attempt — where it fills
| at the rate of the failure, silently, inside the same database the fee ledger
| lives in and the same dump that has to be restorable in a hurry.
|
| Fourteen days deliberately matches BACKUP_KEEP: a failure old enough that no
| retained dump still predates it is a failure nobody is going to investigate.
|
| NOT the `jobs` table, which the worker empties itself, and NOT `audit_logs`,
| which must never be pruned by anything — it is the financial record the spec
| requires, its rows are immutable by design (see App\Models\AuditLog), and at
| roughly 1,750 rows after importing the institute's entire history it will not
| be a problem in this system's lifetime.
*/
Schedule::command('queue:prune-failed', ['--hours' => 336])
    ->weeklyOn(0, '03:30')
    ->timezone(config('institute.timezone'))
    ->withoutOverlapping(30)
    ->onFailure(fn () => logger()->warning('Pruning failed_jobs did not complete.'));
