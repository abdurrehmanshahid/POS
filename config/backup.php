<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Where dumps are written
    |--------------------------------------------------------------------------
    |
    | Deliberately outside `storage/app/public`, which is web-served. A dump
    | contains every password hash, every encrypted 2FA secret and the whole
    | money ledger; a stray symlink into a public path would publish all of it.
    |
    | `?:` rather than an env() default on purpose: a key present but blank —
    | `BACKUP_PATH=` — makes env() return an empty string, not the default, and
    | dumps would be written to the filesystem root. Falling back on any empty
    | value keeps the blank line in .env.example harmless.
    |
    */

    'path' => env('BACKUP_PATH') ?: storage_path('app/backups'),

    /*
    |--------------------------------------------------------------------------
    | How many dumps to keep on the box
    |--------------------------------------------------------------------------
    |
    | This is a COUNT, not an age, and that distinction became load-bearing the
    | day the schedule went from nightly to hourly.
    |
    | At one dump a night, 14 meant a fortnight of history and the two readings
    | were interchangeable. At one dump an hour, 14 means FOURTEEN HOURS — and
    | nothing anywhere would say so. `backup:run` still succeeds, the weekly
    | restore drill still passes against a dump taken this morning, the
    | directory listing still looks reassuringly full, and yesterday has
    | quietly gone. That is precisely the shape of failure these commands exist
    | to prevent, arriving through the retention setting instead of the dump.
    |
    | Production therefore sets BACKUP_KEEP=168: seven days at one an hour. The
    | dumps are small. `deploy.sh` refuses to deploy with anything lower, and
    | `deploy:preflight` says why.
    |
    | Long retention belongs OFF the box, which is where it should live anyway —
    | see `rclone_remote` below. A dump on the same disk as the database dies
    | with the disk.
    |
    | The default stays 14 because this file is also read on a developer's
    | laptop, where the schedule is not running and 168 local dumps of a demo
    | database would be litter. Production is explicit about it in .env.
    |
    | A blank key falls back to the default; an explicit `0` is honoured and
    | means "never prune", which is why this is not a plain `?:`.
    |
    */

    'keep' => is_numeric(env('BACKUP_KEEP')) ? (int) env('BACKUP_KEEP') : 14,

    /*
    |--------------------------------------------------------------------------
    | Off-box copy
    |--------------------------------------------------------------------------
    |
    | An rclone remote, e.g. `r2:institute-backups`. This is the line between a
    | backup and a disaster-recovery plan: everything above writes to the same
    | disk the database is on, so everything above dies with that disk.
    |
    | `backup:run` copies to it after a SUCCESSFUL dump only. A failed or
    | undersized dump is deleted rather than propagated — pushing a truncated
    | archive off-box would replace the one good copy with a broken one, which
    | is worse than not copying at all.
    |
    | Blank disables the copy, with a warning on every run rather than silence.
    | The warning is the point: a box with no off-box copy should be noisy about
    | it, not quietly reassuring.
    |
    | Retention off-box is the bucket's job (a 30-day lifecycle rule), not
    | rclone's. On-box dumps are never deleted merely because the remote copy
    | succeeded; the two retentions are independent by design.
    |
    | The rclone configuration itself lives at ~institute/.config/rclone/
    | rclone.conf, mode 0600, and is NEVER committed. Losing it means losing
    | access to your own backups, so a copy belongs in the off-box secret store
    | alongside APP_KEY (docs/PRODUCTION-EMERGENCY.md).
    |
    */

    'rclone_remote' => env('BACKUP_RCLONE_REMOTE', ''),

    /*
    |--------------------------------------------------------------------------
    | The lock two dumps must not both hold
    |--------------------------------------------------------------------------
    |
    | There are three ways `backup:run` starts: the hourly scheduler, the
    | mandatory dump at the top of `deploy.sh`, and a human at a prompt. Any two
    | of them can now coincide — a deploy approved at the top of the hour is not
    | a rare event — and two writers appending to the same directory turn one
    | slow minute into a pile of half-written files.
    |
    | The lock lives HERE, inside the command, rather than in deploy.sh, because
    | a lock that only one of three invocation paths takes is not a lock. The
    | scheduler's `withoutOverlapping` is kept as well, but it only covers the
    | scheduler and it is a cache entry rather than a kernel lock: it does not
    | survive a `php artisan backup:run` typed by hand.
    |
    | Production points this at /run/institute-backup.lock, which provision.sh
    | creates via /etc/tmpfiles.d/institute.conf with the app account as owner
    | (/run is root-owned and on tmpfs, so neither a plain touch nor an
    | unprivileged create would work). The default is inside storage/ so that a
    | laptop and the test suite need no such setup.
    |
    */

    'lock' => env('BACKUP_LOCK_PATH') ?: storage_path('framework/institute-backup.lock'),

    /*
    |--------------------------------------------------------------------------
    | Smallest dump we are willing to believe
    |--------------------------------------------------------------------------
    |
    | A backup that silently produces a 0-byte file is worse than no backup,
    | because the cron job goes green and nobody looks again until the day it
    | matters. `backup:run` fails loudly below this size.
    |
    */

    'minimum_bytes' => (int) (env('BACKUP_MINIMUM_BYTES') ?: 1024),

    /*
    |--------------------------------------------------------------------------
    | Scratch database used by `backup:verify`
    |--------------------------------------------------------------------------
    |
    | The restore drill needs somewhere to restore INTO. This database is
    | created and dropped by the command on every run, so it must never be a
    | name you would mind losing.
    |
    */

    'verify_database' => env('BACKUP_VERIFY_DATABASE') ?: 'institute_restore_check',

];
