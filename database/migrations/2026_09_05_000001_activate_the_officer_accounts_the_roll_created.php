<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Switch on the seven officer accounts the roll import created.
 *
 * ---------------------------------------------------------------------------
 * What went wrong, and why a migration is the fix.
 * ---------------------------------------------------------------------------
 *
 * `..._000002_create_accounts_for_the_officers_named_in_the_roll` and
 * `..._000001_create_the_account_for_the_officer_who_signed_most_of_the_roll`
 * created seven accounts — Ali Raza, Sofia, Mariyam, Shumail Altaf, Eman
 * Ashraf, Iqra Ijaz and Ayaan Ali — so that `admissions.enrolled_by` had
 * somebody to point at for all 478 lines of the roll. They were seeded
 * `is_active = false` on the reasoning that the institute had asked for
 * attribution, not for new people to be able to sign in, and that an
 * administrator would switch on whoever was still employed.
 *
 * On the screen there was no way to tell those rows apart from working logins.
 * An administrator issued a temporary password to one of them during a client
 * demonstration; the account saved, the sign-in was refused, and the login
 * screen said "Incorrect username or password" — which was false, and pointed
 * at the one thing that was not wrong. The two halves of that are fixed where
 * they belong (the login now names a deactivated account, and Staff & roles
 * warns when a save leaves an account unable to sign in), but the seven rows
 * themselves still sit switched off on every box that has run the import.
 *
 * The institute has confirmed these are current staff. Activating them is a
 * decision, not a default, so it is recorded here rather than left as a note
 * for someone to repeat by hand on each environment.
 *
 * ---------------------------------------------------------------------------
 * Activation is not access.
 * ---------------------------------------------------------------------------
 *
 * The passwords these accounts hold are random 32-character strings that were
 * hashed and discarded — nobody has ever known them, and this migration does
 * not change them. `must_reset_password` stays true. So an activated account
 * still cannot be signed into until an administrator issues a temporary
 * password from Staff & roles, and the holder is then forced to replace it
 * with one only they know. What changes here is that the account is no longer
 * refused by `EnsureActiveUser` once it has a password somebody knows.
 *
 * ---------------------------------------------------------------------------
 * It only touches rows nobody has since made a decision about.
 * ---------------------------------------------------------------------------
 *
 * The guard is `is_active = 0 AND last_login_at IS NULL AND deleted_at IS
 * NULL`: an account still in exactly the state the import left it. If an
 * administrator has deliberately switched one of these people off after they
 * had been using the system, or removed them, re-running migrations must not
 * quietly hand their access back. That is the same rule
 * `RecordRemoval::restore()` follows for a different reason, and it is worth
 * being consistent about: getting a record back and granting someone access
 * are separate decisions.
 *
 * Every activation writes its own audit row, as a system actor. A privilege
 * appearing out of nowhere with nothing in the trail to explain it is exactly
 * what an auditor is looking for; "this deploy did it, and here is when" is an
 * answer.
 */
return new class extends Migration
{
    /** The seven the two import migrations created. */
    private const USERNAMES = [
        'aliraza', 'sofia', 'mariyam', 'shumailaltaf', 'emanashraf', 'iqraijaz', 'ayaanali',
    ];

    public function up(): void
    {
        $dormant = DB::table('users')
            ->whereIn('username', self::USERNAMES)
            ->where('is_active', false)
            ->whereNull('last_login_at')
            ->whereNull('deleted_at')
            ->get(['id', 'name', 'username']);

        foreach ($dormant as $user) {
            DB::table('users')->where('id', $user->id)->update([
                'is_active' => true,
                'deactivated_at' => null,
                'updated_at' => now(),
            ]);

            DB::table('audit_logs')->insert([
                'actor_type' => 'system',
                'actor_name' => null,
                'subject_type' => User::class,
                'subject_id' => $user->id,
                'subject_label' => $user->name,
                'action' => 'Account activated',
                'field' => 'is_active',
                'old_value' => '0',
                'new_value' => '1',
                'context' => json_encode([
                    'reason' => 'Officer named on the roll import, confirmed as current staff.',
                    'note' => 'Password unchanged and still unknown; a temporary one must be issued from Staff & roles.',
                ]),
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Only the ones that still have not been used. Someone who has signed
        // in since is a working account now, and rolling a migration back is
        // not a reason to lock them out mid-shift.
        $ids = DB::table('users')
            ->whereIn('username', self::USERNAMES)
            ->where('is_active', true)
            ->whereNull('last_login_at')
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        DB::table('users')->whereIn('id', $ids)->update([
            'is_active' => false,
            'updated_at' => now(),
        ]);
    }
};
