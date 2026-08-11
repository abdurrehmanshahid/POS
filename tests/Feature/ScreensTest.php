<?php

namespace Tests\Feature;

use App\Http\Controllers\ReceiptController;
use App\Models\Challan;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\ChallanActions;
use App\Support\Format;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** End-to-end render + auth + scoping smoke tests for the screens and controllers. */
class ScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-15 10:00:00');
        config(['institute.today' => null]);
        $this->seed(DatabaseSeeder::class);
    }

    /**
     * The Administrator role requires a second factor, so an admin that has not
     * enrolled is pinned to the setup screen. Tests that want to reach a real
     * screen enrol first; the gate itself is asserted in TwoFactorTest.
     */
    private function admin(): User
    {
        return $this->enrolTwoFactor(User::where('username', 'adminansar')->firstOrFail());
    }

    private function officer(): User
    {
        return User::where('username', 'aliraza')->firstOrFail();
    }

    public function test_login_page_renders(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Sign in')
            ->assertSee('adminansar');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_admin_dashboard_shows_reconciled_revenue(): void
    {
        $this->actingAs($this->admin())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('TOTAL BILLED')
            ->assertSee('Rs 214,000')
            ->assertSee('Rs 119,000')
            ->assertSee('Active students');
    }

    public function test_officer_dashboard_hides_money_and_flips_labels(): void
    {
        $this->actingAs($this->officer())
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('TOTAL BILLED')
            ->assertDontSee('Rs 214,000')
            ->assertSee('My active students');
    }

    public function test_officer_cannot_reach_admin_only_screens(): void
    {
        $officer = $this->officer();
        $this->actingAs($officer)->get('/courses')->assertForbidden();
        $this->actingAs($officer)->get('/staff')->assertForbidden();
        $this->actingAs($officer)->get('/settings')->assertForbidden();
        $this->actingAs($officer)->get('/reports')->assertForbidden();
        $this->actingAs($officer)->get('/datamodel')->assertForbidden();
    }

    public function test_officer_can_reach_permitted_screens(): void
    {
        $officer = $this->officer();
        $this->actingAs($officer)->get('/registrations')->assertOk();
        $this->actingAs($officer)->get('/challans')->assertOk();
        $this->actingAs($officer)->get('/students')->assertOk();
    }

    // ---- Receipts (GAP-05) --------------------------------------------------

    /**
     * The rule the whole receipt exists for: a reprint must not contradict the
     * copy the student is already holding.
     *
     * A student pays Rs 10,000 of Rs 25,000 and is handed a receipt saying
     * Rs 15,000 remains. They pay the rest next week. Reprinting the FIRST
     * receipt must still say Rs 15,000 — computing the balance from today's
     * ledger would reprint it as Rs 0, and two documents describing one payment
     * would disagree about what happened.
     */
    public function test_a_reprinted_receipt_still_shows_the_balance_as_at_that_payment(): void
    {
        $challan = Challan::where('challan_no', 'BBT-CH-2026-1076')->firstOrFail(); // unpaid, net 25000
        $actions = app(ChallanActions::class);

        $first = $actions->recordPayment($challan, $this->admin(), 10000, 'Cash');
        $first = $challan->fresh()->payments()->orderBy('id')->first();

        $balanceAfterFirst = $this->receiptBalance($first);
        $this->assertSame(15000, $balanceAfterFirst);

        // The rest is collected later.
        $actions->recordPayment($challan->fresh(), $this->admin(), 15000, 'Cash');

        $this->assertSame(15000, $this->receiptBalance($first->fresh()),
            'Reprinting the first receipt must reproduce the original facts, not recompute them.');
    }

    /** The balance a receipt would print for a given payment. */
    private function receiptBalance(Payment $payment): int
    {
        $method = new \ReflectionMethod(ReceiptController::class, 'balanceAfter');

        return $method->invoke(app(ReceiptController::class), $payment->load('challan.payments'));
    }

    /**
     * The receipt number is derived from the payment id, not allocated, so
     * printing twice cannot produce two different numbers for one payment.
     */
    public function test_a_receipt_number_is_stable_across_reprints(): void
    {
        $challan = Challan::where('challan_no', 'BBT-CH-2026-1076')->firstOrFail();
        app(ChallanActions::class)->recordPayment($challan, $this->admin(), 5000, 'Cash');

        $payment = $challan->fresh()->payments()->orderBy('id')->first();

        $this->assertSame($payment->receiptNo(), $payment->fresh()->receiptNo());
        $this->assertStringContainsString('RC-', $payment->receiptNo());
    }

    public function test_a_receipt_renders_as_a_pdf_and_is_scoped_like_the_voucher(): void
    {
        $challan = Challan::where('challan_no', 'BBT-CH-2026-1076')->firstOrFail(); // enrolled_by admin
        app(ChallanActions::class)->recordPayment($challan, $this->admin(), 10000, 'Cash');
        $payment = $challan->fresh()->payments()->orderBy('id')->first();

        $this->actingAs($this->admin())
            ->get(route('payments.receipt', $payment))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        // The receipt names the student, so it cannot be laxer than the voucher.
        $this->actingAs($this->officer())
            ->get(route('payments.receipt', $payment))
            ->assertForbidden();
    }

    /** Printing a receipt must never write anything; it reports the ledger. */
    public function test_printing_a_receipt_changes_nothing(): void
    {
        $challan = Challan::where('challan_no', 'BBT-CH-2026-1076')->firstOrFail();
        app(ChallanActions::class)->recordPayment($challan, $this->admin(), 10000, 'Cash');
        $payment = $challan->fresh()->payments()->orderBy('id')->first();

        $before = [Payment::count(), Challan::count(), $challan->fresh()->balance()];

        $this->actingAs($this->admin())->get(route('payments.receipt', $payment))->assertOk();

        $this->assertSame($before, [Payment::count(), Challan::count(), $challan->fresh()->balance()]);
    }

    public function test_challan_pdf_is_permission_gated_and_scoped(): void
    {
        $challan = Challan::where('challan_no', 'BBT-CH-2026-1076')->firstOrFail(); // enrolled_by admin(1)

        $this->actingAs($this->admin())
            ->get(route('challans.pdf', $challan))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        // Officer(2) did not enrol this one -> forbidden by scope.
        $this->actingAs($this->officer())
            ->get(route('challans.pdf', $challan))
            ->assertForbidden();
    }

    /**
     * The voucher prints three copies, one each for the student, head office and
     * the campus, matching the form the institute already hands over the
     * counter. Rendered rather than asserted on the blade, because a DomPDF
     * template that throws only does so at render time.
     */
    public function test_the_challan_pdf_renders_three_copies_with_advance_and_balance(): void
    {
        $challan = Challan::where('challan_no', 'BBT-CH-2026-1076')->firstOrFail(); // unpaid, net 25000
        app(ChallanActions::class)->recordPayment($challan, $this->admin(), 10000, 'Cash');

        $html = view('challans.pdf', [
            'challan' => $challan->fresh()->load('admission.student', 'admission.course', 'admission.cohort', 'admission.enroller', 'payments'),
            'settings' => Setting::current(),
        ])->render();

        foreach (['Student Copy', 'Head Office Copy', 'Campus Copy'] as $copy) {
            $this->assertStringContainsString($copy, $html);
        }

        $this->assertStringContainsString('Advance Payment', $html);
        $this->assertStringContainsString('Balance', $html);
        $this->assertStringContainsString('PART PAID', $html, 'A partly collected challan is neither paid nor unpaid.');
        $this->assertStringContainsString(Format::money(10000), $html);
        $this->assertStringContainsString(Format::money(15000), $html);
        $this->assertStringContainsString('BBT-CH-2026-1076', $html);

        // And it survives an actual PDF render.
        $this->actingAs($this->admin())
            ->get(route('challans.pdf', $challan))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_students_export_streams_csv_with_bom(): void
    {
        $res = $this->actingAs($this->admin())->get(route('students.export'));
        $res->assertOk();
        $this->assertStringContainsString('BBT Students.csv', $res->headers->get('content-disposition'));
        $body = $res->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString('Student ID,Name,Type', $body);
        $this->assertStringContainsString('BBT-R26-0009', $body);
    }

    public function test_admin_can_render_every_screen(): void
    {
        $admin = $this->admin();
        foreach (['dashboard', 'registrations', 'challans', 'courses', 'students', 'staff', 'reports', 'datamodel', 'settings'] as $r) {
            $this->actingAs($admin)->get('/'.$r)->assertOk();
        }
    }

    public function test_registration_wizard_creates_enrolment_with_next_serials(): void
    {
        $officer = $this->officer();
        $wd = Course::where('code', 'WD-101')->firstOrFail();

        Livewire::actingAs($officer)->test('pages.registrations')
            ->call('openWizard')
            ->set('mode', 'new')
            ->set('newName', 'Wizard Test')
            ->set('newGuardian', 'Guardian X')
            ->set('newPhone', '+92 300 1112223')
            ->set('newCnic', '35201-0000000-9')
            ->call('next')                 // step 1 -> 2
            ->assertSet('step', 2)
            ->call('toggleCourse', $wd->id)
            ->call('next')                 // step 2 -> 3
            ->assertSet('step', 3)
            ->call('submit');

        $this->assertDatabaseHas('students', ['name' => 'Wizard Test', 'student_code' => 'BBT-R26-0011']);
        $this->assertDatabaseHas('admissions', ['reg_no' => 'BBT-ADM-0012', 'enrolled_by' => $officer->id]);
        $this->assertDatabaseHas('challans', ['challan_no' => 'BBT-CH-2026-1086', 'base_amount' => 20000]);
    }

    public function test_wizard_blocks_invalid_new_student(): void
    {
        Livewire::actingAs($this->officer())->test('pages.registrations')
            ->call('openWizard')
            ->set('mode', 'new')
            ->set('newName', '')
            ->set('newPhone', '12345')      // invalid PK mobile
            ->set('newCnic', 'bad')
            ->call('next')
            ->assertSet('step', 1);         // stays on step 1
    }

    public function test_challans_mark_paid_flow(): void
    {
        $challan = Challan::where('challan_no', 'BBT-CH-2026-1077')->firstOrFail(); // unpaid

        Livewire::actingAs($this->admin())->test('pages.challans')
            ->call('askPay', $challan->id)
            ->set('payMethod', 'Cash')
            ->call('confirmPay');

        $this->assertSame('paid', $challan->fresh()->status);
        $this->assertSame('Cash', $challan->fresh()->paid_via);
    }

    public function test_courses_save_creates_course(): void
    {
        Livewire::actingAs($this->admin())->test('pages.courses')
            ->call('addCourse')
            ->set('code', 'TEST-900')
            ->set('title', 'Test Course')
            ->set('fee', 15000)
            ->call('save');

        $this->assertDatabaseHas('courses', ['code' => 'TEST-900', 'fee' => 15000]);
    }

    // ---- Invoices that bill no enrolment ------------------------------------

    /**
     * An invoice for a service — a desk, a certificate — with no admission.
     *
     * Built directly rather than imported, so these tests pin the SHAPE rather
     * than the importer that happens to produce it today. The counter will
     * raise these too.
     */
    private function charge(array $overrides = []): Challan
    {
        $officer = $this->officer();

        $challan = Challan::create($overrides + [
            'challan_no' => 'BBT-CH-2026-9001',
            'admission_id' => null,
            'student_id' => Student::first()->id,
            'raised_by' => $officer->id,
            'description' => 'Co-working Space',
            'base_amount' => 15000,
            'discount_amount' => 0,
            'net_amount' => 15000,
            'plan' => 'full',
            'due_date' => '2026-07-30',
            'status' => 'unpaid',
        ]);

        Payment::create([
            'challan_id' => $challan->id,
            'amount' => 15000,
            'method' => 'Cash',
            'received_by' => $officer->id,
            'received_at' => '2026-07-15',
        ]);

        return $challan->refresh();
    }

    /**
     * Every screen that lists invoices must survive one with no admission.
     *
     * The regression for a hard 500 on `/challans`: the list read
     * `$c->admission->student->name`, a charge has no admission, and the whole
     * screen died — not that row, everybody's. The same chain was written in
     * six places, so this walks all of them rather than only the one reported.
     *
     * Rendered rather than unit-tested, because the fault was never inside a
     * method anything called directly. It was the assumption, held in Blade,
     * that `admission` is always there.
     */
    public function test_every_screen_survives_an_invoice_with_no_enrolment(): void
    {
        $charge = $this->charge();
        $admin = $this->admin();

        foreach (['/dashboard', '/challans', '/students', '/reports'] as $screen) {
            $this->actingAs($admin)->get($screen)->assertOk();
        }

        // The drawer, where most of the `admission->` chains lived.
        $this->actingAs($admin)->get('/challans?open='.$charge->id)->assertOk();
    }

    /**
     * Both PDFs, whose controllers tested `admission->enrolled_by` directly —
     * a 500 rather than a permission decision on an invoice with no admission.
     */
    public function test_a_charge_prints_a_voucher_and_a_receipt(): void
    {
        $charge = $this->charge();

        $this->actingAs($this->admin())
            ->get(route('challans.pdf', $charge))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->actingAs($this->admin())
            ->get(route('payments.receipt', $charge->payments->sole()))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    /**
     * The officer who raised a charge may reach it; another officer may not.
     *
     * A charge has no `enrolled_by`, so the scope rule has to fall back to
     * `raised_by`. Without this the answer was an exception, which fails
     * *open* in the sense that matters: it tells you nothing about who is
     * allowed.
     */
    public function test_a_charge_is_scoped_to_the_officer_who_raised_it(): void
    {
        $charge = $this->charge();
        $other = User::where('username', 'fatimanoor')->firstOrFail();

        $this->actingAs($this->officer())->get(route('challans.pdf', $charge))->assertOk();
        $this->actingAs($other)->get(route('challans.pdf', $charge))->assertForbidden();
    }

    /** An overdue charge belongs on the dashboard beside an overdue fee. */
    public function test_an_overdue_charge_reaches_the_dashboard(): void
    {
        $this->charge(['due_date' => '2026-06-01', 'challan_no' => 'BBT-CH-2026-9002']);

        $this->actingAs($this->admin())->get('/dashboard')
            ->assertOk()
            ->assertSee('Co-working Space');
    }
}
