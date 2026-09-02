<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * An administrator can switch a staff account's access on and off.
 *
 * ---------------------------------------------------------------------------
 * The bug these tests exist for.
 * ---------------------------------------------------------------------------
 *
 * `is_active` was write-once. `saveUser()` set it to `true` on the CREATE path
 * and never mentioned it again, the edit drawer had no control for it, and the
 * staff list drew an account that could not sign in exactly like one that
 * could. So the column that decides whether someone may use the system had no
 * way to be changed after the row was written.
 *
 * That was invisible until the roll import, which creates an account for each
 * of the seven officers named on the institute's spreadsheet — deliberately
 * `is_active = false`, with an unguessable password, so that 478 rows have a
 * real author without seven unattended logins appearing on a production box.
 * They were always meant to be switched on by an administrator once the
 * institute confirmed who still works there. There was nothing to switch.
 *
 * The dead end was quiet from both directions: setting a temporary password on
 * one of those accounts saved the password and left `EnsureActiveUser`
 * refusing every request, so the attempt came back "Incorrect username or
 * password" and read as the password not having been saved at all.
 */
class StaffActivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function admin(): User
    {
        return $this->enrolTwoFactor(User::where('username', 'adminansar')->firstOrFail());
    }

    /**
     * An officer who cannot sign in — the shape the roll import leaves behind.
     *
     * A fixture rather than the real `aliraza`, even though that is the account
     * an administrator will reach for first. `DemoDataSeeder` recreates the
     * seven roll officers as working dev logins, so on a seeded database the
     * real row is active and would prove nothing here. That the MIGRATION
     * leaves them switched off is asserted where it can be — against a
     * production-shaped seed, in ProductionSeedingTest.
     */
    private function dormant(): User
    {
        return User::factory()->create([
            'name' => 'Dormant Officer',
            'username' => 'dormantofficer',
            'email' => 'dormant.officer@bbt.edu.pk',
            'role_id' => 'officer',
            'is_active' => false,
            'must_reset_password' => true,
        ]);
    }

    public function test_an_administrator_can_activate_a_dormant_account(): void
    {
        $user = $this->dormant();

        Livewire::actingAs($this->admin())
            ->test('pages.staff')
            ->call('editUser', $user->id)
            ->assertSet('isActive', false)   // it must LOAD the real state, see below
            ->set('isActive', true)
            ->set('tempPassword', 'starter-pass')
            ->call('saveUser')
            ->assertHasNoErrors();

        $this->assertTrue($user->fresh()->is_active);
    }

    /**
     * The regression that would be worse than the bug.
     *
     * `$isActive` is declared `true`, because a NEW account is created active.
     * If `editUser()` failed to overwrite it from the record, then opening any
     * inactive account and saving an unrelated change — a corrected surname —
     * would silently grant that account access. Seven of them exist on the
     * production box precisely because they should not have it yet.
     */
    public function test_opening_an_inactive_account_does_not_quietly_activate_it(): void
    {
        $user = $this->dormant();

        Livewire::actingAs($this->admin())
            ->test('pages.staff')
            ->call('editUser', $user->id)
            ->set('fullName', 'Ali Raza Corrected')
            ->call('saveUser')
            ->assertHasNoErrors();

        $fresh = $user->fresh();
        $this->assertSame('Ali Raza Corrected', $fresh->name);
        $this->assertFalse($fresh->is_active, 'Editing an unrelated field granted this account access.');
    }

    public function test_an_administrator_can_revoke_access(): void
    {
        $user = User::factory()->create([
            'username' => 'someofficer', 'email' => 'someofficer@bbt.edu.pk',
            'role_id' => 'officer', 'is_active' => true, 'must_reset_password' => false,
        ]);

        Livewire::actingAs($this->admin())
            ->test('pages.staff')
            ->call('editUser', $user->id)
            ->set('isActive', false)
            ->call('saveUser')
            ->assertHasNoErrors();

        $this->assertFalse($user->fresh()->is_active);
    }

    /**
     * You cannot switch off the account you are holding.
     *
     * `EnsureActiveUser` runs on every authenticated request, so this is not
     * an inconvenience that a refresh clears: the next click signs you out, and
     * a sole administrator who did it would have locked the institute out of
     * its own staff screen.
     */
    public function test_an_administrator_cannot_deactivate_themselves(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test('pages.staff')
            ->call('editUser', $admin->id)
            ->set('isActive', false)
            ->call('saveUser')
            ->assertHasErrors('isActive');

        $this->assertTrue($admin->fresh()->is_active);
    }

    /** A new account is usable the moment it is made. */
    public function test_a_newly_created_account_is_active(): void
    {
        Livewire::actingAs($this->admin())
            ->test('pages.staff')
            ->call('newUser')
            ->set('fullName', 'Fresh Hire')
            ->set('username', 'freshhire')
            ->set('email', 'fresh.hire@bbt.edu.pk')
            ->set('tempPassword', 'starter-pass')
            ->set('roleChoice', 'officer')
            ->call('saveUser')
            ->assertHasNoErrors();

        $this->assertTrue(User::where('username', 'freshhire')->firstOrFail()->is_active);
    }

    /**
     * Granting or revoking a login is its own audit entry.
     *
     * Folded into a generic "user updated" it is the one change an auditor
     * cannot find by name, and it is the change they come looking for.
     */
    public function test_activation_is_audited_under_its_own_name(): void
    {
        $user = $this->dormant();

        Livewire::actingAs($this->admin())
            ->test('pages.staff')
            ->call('editUser', $user->id)
            ->set('isActive', true)
            ->call('saveUser')
            ->assertHasNoErrors();

        $this->assertTrue(
            AuditLog::where('action', 'Account activated')->where('field', 'is_active')->exists(),
            'Turning an account on left no trail naming what happened.'
        );
    }
}
