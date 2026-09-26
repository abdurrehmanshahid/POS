<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Challan;
use App\Models\Payment;
use App\Models\Student;
use App\Models\SuperAdmin;
use App\Models\User;
use App\Services\Audit;
use App\Services\ChallanActions;
use App\Services\Impersonation;
use App\Services\RecordRemoval;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/** The /superadmin guard, panel, destructive-action gating and impersonation. */
class SuperAdminTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Owner@Pass1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function su(): SuperAdmin
    {
        $su = SuperAdmin::firstOrFail();
        $su->forceFill(['password' => self::PASSWORD])->save();

        return $su;
    }

    /** What the step-up prompt asks for: the super admin's own password. */
    private function code(): string
    {
        return self::PASSWORD;
    }

    // ---- Guard isolation ----------------------------------------------------

    public function test_the_panel_is_unreachable_without_a_superadmin_session(): void
    {
        $this->get('/superadmin/dashboard')->assertRedirect();
    }

    public function test_an_institute_admin_cannot_reach_the_panel(): void
    {
        $admin = User::where('username', 'adminansar')->firstOrFail();

        // Signed in on the `web` guard with every one of the 16 permissions...
        $this->actingAs($admin)->get('/superadmin/dashboard')->assertRedirect();
    }

    public function test_the_super_admin_is_not_a_row_in_users(): void
    {
        $su = SuperAdmin::firstOrFail();

        $this->assertDatabaseMissing('users', ['email' => $su->email]);
        $this->assertDatabaseMissing('users', ['username' => $su->username]);
    }

    public function test_the_password_alone_opens_the_panel(): void
    {
        $su = $this->su();

        Livewire::test('superadmin.login')
            ->set('user', $su->username)
            ->set('password', self::PASSWORD)
            ->call('login')
            ->assertRedirect(route('superadmin.dashboard'));

        $this->assertAuthenticatedAs($su, 'superadmin');
    }

    public function test_panel_screens_render_for_a_super_admin(): void
    {
        $su = $this->su();

        foreach (['dashboard', 'performance', 'staff', 'students', 'activity', 'backups'] as $screen) {
            $this->actingAs($su, 'superadmin')
                ->get("/superadmin/{$screen}")
                ->assertOk();
        }
    }

    // ---- Destructive gating ---------------------------------------------------

    public function test_removal_requires_both_the_typed_phrase_and_the_password(): void
    {
        $su = $this->su();
        $student = Student::where('student_code', 'BBT-R26-0001')->firstOrFail();

        $c = Livewire::actingAs($su, 'superadmin')
            ->test('superadmin.students')
            ->call('askRemove', $student->id);

        // Correct code, wrong phrase.
        $c->set('dangerTyped', 'WRONG')->set('dangerSecret', $this->code())
            ->set('dangerReason', 'test')->call('confirmDanger');
        $this->assertNotNull(Student::find($student->id), 'Must not delete on a wrong phrase.');

        // Correct phrase, wrong code.
        $c->set('dangerTyped', 'BBT-R26-0001')->set('dangerSecret', '000000')
            ->set('dangerReason', 'test')->call('confirmDanger');
        $this->assertNotNull(Student::find($student->id), 'Must not delete on a bad code.');

        // Both correct.
        $c->set('dangerTyped', 'BBT-R26-0001')->set('dangerSecret', $this->code())
            ->set('dangerReason', 'Left the institute')->call('confirmDanger');

        $this->assertNull(Student::find($student->id), 'Removed from normal queries.');
        $this->assertNotNull(Student::withTrashed()->find($student->id), 'But recoverable.');
    }

    public function test_removal_is_audited_with_its_reason(): void
    {
        $su = $this->su();
        $student = Student::where('student_code', 'BBT-R26-0001')->firstOrFail();

        Livewire::actingAs($su, 'superadmin')
            ->test('superadmin.students')
            ->call('askRemove', $student->id)
            ->set('dangerTyped', 'BBT-R26-0001')
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

    public function test_a_student_with_only_a_part_payment_cannot_be_purged(): void
    {
        $su = $this->su();
        $admin = User::where('username', 'adminansar')->firstOrFail();

        // An advance against an otherwise unpaid challan. The challan stays
        // flagged unpaid, which is exactly the state the old blocker waved
        // through, and `payments.challan_id` cascades, so the purge would have
        // destroyed the collection along with the challan.
        $challan = Challan::where('status', '!=', 'paid')->firstOrFail();
        $student = $challan->admission->student;
        app(ChallanActions::class)->recordPayment($challan, $admin, 5000, 'Cash');

        $this->assertSame('unpaid', $challan->refresh()->status);

        Livewire::actingAs($su, 'superadmin')
            ->test('superadmin.students')
            ->call('askPurge', $student->id)
            ->assertSet('dangerOpen', false);   // blocked before the dialog opens

        $this->assertNotNull(Student::find($student->id));
        $this->assertSame(5000, (int) Payment::where('challan_id', $challan->id)->sum('amount'));
    }

    public function test_the_purge_blocker_names_the_amount_at_risk(): void
    {
        $admin = User::where('username', 'adminansar')->firstOrFail();
        $challan = Challan::where('status', '!=', 'paid')->firstOrFail();
        $student = $challan->admission->student;

        app(ChallanActions::class)->recordPayment($challan, $admin, 7500, 'Cash');

        $blocker = app(RecordRemoval::class)->purgeBlocker($student->refresh());

        $this->assertNotNull($blocker);
        $this->assertStringContainsString('Rs 7,500', $blocker);
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

        $c->call('askRestore', $officer->id)
            ->set('dangerSecret', $this->code())
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

    /**
     * A dump holds every password hash and encrypted 2FA secret in the system,
     * so a live session alone must not be enough to take one (issue #12).
     */
    public function test_backup_download_is_refused_without_a_fresh_step_up(): void
    {
        $su = $this->su();

        $this->actingAs($su, 'superadmin')
            ->get(route('superadmin.backups.sql'))
            ->assertForbidden();

        $this->actingAs($su, 'superadmin')
            ->get(route('superadmin.backups.csv', ['table' => 'students']))
            ->assertForbidden();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'Database backup downloaded']);
    }

    public function test_backup_download_streams_sql_and_is_audited(): void
    {
        $su = $this->su();

        Livewire::actingAs($su, 'superadmin')
            ->test('superadmin.backups')
            ->call('askSql')
            ->set('dangerSecret', $this->code())
            ->call('confirmDanger')
            ->assertRedirect(route('superadmin.backups.sql'));

        $response = $this->actingAs($su, 'superadmin')->get(route('superadmin.backups.sql'));
        $response->assertOk();

        $body = $response->streamedContent();
        $this->assertStringContainsString('Big Binary Tech Institute', $body);
        $this->assertStringContainsString('INSERT INTO', $body);

        $this->assertDatabaseHas('audit_logs', ['action' => 'Database backup downloaded']);
    }

    /** The ticket authorises exactly one download; a replayed URL gets nothing. */
    public function test_a_backup_ticket_cannot_be_spent_twice(): void
    {
        $su = $this->su();

        Livewire::actingAs($su, 'superadmin')
            ->test('superadmin.backups')
            ->call('askSql')
            ->set('dangerSecret', $this->code())
            ->call('confirmDanger');

        $this->actingAs($su, 'superadmin')->get(route('superadmin.backups.sql'))->assertOk();
        $this->actingAs($su, 'superadmin')->get(route('superadmin.backups.sql'))->assertForbidden();
    }

    public function test_csv_export_rejects_unknown_tables(): void
    {
        $su = $this->su();

        // Cleared the step-up, so this proves the allow-list rejects the table
        // rather than the ticket gate rejecting the request.
        Livewire::actingAs($su, 'superadmin')
            ->test('superadmin.backups')
            ->call('askCsv', 'not_a_table')
            ->set('dangerSecret', $this->code())
            ->call('confirmDanger');

        $this->actingAs($su, 'superadmin')
            ->get(route('superadmin.backups.csv', ['table' => 'not_a_table']))
            ->assertNotFound();
    }

    public function test_backups_are_unreachable_to_staff(): void
    {
        $admin = User::where('username', 'adminansar')->firstOrFail();

        $this->actingAs($admin)->get(route('superadmin.backups.sql'))->assertRedirect();
    }
}
