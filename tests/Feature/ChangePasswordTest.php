<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SuperAdmin;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Changing your own password, for staff and for the owner.
 *
 * Before these screens existed every route to a new password went through
 * somebody else: the owner could issue a temporary one, or you could lock
 * yourself out and use the emailed link. The owner's own account had neither —
 * the single most privileged credential in the institute, and the only one with
 * no way to rotate it short of a database command.
 *
 * The tests below are mostly about what the screen REFUSES, because that is
 * where the security lives. A change-password form with no current-password
 * check turns an unlocked laptop into a permanent account takeover.
 */
class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    private const OLD = 'OldPassw0rd!x';

    private const NEW = 'BrandNewPassw0rd!';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-15 10:00:00');
        $this->seed(DatabaseSeeder::class);
        RateLimiter::clear('change-password:'.$this->officer()->id);
    }

    private function officer(): User
    {
        return User::where('username', 'aliraza')->firstOrFail();
    }

    private function withKnownPassword(): User
    {
        $user = $this->officer();
        $user->forceFill(['password' => Hash::make(self::OLD), 'must_reset_password' => false])->save();

        return $user->refresh();
    }

    public function test_a_member_of_staff_can_change_their_own_password(): void
    {
        $user = $this->withKnownPassword();

        Livewire::actingAs($user)->test('pages.auth.change-password')
            ->set('current', self::OLD)
            ->set('pw1', self::NEW)
            ->set('pw2', self::NEW)
            ->call('save')
            ->assertSet('error', '');

        $this->assertTrue(Hash::check(self::NEW, $user->fresh()->password));
    }

    /**
     * The rule that stops an unlocked laptop being a permanent takeover.
     *
     * Without it, anyone who walks up to a signed-in machine can set a password
     * the real owner does not know, and keep the account.
     */
    public function test_the_current_password_is_required(): void
    {
        $user = $this->withKnownPassword();

        Livewire::actingAs($user)->test('pages.auth.change-password')
            ->set('current', 'not-the-right-one')
            ->set('pw1', self::NEW)
            ->set('pw2', self::NEW)
            ->call('save')
            ->assertSet('error', 'That is not your current password.');

        $this->assertTrue(Hash::check(self::OLD, $user->fresh()->password),
            'The password changed without the current one being proved.');
    }

    /**
     * The current-password field is a guessing oracle on an unattended session,
     * so wrong answers have to cost something.
     */
    public function test_repeated_wrong_guesses_are_throttled(): void
    {
        $user = $this->withKnownPassword();
        $screen = Livewire::actingAs($user)->test('pages.auth.change-password');

        for ($i = 0; $i < 5; $i++) {
            $screen->set('current', 'wrong-'.$i)
                ->set('pw1', self::NEW)->set('pw2', self::NEW)
                ->call('save');
        }

        $screen->set('current', self::OLD)
            ->set('pw1', self::NEW)->set('pw2', self::NEW)
            ->call('save');

        $this->assertStringContainsString('Too many attempts', $screen->get('error'));
        $this->assertTrue(Hash::check(self::OLD, $user->fresh()->password),
            'The throttle let a change through after five wrong guesses.');
    }

    public function test_a_weak_password_is_refused(): void
    {
        $user = $this->withKnownPassword();

        Livewire::actingAs($user)->test('pages.auth.change-password')
            ->set('current', self::OLD)
            ->set('pw1', 'password')
            ->set('pw2', 'password')
            ->call('save')
            ->assertSet('error', 'New password is too weak. Add length, a number and a symbol.');

        $this->assertTrue(Hash::check(self::OLD, $user->fresh()->password));
    }

    public function test_mismatched_confirmation_is_refused(): void
    {
        $user = $this->withKnownPassword();

        Livewire::actingAs($user)->test('pages.auth.change-password')
            ->set('current', self::OLD)
            ->set('pw1', self::NEW)
            ->set('pw2', self::NEW.'typo')
            ->call('save')
            ->assertSet('error', 'The new passwords do not match.');

        $this->assertTrue(Hash::check(self::OLD, $user->fresh()->password));
    }

    /**
     * Re-entering the same password is refused rather than accepted silently.
     *
     * Somebody doing that has not rotated anything, and letting it pass leaves
     * them believing they have.
     */
    public function test_reusing_the_same_password_is_refused(): void
    {
        $user = $this->withKnownPassword();

        Livewire::actingAs($user)->test('pages.auth.change-password')
            ->set('current', self::OLD)
            ->set('pw1', self::OLD)
            ->set('pw2', self::OLD)
            ->call('save')
            ->assertSet('error', 'That is already your password. Choose a different one.');
    }

    /**
     * A forced first-login reset must be cleared by this screen too, or the
     * person is bounced back to `set-password` forever.
     */
    public function test_changing_a_password_clears_the_forced_reset_flag(): void
    {
        $user = $this->withKnownPassword();
        $user->forceFill(['must_reset_password' => true])->save();

        Livewire::actingAs($user->refresh())->test('pages.auth.change-password')
            ->set('current', self::OLD)
            ->set('pw1', self::NEW)
            ->set('pw2', self::NEW)
            ->call('save');

        $this->assertFalse((bool) $user->fresh()->must_reset_password);
    }

    /**
     * The owner may sign in as any member of staff. That session must not be
     * able to set the staff member's password — the owner could then use the
     * credential directly, and the audit trail would show only that the staff
     * member changed their own.
     */
    public function test_an_impersonated_session_cannot_change_a_password(): void
    {
        $user = $this->withKnownPassword();

        session(['impersonator_id' => SuperAdmin::firstOrFail()->id]);

        Livewire::actingAs($user)->test('pages.auth.change-password')
            ->assertForbidden();

        $this->assertTrue(Hash::check(self::OLD, $user->fresh()->password));
    }

    /** The fact is recorded; the value never is. */
    public function test_a_password_change_is_audited_without_recording_the_password(): void
    {
        $user = $this->withKnownPassword();

        Livewire::actingAs($user)->test('pages.auth.change-password')
            ->set('current', self::OLD)
            ->set('pw1', self::NEW)
            ->set('pw2', self::NEW)
            ->call('save');

        $log = AuditLog::where('action', 'Password changed')->latest('id')->firstOrFail();

        $this->assertSame($user->name, $log->subject_label);

        $serialised = json_encode($log->toArray());
        $this->assertStringNotContainsString(self::NEW, $serialised,
            'The new password reached the audit log.');
        $this->assertStringNotContainsString(self::OLD, $serialised,
            'The old password reached the audit log.');
    }

    // ---- The owner's own credential -----------------------------------------

    public function test_the_owner_can_change_their_own_password(): void
    {
        $owner = SuperAdmin::firstOrFail();
        $owner->forceFill(['password' => Hash::make(self::OLD)])->save();
        RateLimiter::clear('sa-change-password:'.$owner->id);

        Auth::guard('superadmin')->login($owner);

        Livewire::test('superadmin.change-password')
            ->set('current', self::OLD)
            ->set('pw1', self::NEW)
            ->set('pw2', self::NEW)
            ->call('save')
            ->assertSet('error', '');

        $this->assertTrue(Hash::check(self::NEW, $owner->fresh()->password));
    }

    public function test_the_owner_must_prove_their_current_password(): void
    {
        $owner = SuperAdmin::firstOrFail();
        $owner->forceFill(['password' => Hash::make(self::OLD)])->save();
        RateLimiter::clear('sa-change-password:'.$owner->id);

        Auth::guard('superadmin')->login($owner);

        Livewire::test('superadmin.change-password')
            ->set('current', 'guessing')
            ->set('pw1', self::NEW)
            ->set('pw2', self::NEW)
            ->call('save')
            ->assertSet('error', 'That is not your current password.');

        $this->assertTrue(Hash::check(self::OLD, $owner->fresh()->password),
            'The most privileged credential in the institute changed without proof of the old one.');
    }

    /** Signed out, the screen is not reachable at all. */
    public function test_a_guest_cannot_reach_either_screen(): void
    {
        $this->get('/change-password')->assertRedirect('/login');
        $this->get('/superadmin/change-password')->assertRedirect('/superadmin');
    }
}
