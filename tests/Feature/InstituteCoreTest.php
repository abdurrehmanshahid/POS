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
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
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

        $this->assertSame('BBT-R26-0011', $seq->nextStudentCode('R'));
        $this->assertSame('BBT-T26-0003', $seq->nextStudentCode('T'));
        $this->assertSame('BBT-ADM-0012', $seq->nextAdmissionNo());
        $this->assertSame('BBT-CH-2026-1086', $seq->nextChallanNo());
        // Advanced by one each.
        $this->assertSame('BBT-R26-0012', $seq->nextStudentCode('R'));
        $this->assertSame('BBT-CH-2026-1087', $seq->nextChallanNo());
    }

    // ---- Registration fan-out ---------------------------------------------

    /**
     * A multi-course registration produces ONE invoice billing every course.
     *
     * This test previously asserted the opposite, one challan per course, and
     * it was changed rather than kept because the institute's own paperwork
     * settles it: Invoice #1077 bills a single student for three Shopify
     * courses on one document with one fee, one discount and one balance. The
     * old shape handed a three-course student three invoices to reconcile.
     */
    public function test_multi_course_registration_creates_one_invoice_billing_every_course(): void
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

        $this->assertCount(2, $result['admissions'], 'Two courses, so two enrolments.');
        $this->assertCount(1, $result['challans'], 'Billed on a single invoice.');
        $this->assertSame('BBT-R26-0011', $result['student']->student_code);

        // WD-101 20000 + AI-201 20000 = 40000, 10% -> disc 4000, net 36000.
        $invoice = $result['challans'][0];
        $this->assertSame(40000, $invoice->base_amount);
        $this->assertSame(4000, $invoice->discount_amount);
        $this->assertSame(36000, $invoice->net_amount);
        $this->assertSame($officer->id, $invoice->discount_approved_by);
        $this->assertTrue($invoice->auditLogs()->where('action', 'Discount applied')->exists());
        $this->assertTrue($invoice->auditLogs()->where('action', 'Challan issued')->exists());

        // Both enrolments are billed on it, each carrying its own course's fee.
        $billed = $invoice->admissions()->pluck('billed_amount', 'course_id');
        $this->assertCount(2, $billed);
        $this->assertSame(40000, (int) $billed->sum(), 'The shares must reconstruct the invoice exactly.');

        // The invariant the whole model rests on.
        $this->assertSame($invoice->base_amount, $invoice->liveBilledTotal());

        // And every enrolment resolves back to the one invoice, including the
        // non-anchor. Under the old relation the second course reported having
        // no challan while being billed on one.
        foreach ($result['admissions'] as $admission) {
            $this->assertSame($invoice->id, $admission->refresh()->challan?->id);
        }
    }

    /**
     * Cancelling one course on a grouped invoice must not take the invoice,
     * and the money on it, out of the reports while other courses are live.
     *
     * Money totals used to test the ANCHOR enrolment's status, so cancelling
     * that single enrolment dropped the whole invoice out of billed and
     * received even though the student was still enrolled on, and still owed
     * for, everything else on it.
     */
    public function test_cancelling_one_course_leaves_the_rest_of_its_invoice_billed(): void
    {
        $admin = $this->admin();
        $ledger = app(Ledger::class);

        $result = app(RegistrationService::class)->register($admin, [
            'new_student' => [
                'type' => 'R', 'name' => 'Partial Cancel', 'guardian_name' => 'Guardian',
                'phone' => '+92 300 6660000', 'cnic' => '35201-6660000-3',
            ],
            'course_ids' => Course::whereIn('code', ['WD-101', 'AI-201'])->pluck('id')->all(),
        ]);

        $invoice = $result['challans'][0];
        $billedWithInvoice = $ledger->billed($admin);

        // The anchor: the enrolment challans.admission_id points at.
        $anchor = $result['admissions'][0];
        $this->assertSame($anchor->id, $invoice->admission_id);

        app(ChallanActions::class)->cancel($anchor, $admin, 'Student dropped this course');

        $this->assertSame(
            $billedWithInvoice,
            $ledger->billed($admin),
            'The other course on this invoice is still live, so the invoice is still billed.'
        );

        // Once every enrolment on it is cancelled, it correctly falls out.
        app(ChallanActions::class)->cancel($result['admissions'][1], $admin, 'Student withdrew entirely');

        $this->assertSame(
            $billedWithInvoice - (int) $invoice->net_amount,
            $ledger->billed($admin),
            'With nothing live on it, the invoice leaves the totals.'
        );
    }

    /**
     * Revenue must reach every course on a grouped invoice, not only the one
     * that heads it. This is the failure `billed_amount` exists to prevent.
     */
    public function test_a_grouped_invoice_credits_revenue_to_every_course_on_it(): void
    {
        $officer = $this->officer();

        $result = app(RegistrationService::class)->register($officer, [
            'new_student' => [
                'type' => 'R', 'name' => 'Grouped Revenue', 'guardian_name' => 'Guardian',
                'phone' => '+92 300 4440000', 'cnic' => '35201-4440000-1',
            ],
            'course_ids' => Course::whereIn('code', ['WD-101', 'AI-201'])->pluck('id')->all(),
        ]);

        $invoice = $result['challans'][0];
        $shares = $invoice->admissions()->with('course')->get();

        $this->assertSame(
            ['AI-201', 'WD-101'],
            $shares->pluck('course.code')->sort()->values()->all(),
            'Both courses are billed on the invoice and can be reported on.'
        );

        foreach ($shares as $share) {
            $this->assertSame(
                (int) $share->course->fee,
                $share->billed_amount,
                $share->course->code.' carries its own fee as its share of the invoice.'
            );
        }
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
        $challan = Challan::where('challan_no', 'BBT-CH-2026-1076')->firstOrFail();
        app(ChallanActions::class)->markPaid($challan, $this->admin(), 'Bank transfer');

        $challan->refresh();
        $this->assertSame('paid', $challan->status);
        $this->assertSame('Bank transfer', $challan->paid_via);
        $this->assertTrue($challan->auditLogs()->where('action', 'Marked paid')->exists());
    }

    /**
     * The counter case the paid/unpaid flag could never express: an advance now,
     * the balance later. Both movements are their own rows and revenue reflects
     * the money as it actually arrives.
     */
    public function test_a_part_payment_is_collected_without_settling_the_challan(): void
    {
        $L = app(Ledger::class);
        $admin = $this->admin();
        $receivedBefore = $L->received($admin);

        $challan = Challan::where('challan_no', 'BBT-CH-2026-1076')->firstOrFail(); // unpaid, net 25000
        app(ChallanActions::class)->recordPayment($challan, $admin, 10000, 'Cash');

        $challan->refresh();
        $this->assertNotSame('paid', $challan->status, 'Money is still owed, so it is not settled.');
        $this->assertSame(10000, $challan->paidAmount());
        $this->assertSame(15000, $challan->balance());
        $this->assertTrue($challan->isPartiallyPaid());

        // The advance counts as revenue the moment it is taken, and the
        // reporting invariant still holds mid-way through a collection.
        $this->assertSame($receivedBefore + 10000, $L->received($admin));
        $this->assertSame($L->billed($admin), $L->received($admin) + $L->outstanding($admin));

        // Settling the remainder closes it out.
        app(ChallanActions::class)->recordPayment($challan, $admin, 15000, 'Bank transfer');
        $challan->refresh();

        $this->assertSame('paid', $challan->status);
        $this->assertSame(0, $challan->balance());
        $this->assertSame(25000, $challan->paidAmount());
        $this->assertCount(2, $challan->payments);
        $this->assertSame($receivedBefore + 25000, $L->received($admin));
    }

    public function test_a_payment_cannot_exceed_the_outstanding_balance(): void
    {
        $challan = Challan::where('challan_no', 'BBT-CH-2026-1076')->firstOrFail();

        $this->expectException(RuntimeException::class);
        app(ChallanActions::class)->recordPayment($challan, $this->admin(), 99999, 'Cash');
    }

    public function test_mark_paid_settles_the_whole_balance_in_one_movement(): void
    {
        $challan = Challan::where('challan_no', 'BBT-CH-2026-1076')->firstOrFail();
        app(ChallanActions::class)->markPaid($challan, $this->admin(), 'Cash');

        $challan->refresh();
        $this->assertSame('paid', $challan->status);
        $this->assertSame(0, $challan->balance());
        $this->assertCount(1, $challan->payments);
        $this->assertSame(25000, $challan->payments->first()->amount);
    }

    /**
     * Drives the real screens, not just the service. Both pages own a copy of
     * the pay flow, so a mistake in one is invisible to a test of the other.
     */
    #[DataProvider('payScreens')]
    public function test_the_pay_dialog_records_a_part_payment_on_each_screen(string $screen): void
    {
        $challan = Challan::where('challan_no', 'BBT-CH-2026-1076')->firstOrFail(); // unpaid, net 25000

        Livewire::actingAs($this->admin())
            ->test($screen)
            ->call('askPay', $challan->id)
            ->assertSet('payAmount', 25000)   // pre-filled with the full balance
            ->set('payAmount', 10000)
            ->set('payMethod', 'Cash')
            ->call('confirmPay')
            ->assertSet('payId', null);

        $challan->refresh();
        $this->assertSame(10000, $challan->paidAmount());
        $this->assertSame(15000, $challan->balance());
        $this->assertNotSame('paid', $challan->status);
    }

    public static function payScreens(): array
    {
        return [
            'challans screen' => ['pages.challans'],
            'registrations screen' => ['pages.registrations'],
        ];
    }

    public function test_cancel_voids_challan_from_totals(): void
    {
        $L = app(Ledger::class);
        $admin = $this->admin();
        $before = $L->billed($admin);

        $admission = Admission::where('reg_no', 'BBT-ADM-0002')->firstOrFail(); // unpaid 25000
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

        $admission = Admission::where('reg_no', 'BBT-ADM-0002')->firstOrFail();
        app(ChallanActions::class)->markPaid($admission->challan, $admin, 'Cash');
        $receivedBefore = $L->received($admin);

        try {
            app(ChallanActions::class)->cancel($admission, $admin, 'Student changed their mind');
            $this->fail('Cancelling a paid registration should be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Money has been collected', $e->getMessage());
        }

        // The enrolment survives and the collected money is still on the books.
        $this->assertNotSame('cancelled', $admission->fresh()->status);
        $this->assertSame($receivedBefore, $L->received($admin));
        $this->assertSame($L->billed($admin), $L->received($admin) + $L->outstanding($admin));
    }

    /**
     * The guard above originally tested `status === 'paid'`. Part payments landed
     * after it, and an advance leaves the status short of paid, so a part
     * collected enrolment slipped straight through the check and took its banked
     * advance out of every report on the way. The guard tests collections now.
     */
    public function test_part_paid_registration_cannot_be_cancelled(): void
    {
        $L = app(Ledger::class);
        $admin = $this->admin();

        $admission = Admission::where('reg_no', 'BBT-ADM-0002')->firstOrFail(); // unpaid 25000
        app(ChallanActions::class)->recordPayment($admission->challan, $admin, 10000, 'Cash');

        $challan = $admission->challan->fresh();
        $this->assertNotSame('paid', $challan->status, 'An advance must leave the status short of paid.');
        $this->assertTrue($challan->isPartiallyPaid());

        $receivedBefore = $L->received($admin);

        try {
            app(ChallanActions::class)->cancel($admission, $admin, 'Student changed their mind');
            $this->fail('Cancelling a part paid registration should be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Money has been collected', $e->getMessage());
        }

        // The advance is still on the books and the ledger still reconciles.
        $this->assertNotSame('cancelled', $admission->fresh()->status);
        $this->assertSame($receivedBefore, $L->received($admin));
        $this->assertSame($L->billed($admin), $L->received($admin) + $L->outstanding($admin));
    }

    /** An enrolment nobody has paid anything against is still cancellable. */
    public function test_uncollected_registration_is_still_cancellable(): void
    {
        $admin = $this->admin();
        $admission = Admission::where('reg_no', 'BBT-ADM-0002')->firstOrFail();

        $this->assertFalse($admission->challan->hasCollections());
        app(ChallanActions::class)->cancel($admission, $admin, 'Duplicate enrolment');

        $this->assertSame('cancelled', $admission->fresh()->status);
    }

    /**
     * The drawer used to offer Cancel on any live enrolment, so a collected-on
     * one showed a button whose only possible outcome was the server refusing it.
     * The button now reads the same predicate the service enforces.
     */
    #[DataProvider('payScreens')]
    public function test_the_drawer_hides_cancel_once_money_is_collected(string $screen): void
    {
        $challan = Challan::where('challan_no', 'BBT-CH-2026-1076')->firstOrFail(); // unpaid, net 25000

        // Uncollected: the action is genuinely available, so it is offered.
        Livewire::actingAs($this->admin())
            ->test($screen)
            ->call('select', $challan->id)
            ->assertSeeHtml('wire:click="askCancel('.$challan->admission_id.')"');

        // A part payment is enough to take it away, not just a full settlement.
        app(ChallanActions::class)->recordPayment($challan, $this->admin(), 10000, 'Cash');
        $this->assertTrue($challan->fresh()->hasCollections());

        Livewire::actingAs($this->admin())
            ->test($screen)
            ->call('select', $challan->id)
            ->assertDontSeeHtml('wire:click="askCancel('.$challan->admission_id.')"');
    }

    public function test_audit_rows_are_append_only_on_seed(): void
    {
        // 11 issued + 4 discounts + 6 paid = 21 audit rows.
        $this->assertSame(21, AuditLog::count());
    }

    // ---- Enrolment invariants -----------------------------------------------
    //
    // The wizard guards all of these at selection time, but selection and
    // submission are separate requests. These assert the service refuses on its
    // own, which is what protects every caller that is not the wizard.

    public function test_a_full_course_refuses_further_enrolments(): void
    {
        $course = Course::where('code', 'SHOP-101')->firstOrFail();
        // Pin capacity to exactly what is already taken.
        $course->update(['capacity' => $course->seatsUsed()]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is full');

        app(RegistrationService::class)->register($this->admin(), [
            'new_student' => [
                'type' => 'R', 'name' => 'Overflow Candidate', 'guardian_name' => 'Guardian',
                'phone' => '+92 300 1112223', 'cnic' => '35201-9998887-1',
            ],
            'course_ids' => [$course->id],
        ]);
    }

    public function test_an_inactive_course_refuses_enrolments(): void
    {
        $course = Course::where('code', 'SHOP-101')->firstOrFail();
        $course->update(['is_active' => false]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no longer taking enrolments');

        app(RegistrationService::class)->register($this->admin(), [
            'new_student' => [
                'type' => 'R', 'name' => 'Late Arrival', 'guardian_name' => 'Guardian',
                'phone' => '+92 300 1112224', 'cnic' => '35201-9998887-2',
            ],
            'course_ids' => [$course->id],
        ]);
    }

    public function test_a_stale_course_id_aborts_instead_of_half_registering(): void
    {
        $students = Student::count();

        try {
            app(RegistrationService::class)->register($this->admin(), [
                'new_student' => [
                    'type' => 'R', 'name' => 'Ghost Student', 'guardian_name' => 'Guardian',
                    'phone' => '+92 300 1112225', 'cnic' => '35201-9998887-3',
                ],
                'course_ids' => [999999],
            ]);
            $this->fail('A vanished course must abort the registration.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('no longer exists', $e->getMessage());
        }

        // The whole point: no orphan person left behind by a failed enrolment.
        $this->assertSame($students, Student::count());
        $this->assertNull(Student::where('name', 'Ghost Student')->first());
    }

    public function test_unticking_generate_challans_enrols_without_billing(): void
    {
        $course = Course::where('code', 'SHOP-101')->firstOrFail();

        $result = app(RegistrationService::class)->register($this->admin(), [
            'new_student' => [
                'type' => 'R', 'name' => 'Unbilled Student', 'guardian_name' => 'Guardian',
                'phone' => '+92 300 1112226', 'cnic' => '35201-9998887-4',
            ],
            'course_ids' => [$course->id],
            'generate_challans' => false,
        ]);

        $this->assertCount(1, $result['admissions']);
        $this->assertCount(0, $result['challans'], 'The checkbox must actually suppress billing.');
        $this->assertNull($result['admissions'][0]->challan()->first());
    }

    // ---- One live enrolment per student per course ---------------------------

    public function test_a_student_cannot_be_enrolled_on_the_same_course_twice(): void
    {
        $course = Course::where('code', 'SHOP-101')->firstOrFail();
        $student = Student::firstOrFail();
        $service = app(RegistrationService::class);

        $service->register($this->admin(), ['student_id' => $student->id, 'course_ids' => [$course->id]]);

        $live = fn () => Admission::where('student_id', $student->id)
            ->where('course_id', $course->id)->where('status', '!=', 'cancelled')->count();

        $this->assertSame(1, $live());

        try {
            $service->register($this->admin(), ['student_id' => $student->id, 'course_ids' => [$course->id]]);
            $this->fail('A second live enrolment on the same course must be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('already enrolled', $e->getMessage());
        }

        // The point of the guard: one seat, one challan, one fee.
        $this->assertSame(1, $live());
    }

    public function test_the_database_refuses_a_duplicate_live_enrolment(): void
    {
        $course = Course::where('code', 'SHOP-101')->firstOrFail();
        $student = Student::firstOrFail();

        app(RegistrationService::class)->register($this->admin(), [
            'student_id' => $student->id, 'course_ids' => [$course->id],
        ]);

        // The service guard is for the message; the index is what holds under
        // two officers submitting the same registration at the same moment.
        $this->expectException(QueryException::class);

        Admission::create([
            'reg_no' => 'BBT-ADM-9999', 'student_id' => $student->id, 'course_id' => $course->id,
            'enrolled_by' => $this->admin()->id, 'status' => 'validated',
        ]);
    }

    public function test_a_cancelled_enrolment_frees_the_student_to_take_the_course_again(): void
    {
        $course = Course::where('code', 'SHOP-101')->firstOrFail();
        $student = Student::firstOrFail();
        $service = app(RegistrationService::class);

        $first = $service->register($this->admin(), [
            'student_id' => $student->id, 'course_ids' => [$course->id],
        ]);

        $first['admissions'][0]->update(['status' => 'cancelled', 'rejection_reason' => 'Changed their mind']);

        // Re-taking a course is legitimate. The constraint is on holding two
        // live enrolments at once, not on the pair ever occurring twice.
        $again = $service->register($this->admin(), [
            'student_id' => $student->id, 'course_ids' => [$course->id],
        ]);

        $this->assertNotNull($again['admissions'][0]);
        $this->assertSame(2, Admission::where('student_id', $student->id)->where('course_id', $course->id)->count());
    }

    public function test_the_wizard_marks_courses_the_student_already_holds(): void
    {
        $student = Student::firstOrFail();
        $enrolled = $student->admissions()->where('status', '!=', 'cancelled')->first();

        $this->assertNotNull($enrolled, 'The seed student must already hold an enrolment.');

        Livewire::actingAs($this->admin())
            ->test('pages.registrations')
            ->call('openWizard')
            ->set('mode', 'existing')
            ->set('pickedStudentId', $student->id)
            ->assertSet('courseIds', [])
            ->call('toggleCourse', $enrolled->course_id)
            // Refused, so nothing was selected.
            ->assertSet('courseIds', []);
    }

    public function test_an_officer_cannot_enrol_a_student_outside_their_scope(): void
    {
        $officer = $this->officer();

        // A student nobody in this officer's scope enrolled.
        $stranger = Student::create([
            'student_code' => 'BBT-R26-9999', 'type' => 'R', 'name' => 'Not Yours',
            'guardian_name' => 'Guardian', 'cnic' => '35201-7776665-4',
            'phone' => '+92 300 7776665', 'created_by' => $this->admin()->id,
        ]);

        $this->expectException(ModelNotFoundException::class);

        app(RegistrationService::class)->register($officer, [
            'student_id' => $stranger->id,
            'course_ids' => [Course::where('code', 'SHOP-101')->firstOrFail()->id],
        ]);
    }

    // ---- The payment method agreed at the counter ---------------------------

    /**
     * The voucher's "Payment Method" line, filled before any money arrives.
     *
     * It was always printed and always blank until payment landed, so every
     * challan handed over the counter asked a parent for money without saying
     * how to hand it over. `payment_method` records what was AGREED;
     * `paid_via` still records what actually happened.
     */
    public function test_a_registration_can_state_how_the_fee_is_to_be_paid(): void
    {
        $result = app(RegistrationService::class)->register($this->officer(), [
            'new_student' => [
                'type' => 'R', 'name' => 'Method Student', 'guardian_name' => 'Guardian',
                'phone' => '+92 300 1111111', 'cnic' => null,
            ],
            'course_ids' => [Course::where('code', 'WD-101')->firstOrFail()->id],
            'payment_method' => 'Bank transfer',
        ]);

        $this->assertSame('Bank transfer', $result['challans'][0]->payment_method);
        $this->assertNull($result['challans'][0]->paid_via,
            'Agreeing a method is not the same as having been paid.');
    }

    /** Blank is a real answer: not every registration has agreed one yet. */
    public function test_the_payment_method_is_optional(): void
    {
        $result = app(RegistrationService::class)->register($this->officer(), [
            'new_student' => [
                'type' => 'R', 'name' => 'No Method', 'guardian_name' => null,
                'phone' => '+92 300 2222222', 'cnic' => null,
            ],
            'course_ids' => [Course::where('code', 'WD-101')->firstOrFail()->id],
        ]);

        $this->assertNull($result['challans'][0]->payment_method);
    }

    /**
     * The wizard offers only the configured list, but the value arrives over
     * the wire and ends up PRINTED on a document a parent acts on.
     */
    public function test_an_unknown_payment_method_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(RegistrationService::class)->register($this->officer(), [
            'new_student' => [
                'type' => 'R', 'name' => 'Crafted', 'guardian_name' => null,
                'phone' => '+92 300 3333333', 'cnic' => null,
            ],
            'course_ids' => [Course::where('code', 'WD-101')->firstOrFail()->id],
            'payment_method' => 'Pay at the door, no receipt',
        ]);
    }
}
