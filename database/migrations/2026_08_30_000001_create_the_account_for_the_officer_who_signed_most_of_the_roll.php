<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Ali Raza — the CSR credited with 435 of the roll's 478 lines.
 *
 * ---------------------------------------------------------------------------
 * Why this exists, and why it is not a duplicate of `..._000002_...`.
 * ---------------------------------------------------------------------------
 *
 * That migration created the six officers the roll names who had no account:
 * Sofia, Mariyam, Shumail Altaf, Eman Ashraf, Iqra Ijaz and Ayaan Ali, covering
 * 43 rows between them. It did not create Ali Raza, because at the time he
 * already had an account — from `DemoDataSeeder`.
 *
 * `DemoDataSeeder` is local-and-test only. `DatabaseSeeder` guards it with
 * `app()->environment(['local', 'testing'])`, and go-live checklist **P2-09**
 * says in as many words: run *only* `RolePermissionSeeder` and
 * `SuperAdminSeeder`, by name. So on production Ali Raza does not exist.
 *
 * `admissions.enrolled_by` is a foreign key to `users`, so the resolver refuses
 * every row naming a CSR it cannot find. The effect on a production box, with a
 * dry-run measured on 2026-08-30:
 *
 *     435  CSR "Ali raza" maps to username "aliraza", which has no account
 *
 * That is 91% of the institute's roll. The import that `SHIP-READINESS.md`
 * records as "CLOSED in dev — 447/478 in" was only ever able to close because
 * the dev database had been seeded with demo data. It would not have closed on
 * production, and the failure would have arrived at the worst possible moment:
 * during the P3 bootstrap, on the box, with the counter waiting.
 *
 * The account therefore has to be created by a migration, which runs everywhere,
 * rather than by a seeder that is deliberately barred from production.
 *
 * ---------------------------------------------------------------------------
 * Same treatment as the other six: inactive, with a password nobody knows.
 * ---------------------------------------------------------------------------
 *
 * The reasoning is unchanged from `..._000002_...` and is worth not diluting:
 * the institute asked for accounts so the history can load, not for new people
 * to be able to sign in. `is_active = false` means `EnsureActiveUser` grants no
 * access at all; the password is random, hashed and immediately forgotten, so it
 * cannot be recovered; `must_reset_password = true` catches the temporary one an
 * administrator may later issue from the Staff & Roles screen. Attribution needs
 * the row, not a live session.
 *
 * If Ali Raza is a current member of staff who should be able to log in, that is
 * an administrator activating him deliberately — the same audited path as any
 * new joiner, and not something a migration should decide.
 *
 * The username is the one the resolver derives from the roll's spelling of the
 * name ("Ali raza" → `aliraza`), and it matches what `DemoDataSeeder` uses, so a
 * local database that already has him is left exactly as it is.
 */
return new class extends Migration
{
    private const USERNAME = 'aliraza';

    private const NAME = 'Ali Raza';

    private const EMAIL = 'ali.raza@bbt.edu.pk';

    public function up(): void
    {
        // Idempotent, and it never touches an account that already exists — on a
        // developer's machine `DemoDataSeeder` has usually created him with a
        // known password and an active flag, and re-running migrations must not
        // silently lock that out from under them.
        $exists = DB::table('users')
            ->where('username', self::USERNAME)
            ->orWhere('email', self::EMAIL)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('users')->insert([
            'name' => self::NAME,
            'username' => self::USERNAME,
            'email' => self::EMAIL,
            'password' => Hash::make(Str::password(32)),
            'role_id' => 'officer',
            'is_active' => false,
            'must_reset_password' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Never delete a user who has enrolled somebody: `admissions.enrolled_by`
        // points at this row, and with 435 lines credited to him that foreign key
        // is the likeliest one in the database to be pointing here.
        $id = DB::table('users')->where('username', self::USERNAME)->value('id');

        if ($id === null || DB::table('admissions')->where('enrolled_by', $id)->exists()) {
            return;
        }

        DB::table('users')->where('id', $id)->delete();
    }
};
