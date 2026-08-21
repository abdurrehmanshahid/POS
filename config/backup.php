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
    | Local copies are the fast restore, not the safe one: a dump sitting on the
    | same disk as the database dies with the disk. Keep a fortnight here and
    | push a copy off the box (see docs/DEPLOYMENT-ORACLE.md §7).
    |
    | A blank key falls back to the default; an explicit `0` is honoured and
    | means "never prune", which is why this is not a plain `?:`.
    |
    */

    'keep' => is_numeric(env('BACKUP_KEEP')) ? (int) env('BACKUP_KEEP') : 14,

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
