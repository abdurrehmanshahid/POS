<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\AuditLog;
use App\Models\Challan;
use App\Models\Course;
use App\Models\Student;
use App\Models\User;
use App\Services\ChallanActions;
use App\Services\Ledger;
use App\Services\RegistrationService;
use App\Services\Sequences;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * Parity + invariants against the prototype seed (spec §14). Time is frozen to
 * the prototype's fixed "today" (2026-07-15) so overdue math reproduces exactly.
 */
class InstituteCoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-15 10:00:00');
        config(['institute.today' => null]); // use frozen now()
        $this->seed(DatabaseSeeder::class);
    }

    private function admin(): User
    {
        return User::where('username', 'adminansar')->firstOrFail();
    }

    private function officer(): User
    {
        return User::where('username', 'aliraza')->firstOrFail();
    }

    // ---- Seed / ledger parity ---------------------------------------------

    public function test_admin_ledger_reconciles_to_prototype_figures(): void
    {
        $L = app(Ledger::class);
        $admin = $this->admin();

        $this->assertSame(214000, $L->billed($admin));
        $this->assertSame(119000, $L->received($admin));
        $this->assertSame(95000, $L->outstanding($admin));
        $this->assertSame(56, $L->receivedPct($admin));
        $this->assertSame(11, $L->challanCount($admin));
        $this->assertSame(10, $L->activeStudentsCount($admin));
        $this->assertSame(3, $L->regsThisMonth($admin));
        $this->assertSame(3, $L->overdueCount($admin));
        // billed = received + outstanding (spec §7.1).
        $this->assertSame($L->billed($admin), $L->received($admin) + $L->outstanding($admin));
    }

    public function test_revenue_by_course_matches_prototype(): void
    {
        $rows = app(Ledger::class)->revenueByCourse($this->admin());
        $amounts = array_column($rows, 'amount', 'code');

        $this->assertSame(40000, $amounts['ODOO-301']);
        $this->assertSame(25000, $amounts['SHOP-101']);
        $this->assertSame(24000, $amounts['DMM-101']);
        $this->assertSame(20000, $amounts['GD-101']);
        $this->assertSame(10000, $amounts['SKC-101']);
    }

    public function test_officer_is_scoped_to_own_enrolments(): void
    {
        $L = app(Ledger::class);
        $officer = $this->officer();

        $this->assertSame(6, Admission::visibleTo($officer)->active()->count());
        $this->assertSame(1, $L->overdueCount($officer));
        // Officer sees only its own students (not the 12 total).
        $this->assertSame(6, Student::visibleTo($officer)->count());
    }

    // ---- Permissions -------------------------------------------------------

    public function test_permission_matrix_matches_roles(): void
    {
        $admin = $this->admin();
        $officer = $this->officer();

        $this->assertTrue($admin->can('scope.all'));
        $this->assertTrue($admin->can('settings.manage'));
        $this->assertTrue($admin->can('revenue.view'));

        $this->assertFalse($officer->can('scope.all'));
        $this->assertFalse($officer->can('revenue.view'));
        $this->assertFalse($officer->can('courses.view'));
        $this->assertTrue($officer->can('challans.pay'));
        $this->assertTrue($officer->can('registrations.create'));
    }

    // ---- Serials -----------------------------------------------------------

    public function test_serials_continue_from_seeded_counters(): void
    {
        $seq = app(Sequences::class);

        $this->assertSame('R26-0011', $seq->nextStudentCode('R'));
        $this->assertSame('T26-0003', $seq->nextStudentCode('T'));
        $this->assertSame('ADM-0012', $seq->nextAdmissionNo());
        $this->assertSame('CH-2026-1086', $seq->nextChallanNo());
        // Advanced by one each.
        $this->assertSame('R26-0012', $seq->nextStudentCode('R'));
        $this->assertSame('CH-2026-1087', $seq->nextChallanNo());
    }

    // ---- Registration fan-out ---------------------------------------------

    public function test_multi_course_registration_creates_one_challan_each_with_audit(): void
    {
        $officer = $this->officer();
        $courses = Course::whereIn('code', ['WD-101', 'AI-201'])->pluck('id')->all();

        $result = app(RegistrationService::class)->register($officer, [
            'new_student' => [
                'type' => 'R', 'name' => 'Test Student', 'guardian_name' => 'Guardian',
                'phone' => '+92 300 0000000', 'cnic' => '35201-0000000-1',
            ],
            'course_ids' => $courses,
            'discount_pct' => 10,
            'discount_reason' => 'Referral',
        ]);

        $this->assertCount(2, $result['admissions']);
        $this->assertCount(2, $result['challans']);
        $this->assertSame('R26-0011', $result['student']->student_code);

        // WD-101 fee 20000, 10% -> disc 2000, net 18000; discount audited.
        $wd = collect($result['challans'])->firstWhere('base_amount', 20000);
        $this->assertSame(2000, $wd->discount_amount);
        $this->assertSame(18000, $wd->net_amount);
        $this->assertSame($officer->id, $wd->discount_approved_by);
        $this->assertTrue($wd->auditLogs()->where('action', 'Discount applied')->exists());
        $this->assertTrue($wd->auditLogs()->where('action', 'Challan issued')->exists());
    }

    public function test_discount_without_reason_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(RegistrationService::class)->register($this->officer(), [
            'new_student' => [
                'type' => 'R', 'name' => 'X', 'guardian_name' => 'Y',
                'phone' => '+92 300 0000000', 'cnic' => '35201-0000000-2',
            ],
            'course_ids' => Course::where('code', 'WD-101')->pluck('id')->all(),
            'discount_pct' => 25,
            'discount_reason' => '',
        ]);
    }

    /**
     * The slider bounds this in the UI, but the percentage arrives over the wire
     * and a tampered request can carry anything. Over 100 the derived discount
     * exceeds the base and net_amount goes negative into an unsignedInteger
     * column (issue #10).
     */
    public function test_discount_outside_0_to_100_is_rejected(): void
    {
        $L = app(Ledger::class);
        $before = Challan::count();

        foreach ([150, -10] as $i => $pct) {
            try {
                app(RegistrationService::class)->register($this->officer(), [
                    'new_student' => [
                        'type' => 'R', 'name' => 'X', 'guardian_name' => 'Y',
                        'phone' => '+92 300 0000000', 'cnic' => '35201-000000'.$i.'-3',
                    ],
                    'course_ids' => Course::where('code', 'WD-101')->pluck('id')->all(),
                    'discount_pct' => $pct,
                    'discount_reason' => 'Tampered request',
                ]);
                $this->fail("A discount of {$pct}% should be rejected.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('between 0 and 100', $e->getMessage());
            }
        }

        // Nothing was persisted and the reporting invariant still holds.
        $this->assertSame($before, Challan::count());
        $this->assertSame(0, Challan::where('net_amount', '>', DB::raw('base_amount'))->count());
        $this->assertSame($L->billed($this->admin()), $L->received($this->admin()) + $L->outstanding($this->admin()));
    }

    // ---- Mark paid / cancel ------------------------------------------------

    public function test_mark_paid_records_payment_and_audit(): void
    {
        $challan = Challan::where('challan_no', 'CH-2026-1076')->firstOrFail();
        app(ChallanActions::class)->markPaid($challan, $this->admin(), 'Bank transfer');

        $challan->refresh();
        $this->assertSame('paid', $challan->status);
        $this->assertSame('Bank transfer', $challan->paid_via);
        $this->assertTrue($challan->auditLogs()->where('action', 'Marked paid')->exists());
    }

    public function test_cancel_voids_challan_from_totals(): void
    {
        $L = app(Ledger::class);
        $admin = $this->admin();
        $before = $L->billed($admin);

        $admission = Admission::where('reg_no', 'ADM-0002')->firstOrFail(); // unpaid 25000
        app(ChallanActions::class)->cancel($admission, $admin, 'Duplicate enrolment');

        $this->assertSame('cancelled', $admission->fresh()->status);
        $this->assertSame($before - 25000, $L->billed($admin));
    }

    /**
     * Every money query excludes cancelled admissions, so cancelling a PAID
     * registration used to erase banked revenue from every report retroactively,
     * with no refund record (issue #11). Cancellation is for uncollected
     * enrolments; giving money back is a separate, explicit act.
     */
    public function test_paid_registration_cannot_be_cancelled(): void
    {
        $L = app(Ledger::class);
        $admin = $this->admin();

        $admission = Admission::where('reg_no', 'ADM-0002')->firstOrFail();
        app(ChallanActions::class)->markPaid($admission->challan, $admin, 'Cash');
        $receivedBefore = $L->received($admin);

        try {
            app(ChallanActions::class)->cancel($admission, $admin, 'Student changed their mind');
            $this->fail('Cancelling a paid registration should be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('already paid', $e->getMessage());
        }

        // The enrolment survives and the collected money is still on the books.
        $this->assertNotSame('cancelled', $admission->fresh()->status);
        $this->assertSame($receivedBefore, $L->received($admin));
        $this->assertSame($L->billed($admin), $L->received($admin) + $L->outstanding($admin));
    }

    public function test_audit_rows_are_append_only_on_seed(): void
    {
        // 11 issued + 4 discounts + 6 paid = 21 audit rows.
        $this->assertSame(21, AuditLog::count());
    }
}
