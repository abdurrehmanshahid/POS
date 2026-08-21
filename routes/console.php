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
| Times are the institute's, via `->timezone()`. They have to be stated: the
| application stores UTC deliberately (config/app.php, and the MySQL
| connection's `+00:00`), so a bare `dailyAt('02:00')` is 02:00 UTC — 07:00 in
| Karachi, an hour into the counter's morning rather than the quiet window
| these were written for. Storage stays UTC; only the wall clock these two read
| is local.
|
| `withoutOverlapping` matters more than it looks: if a dump ever runs long
| enough to still be going when the next fires, two writers appending to the
| same directory turn one slow night into a pile of half-written files.
|
| Its lock is given an explicit expiry. The default is 24 hours, so a run that
| is killed rather than returning — an OOM, a reboot mid-dump — leaves the lock
| held and the NEXT night's backup is silently skipped. A skipped event never
| runs, so `onFailure` never fires and the alert below never sounds: exactly the
| stay-green-for-months failure these commands exist to prevent.
|
*/

Schedule::command('backup:run')
    ->dailyAt('02:00')
    ->timezone(config('institute.timezone'))
    ->withoutOverlapping(120)
    ->onFailure(fn () => logger()->critical('Nightly database backup FAILED — the institute has no dump from tonight.'));

/*
| The drill, weekly. This is the half that closes B-05: taking a backup proves
| nothing, restoring it proves everything. Sunday 03:00 is after that morning's
| dump exists and long before anyone opens the counter, and a failure here is a
| quiet Sunday problem rather than a Monday-at-the-till one.
*/

Schedule::command('backup:verify')
    ->weeklyOn(0, '03:00')
    ->timezone(config('institute.timezone'))
    ->withoutOverlapping(180)
    ->onFailure(fn () => logger()->critical('Weekly restore drill FAILED — the latest backup could not be replayed.'));
