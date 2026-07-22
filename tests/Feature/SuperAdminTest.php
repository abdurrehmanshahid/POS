<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Challan;
use App\Models\Student;
use App\Models\SuperAdmin;
use App\Models\User;
use App\Services\Audit;
use App\Services\Impersonation;
use App\Services\TwoFactor;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/** The /superadmin guard, panel, destructive-action gating and impersonation. */
class SuperAdminTest extends TestCase
{
    use RefreshDatabase;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->secret = app(TwoFactor::class)->generateSecret();
    }

    private function su(): SuperAdmin
    {
        return $this->enrolTwoFactor(SuperAdmin::firstOrFail(), $this->secret);
    }

    private function code(): string
    {
        return app(Google2FA::class)->getCurrentOtp($this->secret);
    }

    /**
     * A valid code for a LATER timestep.
     *
     * Needed because each destructive action burns the timestep it consumed, so
     * a test doing two of them cannot reuse one code, that is the replay guard
     * doing its job. Real time does not advance during a test, but the ±1
     * window means a code minted one step ahead is accepted now and is strictly
     * newer than the burned one, which is exactly the situation of an operator
     * waiting for their authenticator to roll over.
     */
    private function nextCode(int $steps = 1): string
    {
        return app(Google2FA::class)->oathTotp($this->secret, (int) floor(time() / 30) + $steps);
    }

    // ---- Guard isolation ----------------------------------------------------

    public function test_the_panel_is_unreachable_without_a_superadmin_session(): void
    {
        $this->get('/superadmin/dashboard')->assertRedirect();
    }

    public function test_an_institute_admin_cannot_reach_the_panel(): void
    {
        $admin = $this->enrolTwoFactor(User::where('username', 'adminansar')->firstOrFail());

        // Signed in on the `web` guard with every one of the 16 permissions...
        $this->actingAs($admin)->get('/superadmin/dashboard')->assertRedirect();
    }

    public function test_the_super_admin_is_not_a_row_in_users(): void
    {
        $su = SuperAdmin::firstOrFail();

        $this->assertDatabaseMissing('users', ['email' => $su->email]);
        $this->assertDatabaseMissing('users', ['username' => $su->username]);
    }

    public function test_super_admin_must_enrol_two_factor_before_any_screen(): void
    {
        $su = SuperAdmin::firstOrFail();   // not enrolled
        $this->assertTrue($su->requiresTwoFactor());

        $this->actingAs($su, 'superadmin')
            ->get('/superadmin/dashboard')
            ->assertRedirect(route('superadmin.two-factor.setup'));
    }

    public function test_password_alone_never_grants_a_panel_session(): void
    {
        $su = $this->su();
        $su->forceFill(['password' => 'Owner@Pass1'])->save();

        Livewire::test('superadmin.login')
            ->set('user', $su->username)
            ->set('password', 'Owner@Pass1')
            ->call('login')
            ->assertRedirect(route('two-factor.challenge'));

        $this->assertGuest('superadmin');
    }

    public function test_panel_screens_render_for_an_enrolled_super_admin(): void
    {
        $su = $this->su();

        foreach (['dashboard', 'performance', 'staff', 'students', 'activity', 'backups'] as $screen) {
            $this->actingAs($su, 'superadmin')
                ->get("/superadmin/{$screen}")
                ->assertOk();
        }
    }

    // ---- Destructive gating ---------------------------------------------------

    public function test_removal_requires_both_the_typed_phrase_and_a_code(): void
    {
        $su = $this->su();
        $student = Student::where('student_code', 'R26-0001')->firstOrFail();

        $c = Livewire::actingAs($su, 'superadmin')
            ->test('superadmin.students')
            ->call('askRemove', $student->id);

        // Correct code, wrong phrase.
        $c->set('dangerTyped', 'WRONG')->set('dangerSecret', $this->code())
            ->set('dangerReason', 'test')->call('confirmDanger');
        $this->assertNotNull(Student::find($student->id), 'Must not delete on a wrong phrase.');

        // Correct phrase, wrong code.
        $c->set('dangerTyped', 'R26-0001')->set('dangerSecret', '000000')
            ->set('dangerReason', 'test')->call('confirmDanger');
        $this->assertNotNull(Student::find($student->id), 'Must not delete on a bad code.');

        // Both correct.
        $c->set('dangerTyped', 'R26-0001')->set('dangerSecret', $this->code())
            ->set('dangerReason', 'Left the institute')->call('confirmDanger');

        $this->assertNull(Student::find($student->id), 'Removed from normal queries.');
        $this->assertNotNull(Student::withTrashed()->find($student->id), 'But recoverable.');
    }

    public function test_removal_is_audited_with_its_reason(): void
    {
        $su = $this->su();
        $student = Student::where('student_code', 'R26-0001')->firstOrFail();

        Livewire::actingAs($su, 'superadmin')
            ->test('superadmin.students')
            ->call('askRemove', $student->id)
            ->set('dangerTyped', 'R26-0001')
            ->set('dangerSecret', $this->code())
            ->set('dangerReason', 'Duplicate record')
            ->call('confirmDanger');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'Student removed',
            'actor_type' => 'superadmin',
            'subject_id' => $student->id,
        ]);
    }

    public function test_a_student_with_paid_challans_cannot_be_purged(): void
    {
        $su = $this->su();

        // Rabia Bano's GD-101 challan is paid in the seed data.
        $paid = Challan::where('status', 'paid')->firstOrFail();
        $student = $paid->admission->student;

        Livewire::actingAs($su, 'superadmin')
            ->test('superadmin.students')
            ->call('askPurge', $student->id)
            ->assertSet('dangerOpen', false);   // blocked before the dialog opens

        $this->assertNotNull(Student::find($student->id));
    }

    public function test_a_staff_account_that_signed_admissions_cannot_be_purged(): void
    {
        $su = $this->su();
        $officer = User::where('username', 'aliraza')->firstOrFail();

        Livewire::actingAs($su, 'superadmin')
            ->test('superadmin.staff')
            ->call('askPurge', $officer->id)
            ->assertSet('dangerOpen', false);

        $this->assertNotNull(User::find($officer->id));
    }

    public function test_a_code_cannot_authorise_two_removals(): void
    {
        $su = $this->su();
        $code = $this->code();
        $a = Student::where('student_code', 'R26-0001')->firstOrFail();
        $b = Student::where('student_code', 'R26-0010')->firstOrFail();

        $c = Livewire::actingAs($su, 'superadmin')->test('superadmin.students');

        $c->call('askRemove', $a->id)->set('dangerTyped', 'R26-0001')
            ->set('dangerSecret', $code)->set('dangerReason', 'one')->call('confirmDanger');
        $this->assertNull(Student::find($a->id));

        // Same code again on a different record must be refused.
        $c->call('askRemove', $b->id)->set('dangerTyped', 'R26-0010')
            ->set('dangerSecret', $code)->set('dangerReason', 'two')->call('confirmDanger');
        $this->assertNotNull(Student::find($b->id), 'A used code must not authorise a second removal.');
    }

    // ---- Staff actions ---------------------------------------------------------

    public function test_password_reset_issues_a_temp_password_and_forces_a_change(): void
    {
        $su = $this->su();
        $officer = User::where('username', 'aliraza')->firstOrFail();
        $officer->forceFill(['must_reset_password' => false])->save();

        $c = Livewire::actingAs($su, 'superadmin')
            ->test('superadmin.staff')
            ->call('askResetPassword', $officer->id)
            ->set('dangerSecret', $this->code())
            ->call('confirmDanger');

        $temp = $c->get('issuedPassword');

        $this->assertNotEmpty($temp);
        $this->assertTrue($officer->fresh()->must_reset_password);
        $this->assertTrue(Hash::check($temp, $officer->fresh()->password));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'Password reset by super admin',
            'subject_id' => $officer->id,
        ]);
    }

    public function test_two_factor_reset_clears_the_secret(): void
    {
        $su = $this->su();
        $admin = $this->enrolTwoFactor(User::where('username', 'adminansar')->firstOrFail());
        $this->assertTrue($admin->hasTwoFactorEnabled());

        Livewire::actingAs($su, 'superadmin')
            ->test('superadmin.staff')
            ->call('askResetTwoFactor', $admin->id)
            ->set('dangerSecret', $this->code())
            ->call('confirmDanger');

        $this->assertFalse($admin->fresh()->hasTwoFactorEnabled());
    }

    public function test_removing_a_staff_account_also_ends_their_access(): void
    {
        $su = $this->su();
        $officer = User::where('username', 'fatimanoor')->firstOrFail();

        Livewire::actingAs($su, 'superadmin')
            ->test('superadmin.staff')
            ->call('askRemove', $officer->id)
            ->set('dangerTyped', 'fatimanoor')
            ->set('dangerSecret', $this->code())
            ->set('dangerReason', 'Left the institute')
            ->call('confirmDanger');

        $gone = User::withTrashed()->find($officer->id);
        $this->assertTrue($gone->trashed());
        $this->assertFalse($gone->is_active, 'A removed account must not still be able to sign in.');
        $this->assertNull($gone->remember_token, 'Remember-me cookies must be invalidated.');
    }

    public function test_restoring_an_account_does_not_restore_sign_in(): void
    {
        $su = $this->su();
        $officer = User::where('username', 'fatimanoor')->firstOrFail();

        $c = Livewire::actingAs($su, 'superadmin')->test('superadmin.staff');

        $c->call('askRemove', $officer->id)->set('dangerTyped', 'fatimanoor')
            ->set('dangerSecret', $this->code())->set('dangerReason', 'x')->call('confirmDanger');

        // A second destructive action needs a genuinely newer code.
        $c->call('askRestore', $officer->id)
            ->set('dangerSecret', $this->nextCode())
            ->call('confirmDanger');

        $restored = User::withTrashed()->find($officer->id);
        $this->assertFalse($restored->trashed(), 'Record is back.');
        $this->assertFalse($restored->is_active, 'But access is not.');
    }

    // ---- Impersonation -----------------------------------------------------------

    public function test_impersonation_switches_guard_and_is_audited_both_ways(): void
    {
        $su = $this->su();
        $officer = User::where('username', 'aliraza')->firstOrFail();

        Livewire::actingAs($su, 'superadmin')
            ->test('superadmin.staff')
            ->call('impersonate', $officer->id);

        $this->assertAuthenticatedAs($officer, 'web');
        $this->assertTrue(app(Impersonation::class)->isImpersonating());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'Impersonation started',
            'actor_type' => 'superadmin',
            'subject_id' => $officer->id,
        ]);

        app(Impersonation::class)->stop();

        $this->assertGuest('web');
        $this->assertDatabaseHas('audit_logs', ['action' => 'Impersonation ended']);
    }

    public function test_actions_during_impersonation_name_the_real_actor(): void
    {
        $su = $this->su();
        $officer = User::where('username', 'aliraza')->firstOrFail();

        app(Impersonation::class)->start($su, $officer);

        Audit::record('Test action', $officer, ['subject_label' => 'x']);

        $row = AuditLog::where('action', 'Test action')->firstOrFail();

        $this->assertStringContainsString($su->name, $row->actor_name);
        $this->assertSame($su->name, $row->context['performed_via_impersonation_by'] ?? null);
    }

    // ---- Backups -------------------------------------------------------------------

    public function test_backup_download_streams_sql_and_is_audited(): void
    {
        $su = $this->su();

        $response = $this->actingAs($su, 'superadmin')->get(route('superadmin.backups.sql'));
        $response->assertOk();

        $body = $response->streamedContent();
        $this->assertStringContainsString('Big Binary Tech Institute', $body);
        $this->assertStringContainsString('INSERT INTO', $body);

        $this->assertDatabaseHas('audit_logs', ['action' => 'Database backup downloaded']);
    }

    public function test_csv_export_rejects_unknown_tables(): void
    {
        $su = $this->su();

        $this->actingAs($su, 'superadmin')
            ->get(route('superadmin.backups.csv', ['table' => 'not_a_table']))
            ->assertNotFound();
    }

    public function test_backups_are_unreachable_to_staff(): void
    {
        $admin = $this->enrolTwoFactor(User::where('username', 'adminansar')->firstOrFail());

        $this->actingAs($admin)->get(route('superadmin.backups.sql'))->assertRedirect();
    }
}
