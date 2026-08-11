<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The six officers the roll credits with enrolments but who have no account.
 *
 * 43 rows name a CSR the system has never heard of: Sofia (13), Mariyam (11),
 * Shumail Altaf (7), Eman Ashraf (6), Iqra Ijaz (4) and Ayaan Ali (2).
 * `admissions.enrolled_by` is a foreign key to `users`, so without a row here
 * their enrolments cannot be imported at all.
 *
 * Attribution is not cosmetic, which is why these are real accounts and not one
 * shared placeholder. `Admission::scopeVisibleTo()` filters on `enrolled_by`,
 * so it decides who can see a student for the rest of that record's life, and
 * the officer scorecards count collections by the same column. Pointing all six
 * at one bucket would merge six people's books.
 *
 * ---------------------------------------------------------------------------
 * They are created INACTIVE, and with a password nobody knows.
 * ---------------------------------------------------------------------------
 *
 * The institute asked for accounts so the history can load; it has not asked
 * for six new people to be able to sign in, and an account created by a
 * migration is an account nobody consciously granted. So:
 *
 *   - `is_active = false`, which `EnsureActiveUser` treats as no access at all.
 *   - The password is a throwaway random string that is hashed and immediately
 *     forgotten. It is not printed, logged, or committed, so it cannot be
 *     recovered — the only route in is an administrator issuing a temporary
 *     password on the Staff & Roles screen, which is an existing, audited
 *     feature.
 *   - `must_reset_password = true`, so even that temporary password has to be
 *     changed on first use.
 *
 * Activating one of these is therefore a deliberate act by an administrator,
 * exactly as it would be for any new member of staff. The import works either
 * way, because attribution needs the row, not a live session.
 *
 * Names come from the roll verbatim. "Sofia" is a first name only, and if the
 * institute has two, this migration cannot tell them apart — that is a question
 * for the institute, and the account can be renamed on the Staff screen.
 */
return new class extends Migration
{
    /** username, display name, email */
    private const OFFICERS = [
        ['sofia', 'Sofia', 'sofia@bbt.edu.pk'],
        ['mariyam', 'Mariyam', 'mariyam@bbt.edu.pk'],
        ['shumailaltaf', 'Shumail Altaf', 'shumail.altaf@bbt.edu.pk'],
        ['emanashraf', 'Eman Ashraf', 'eman.ashraf@bbt.edu.pk'],
        ['iqraijaz', 'Iqra Ijaz', 'iqra.ijaz@bbt.edu.pk'],
        ['ayaanali', 'Ayaan Ali', 'ayaan.ali@bbt.edu.pk'],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::OFFICERS as [$username, $name, $email]) {
            // Idempotent, and it never touches an account that already exists:
            // re-running must not deactivate somebody the institute has since
            // activated, nor reset their password.
            $exists = DB::table('users')
                ->where('username', $username)
                ->orWhere('email', $email)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('users')->insert([
                'name' => $name,
                'username' => $username,
                'email' => $email,
                // Random, hashed, and discarded in the same expression. Nobody
                // ever holds this value, which is the point.
                'password' => Hash::make(Str::password(32)),
                'role_id' => 'officer',
                'is_active' => false,
                'must_reset_password' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Only accounts that never signed anything. Deleting a user who has
        // enrolled students would break `admissions.enrolled_by` and orphan the
        // attribution this migration exists to create.
        $usernames = array_column(self::OFFICERS, 0);

        $ids = DB::table('users')->whereIn('username', $usernames)->pluck('id');
        $signed = DB::table('admissions')->whereIn('enrolled_by', $ids)->pluck('enrolled_by')->unique();

        DB::table('users')
            ->whereIn('username', $usernames)
            ->whereNotIn('id', $signed)
            ->delete();
    }
};
