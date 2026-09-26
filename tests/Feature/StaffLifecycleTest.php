<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\RecordRemoval;
use App\Support\Nav;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A staff account from one end to the other: created on the Staff & roles
 * screen, signed into, forced onto a password of its own, switched off, and
 * switched back on.
 *
 * ---------------------------------------------------------------------------
 * The failure this exists to make impossible.
 * ---------------------------------------------------------------------------
 *
 * Every step below was already covered somewhere — `StaffActivationTest` proves
 * an administrator can toggle `is_active`, `AuthSecurityTest` proves a bad
 * password is refused, `ChangePasswordTest` proves a password can be changed.
 * What nothing covered was the JOIN: an administrator setting up an account and
 * then that account actually signing in, in one run, the way it happens at the
 * counter.
 *
 * In the gap between those tests sat this: an administrator issued a temporary
 * password to one of the dormant officer accounts the roll import creates, got
 * a green confirmation, and the sign-in came back "Incorrect username or
 * password". The account was `is_active = false`, so the password was never the
 * problem — but nothing on either screen said so, and the demonstration it
 * happened during was in front of a client.
 *
 * Both halves are asserted here: the login must NAME a deactivated account
 * rather than blaming the password, and the save must not report success when
 * it has left an account that cannot sign in.
 */
class StaffLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const TEMP = 'starter-pass-1';

    private const OWN = 'M1neAl0ne!x';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function admin(): User
    {
        return User::where('username', 'adminansar')->firstOrFail();
    }

    /** Create one through the screen, exactly as an administrator would. */
    private function createOfficer(string $username = 'newjoiner'): User
    {
        Livewire::actingAs($this->admin())
            ->test('pages.staff')
            ->call('newUser')
            ->set('fullName', 'New Joiner')
            ->set('username', $username)
            ->set('email', $username.'@bbt.edu.pk')
            ->set('tempPassword', self::TEMP)
            ->set('roleChoice', 'officer')
            ->call('saveUser')
            ->assertHasNoErrors();

        // Drop the administrator's session, so every sign-in below starts cold
        // and `assertGuest` means what it says rather than quietly reporting on
        // whoever created the account.
        Auth::guard('web')->logout();

        return User::where('username', $username)->firstOrFail();
    }

    // ---- The whole journey, in the order it really happens ------------------

    public function test_an_account_created_on_the_staff_screen_can_immediately_sign_in(): void
    {
        $user = $this->createOfficer();

        $this->assertTrue((bool) $user->is_active, 'A new account must not be born unable to sign in.');
        $this->assertTrue((bool) $user->must_reset_password);

        // 1. The temporary password works, and lands on the screen that makes
        //    them replace it. Reaching the portal directly would leave an
        //    administrator-known password in circulation indefinitely.
        Livewire::test('pages.auth.login')
            ->set('user', 'newjoiner')
            ->set('password', self::TEMP)
            ->call('login')
            ->assertRedirect(route('password.set'));

        $this->assertAuthenticatedAs($user->fresh(), 'web');

        // 2. They set one only they know, and the flag clears.
        Livewire::actingAs($user->fresh())
            ->test('pages.auth.set-password')
            ->set('pw1', self::OWN)
            ->set('pw2', self::OWN)
            ->call('save');

        $this->assertFalse((bool) $user->fresh()->must_reset_password);

        // 3. From a cold start, their own password reaches the portal itself.
        Auth::guard('web')->logout();

        Livewire::test('pages.auth.login')
            ->set('user', 'newjoiner')
            ->set('password', self::OWN)
            ->call('login')
            ->assertRedirect(route(Nav::firstScreen($user->fresh())));

        $this->assertAuthenticatedAs($user->fresh(), 'web');
    }

    public function test_signing_in_by_email_works_as_well_as_by_username(): void
    {
        $this->createOfficer();

        Livewire::test('pages.auth.login')
            ->set('user', 'NewJoiner@BBT.edu.pk')   // case is not a credential
            ->set('password', self::TEMP)
            ->call('login')
            ->assertRedirect(route('password.set'));

        $this->assertAuthenticated('web');
    }

    // ---- The lie the login screen used to tell -------------------------------

    /**
     * The bug, stated as a test.
     *
     * A correct password on a switched-off account must not be reported as an
     * incorrect password. The person typing it knows the password is right, so
     * that message sends them to re-issue a credential that was never the
     * problem — which is precisely what happened during the client demo.
     */
    public function test_a_deactivated_account_is_told_it_is_deactivated(): void
    {
        $user = $this->createOfficer();
        $user->forceFill(['is_active' => false])->save();

        $component = Livewire::test('pages.auth.login')
            ->set('user', 'newjoiner')
            ->set('password', self::TEMP)
            ->call('login');

        $error = $component->get('error');

        $this->assertStringContainsString('deactivated', $error);
        $this->assertStringNotContainsString('Incorrect', $error, 'The password was right; saying otherwise sends the administrator hunting for a fault that is not there.');
        $this->assertGuest('web');
    }

    /** ...and it is in the trail under its own name, not as a failed attempt. */
    public function test_a_refused_deactivated_sign_in_is_logged_as_itself(): void
    {
        $user = $this->createOfficer();
        $user->forceFill(['is_active' => false])->save();

        Livewire::test('pages.auth.login')
            ->set('user', 'newjoiner')
            ->set('password', self::TEMP)
            ->call('login');

        $this->assertTrue(
            AuditLog::where('action', 'Sign-in refused (deactivated)')->where('subject_id', $user->id)->exists(),
            'A refused sign-in on a live account left nothing in the trail to explain it.'
        );
        $this->assertFalse(
            AuditLog::where('action', 'Sign-in failed')->exists(),
            'A correct password must not be counted as a failed attempt.'
        );
    }

    /**
     * The refusal must not become a five-strikes lockout.
     *
     * Nothing is being guessed — the password is right. Counting these would
     * lock the account an administrator is in the middle of trying to fix, and
     * replace one misleading message ("incorrect password") with another
     * ("temporarily locked").
     */
    public function test_a_deactivated_refusal_does_not_count_towards_the_lockout(): void
    {
        $user = $this->createOfficer();
        $user->forceFill(['is_active' => false])->save();

        for ($i = 0; $i < 6; $i++) {
            $component = Livewire::test('pages.auth.login')
                ->set('user', 'newjoiner')
                ->set('password', self::TEMP)
                ->call('login');
        }

        $this->assertStringContainsString('deactivated', $component->get('error'));
        $this->assertStringNotContainsString('locked', $component->get('error'));

        // And the account is usable the moment it is switched back on, with no
        // cooling-off period to wait out.
        $user->forceFill(['is_active' => true])->save();

        Livewire::test('pages.auth.login')
            ->set('user', 'newjoiner')
            ->set('password', self::TEMP)
            ->call('login')
            ->assertRedirect(route('password.set'));

        $this->assertAuthenticated('web');
    }

    // ---- What it must still refuse to say ------------------------------------

    /**
     * Naming a deactivated account is only safe because you cannot reach that
     * message without the password. A wrong password on the same account must
     * be indistinguishable from a wrong password anywhere else, or the login
     * screen becomes an account-status oracle for anyone who can type.
     */
    public function test_a_wrong_password_on_a_deactivated_account_reveals_nothing(): void
    {
        $user = $this->createOfficer();
        $user->forceFill(['is_active' => false])->save();

        $deactivated = Livewire::test('pages.auth.login')
            ->set('user', 'newjoiner')
            ->set('password', 'not-the-password')
            ->call('login')
            ->get('error');

        $unknown = Livewire::test('pages.auth.login')
            ->set('user', 'nobody-at-all')
            ->set('password', 'not-the-password')
            ->call('login')
            ->get('error');

        $this->assertStringContainsString('Incorrect username or password', $deactivated);
        $this->assertStringNotContainsString('deactivated', $deactivated);
        $this->assertSame(preg_replace('/\(\d+ of \d+\)/', '', $unknown), preg_replace('/\(\d+ of \d+\)/', '', $deactivated));
    }

    /**
     * A removed account is not a deactivated one, and must not be advertised as
     * either. Soft deletion hides the row from the user provider, so the login
     * cannot even see it — which is the behaviour we want, asserted rather than
     * assumed.
     */
    public function test_a_removed_account_still_gets_the_generic_message(): void
    {
        $user = $this->createOfficer();
        $user->delete();

        $error = Livewire::test('pages.auth.login')
            ->set('user', 'newjoiner')
            ->set('password', self::TEMP)
            ->call('login')
            ->get('error');

        $this->assertStringContainsString('Incorrect username or password', $error);
        $this->assertStringNotContainsString('deactivated', $error);
        $this->assertGuest('web');
    }

    // ---- The save that used to sound like a success --------------------------

    public function test_saving_an_account_that_cannot_sign_in_warns_instead_of_celebrating(): void
    {
        $user = $this->createOfficer();
        $user->forceFill(['is_active' => false])->save();

        Livewire::actingAs($this->admin())
            ->test('pages.staff')
            ->call('editUser', $user->id)
            ->set('tempPassword', 'another-temp-pass')
            ->call('saveUser')
            ->assertHasNoErrors()
            ->assertDispatched('bbt-toast', fn ($event, $params) => $params['tone'] === 'warn'
                && str_contains($params['title'], 'cannot sign in'));
    }

    // ---- What the list has to say out loud ------------------------------------

    /**
     * The screen must distinguish an account that works from one that cannot.
     *
     * Every row used to render identically whatever state it was in, so seven
     * dormant records left by the roll import sat among the working logins
     * looking exactly like them. The only way to find out which was which was
     * to try one — which is how this was discovered, mid-demonstration.
     */
    public function test_the_staff_list_says_which_accounts_can_actually_be_used(): void
    {
        $this->createOfficer();

        $dormant = User::factory()->create([
            'name' => 'Dormant Officer', 'username' => 'dormantofficer',
            'email' => 'dormant.officer@bbt.edu.pk', 'role_id' => 'officer',
            'is_active' => false, 'must_reset_password' => true,
        ]);

        Livewire::actingAs($this->admin())
            ->test('pages.staff')
            ->assertSee('Cannot sign in')            // the dormant one
            ->assertSee('Never signed in')           // created, but not yet used
            ->assertSee('Temporary password pending');

        $this->assertFalse((bool) $dormant->is_active);
    }

    // ---- What has to survive the account -------------------------------------

    /**
     * Removing an officer must not erase their signature from the roll.
     *
     * `admissions.enrolled_by` is the source of truth for who registered whom
     * and is deliberately never editable, which is exactly why the account it
     * points at cannot be purged. But staff SOFT delete, and the relation that
     * reads the name back did not say `withTrashed()` — so the moment an
     * officer was removed, `$a->enroller->name` resolved to null and every
     * screen that prints it (the registrations list, its drawer, the challan
     * drawer) fatalled on rows that had rendered fine the day before.
     *
     * On the production box one account signs 435 of the roll's 478 lines. That
     * is the screen the institute looks at most, one removal away from a white
     * page.
     */
    public function test_removing_an_officer_leaves_their_signature_on_the_history(): void
    {
        $admin = $this->admin();
        $officer = User::where('username', 'aliraza')->firstOrFail();
        $admission = Admission::where('enrolled_by', $officer->id)->firstOrFail();

        app(RecordRemoval::class)->remove($officer, $admin, 'left the institute');

        $this->assertNull(User::find($officer->id), 'The account should be gone from every ordinary query.');
        $this->assertSame(
            'Ali Raza',
            $admission->fresh()->enroller?->name,
            'The officer who signed this enrolment can no longer be named.'
        );

        // And the screens that print it still render.
        Livewire::actingAs($admin)->test('pages.registrations')->assertOk();
    }

    public function test_activating_and_issuing_a_password_together_produces_a_working_login(): void
    {
        $user = $this->createOfficer();
        $user->forceFill(['is_active' => false])->save();

        Livewire::actingAs($this->admin())
            ->test('pages.staff')
            ->call('editUser', $user->id)
            ->set('isActive', true)
            ->set('tempPassword', 'reissued-pass')
            ->call('saveUser')
            ->assertHasNoErrors()
            ->assertDispatched('bbt-toast', fn ($event, $params) => $params['tone'] === 'ok');

        Livewire::test('pages.auth.login')
            ->set('user', 'newjoiner')
            ->set('password', 'reissued-pass')
            ->call('login')
            ->assertRedirect(route('password.set'));

        $this->assertAuthenticated('web');
    }
}
