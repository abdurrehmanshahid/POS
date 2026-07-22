<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * `staff.manage` must not be a back door to every other permission.
 * Each test names an escalation path and proves it is closed.
 */
class PrivilegeEscalationTest extends TestCase
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
     * A "supervisor" who may manage staff but has no revenue access, the
     * realistic middle role where escalation actually matters.
     */
    private function supervisor(): User
    {
        $role = Role::create(['id' => 'role_supervisor_111', 'name' => 'Supervisor', 'tone' => 'navy']);
        foreach (['dashboard.view', 'registrations.view', 'staff.view', 'staff.manage'] as $key) {
            RolePermission::create(['role_id' => $role->id, 'permission_key' => $key]);
        }

        return User::factory()->create([
            'name' => 'Sup Visor',
            'username' => 'supervisor',
            'email' => 'sup@bbt.edu.pk',
            'role_id' => $role->id,
            'is_active' => true,
            'must_reset_password' => false,
        ]);
    }

    public function test_a_manager_cannot_grant_permissions_they_do_not_hold(): void
    {
        $sup = $this->supervisor();

        Livewire::actingAs($sup)
            ->test('pages.staff')
            ->call('editRole', $sup->role_id)
            // Try to tick revenue + full data scope onto their own role.
            ->set('perms', ['dashboard.view', 'staff.view', 'staff.manage', 'revenue.view', 'scope.all'])
            ->call('saveRole')
            ->assertHasErrors('perms');

        $sup->refresh()->load('role.permissions');
        $this->assertFalse($sup->hasPermission('revenue.view'), 'Escalation to revenue.view must be blocked.');
        $this->assertFalse($sup->hasPermission('scope.all'), 'Escalation to scope.all must be blocked.');
    }

    public function test_a_manager_cannot_assign_a_role_richer_than_their_own(): void
    {
        $sup = $this->supervisor();
        $officer = User::where('username', 'aliraza')->firstOrFail();

        Livewire::actingAs($sup)
            ->test('pages.staff')
            ->call('editUser', $officer->id)
            ->set('roleChoice', 'admin')   // the full-power system role
            ->call('saveUser')
            ->assertHasErrors('roleChoice');

        $this->assertSame('officer', $officer->fresh()->role_id);
    }

    public function test_nobody_can_change_their_own_role(): void
    {
        $sup = $this->supervisor();

        Livewire::actingAs($sup)
            ->test('pages.staff')
            ->call('editUser', $sup->id)
            ->set('roleChoice', 'officer')
            ->call('saveUser')
            ->assertHasErrors('roleChoice');

        $this->assertSame('role_supervisor_111', $sup->fresh()->role_id);
    }

    public function test_an_admin_cannot_strip_manage_rights_from_their_own_role(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test('pages.staff')
            ->call('editRole', 'admin')
            ->set('perms', ['dashboard.view'])   // drops staff.manage
            ->call('saveRole')
            ->assertHasErrors('perms');

        $this->assertTrue($admin->fresh()->load('role.permissions')->hasPermission('staff.manage'));
    }

    public function test_an_admin_may_still_grant_what_they_hold(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test('pages.staff')
            ->call('editRole', 'officer')
            ->set('perms', ['dashboard.view', 'registrations.view', 'students.view', 'reports.view'])
            ->call('saveRole')
            ->assertHasNoErrors();

        $officer = User::where('username', 'aliraza')->firstOrFail();
        $this->assertTrue($officer->load('role.permissions')->hasPermission('reports.view'));
    }

    public function test_admin_set_password_forces_a_reset_at_next_sign_in(): void
    {
        $admin = $this->admin();
        $officer = User::where('username', 'aliraza')->firstOrFail();
        $officer->forceFill(['must_reset_password' => false])->save();

        Livewire::actingAs($admin)
            ->test('pages.staff')
            ->call('editUser', $officer->id)
            ->set('tempPassword', 'Temp@12345')
            ->call('saveUser')
            ->assertHasNoErrors();

        $this->assertTrue($officer->fresh()->must_reset_password);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'Password reset by admin',
            'subject_id' => $officer->id,
        ]);
    }

    public function test_role_changes_are_audited(): void
    {
        $admin = $this->admin();
        $officer = User::where('username', 'fatimanoor')->firstOrFail();

        Livewire::actingAs($admin)
            ->test('pages.staff')
            ->call('editUser', $officer->id)
            ->set('roleChoice', 'admin')
            ->call('saveUser')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'Role changed',
            'subject_id' => $officer->id,
            'new_value' => 'admin',
        ]);
    }
}
