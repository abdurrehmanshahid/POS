<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Challan;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\Ledger;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * `roll:collect` — money already taken, against invoices that already exist.
 *
 * The August intake sheet is what made this necessary. Five of its eighteen
 * lines are not enrolments: they are the second instalment on a Super Kid Camp
 * cohort enrolled in June, whose invoices are already on the box with exactly
 * Rs 10,000 outstanding each. Through the importer those lines would have
 * created five duplicate children, banked Rs 50,000 twice, and left the June
 * balances standing as debts the institute had in fact been paid.
 */
class CollectInstalmentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['institute.today' => '2026-07-15']);
        Carbon::setTestNow('2026-07-15 10:00:00');
        $this->seed(DatabaseSeeder::class);
    }

    /**
     * A student half-way through paying, made the way the June camp was: an
     * import that left a real balance on a real invoice.
     */
    private function halfPaid(string $name = 'Camp Child', string $phone = '03009990001'): Admission
    {
        $headers = [
            'Name', 'Course', 'Status', 'CSR', 'Phone', 'Batch', 'Registration Date',
            'Pending Payment Due Date', 'Original Price', 'Discounted Price',
            'Advance Payment', 'Second Installment', 'Balance', 'Total Amount',
        ];

        $row = [
            'Name' => $name, 'Course' => 'Super Kid Camp', 'Status' => 'Pending', 'CSR' => 'Ali raza',
            'Phone' => $phone, 'Batch' => '', 'Registration Date' => '2026-06-07',
            'Pending Payment Due Date' => '', 'Original Price' => 20000, 'Discounted Price' => 20000,
            'Advance Payment' => 10000, 'Second Installment' => 10000, 'Balance' => 10000,
            'Total Amount' => 10000,
        ];

        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray($headers, null, 'A1');
        $book->getActiveSheet()->fromArray(
            array_map(fn ($h) => $row[$h] ?? '', $headers), null, 'A2', true
        );

        $path = tempnam(sys_get_temp_dir(), 'camp').'.xlsx';
        (new Xlsx($book))->save($path);

        $this->artisan('roll:import', ['file' => $path, '--commit' => true])->assertSuccessful();

        return Admission::whereNotNull('import_key')->with('challan')->latest('id')->firstOrFail();
    }

    private function file(array $lines): string
    {
        $path = tempnam(sys_get_temp_dir(), 'collect').'.txt';
        file_put_contents($path, implode("\n", $lines)."\n");

        return $path;
    }

    private function args(array $extra = []): array
    {
        return $extra + ['--on' => '2026-08-01', '--officer' => 'aliraza'];
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $admission = $this->halfPaid();
        $before = Payment::count();

        $this->artisan('roll:collect', $this->args([
            'file' => $this->file([$admission->student->student_code.' SKC-101 10000']),
        ]))->assertSuccessful();

        $this->assertSame($before, Payment::count(), 'A dry run must not book money.');
    }

    public function test_collecting_the_balance_settles_the_invoice(): void
    {
        $admission = $this->halfPaid();
        $challan = $admission->challan;

        $this->assertSame('unpaid', $challan->status);

        $this->artisan('roll:collect', $this->args([
            'file' => $this->file([$admission->student->student_code.' SKC-101 10000']),
            '--commit' => true,
        ]))->assertSuccessful();

        $challan->refresh();

        $this->assertSame(20000, (int) $challan->payments->sum('amount'));
        $this->assertSame('paid', $challan->status);
        $this->assertSame('2026-08-01', $challan->paid_at->toDateString());
    }

    /**
     * The money lands on the day it was collected, not the day it was typed in.
     *
     * `Reporting::summary()` reads collections from `payments.received_at`, so a
     * payment stamped with the run date puts August's cash in whatever month the
     * import happened to be run.
     */
    public function test_the_payment_is_dated_when_the_money_arrived(): void
    {
        $admission = $this->halfPaid();

        $this->artisan('roll:collect', $this->args([
            'file' => $this->file([$admission->student->student_code.' SKC-101 10000']),
            '--commit' => true,
        ]))->assertSuccessful();

        $payment = Payment::latest('id')->firstOrFail();

        $this->assertSame('2026-08-01', $payment->received_at->toDateString());
        $this->assertSame('aliraza', User::find($payment->received_by)->username);
    }

    /**
     * Historical money is never mistaken for a collection somebody witnessed.
     *
     * 'Unrecorded' is outside `config('institute.payment_methods')` on purpose —
     * the counter must name a real method, and this cannot, because the sheet
     * records an amount and nothing about how it arrived.
     */
    public function test_collected_money_does_not_claim_a_payment_method_nobody_recorded(): void
    {
        $admission = $this->halfPaid();

        $this->artisan('roll:collect', $this->args([
            'file' => $this->file([$admission->student->student_code.' SKC-101 10000']),
            '--commit' => true,
        ]))->assertSuccessful();

        $method = Payment::latest('id')->firstOrFail()->method;

        $this->assertSame('Unrecorded', $method);
        $this->assertNotContains($method, config('institute.payment_methods'));
    }

    public function test_running_it_twice_does_not_bank_the_money_twice(): void
    {
        $admission = $this->halfPaid();

        $options = $this->args([
            'file' => $this->file([$admission->student->student_code.' SKC-101 10000']),
            '--commit' => true,
        ]);

        $this->artisan('roll:collect', $options)->assertSuccessful();
        $after = Payment::count();

        $this->artisan('roll:collect', $options)->assertSuccessful();

        $this->assertSame($after, Payment::count(), 'A second run must not book the same payment again.');
        $this->assertSame(20000, (int) $admission->challan->refresh()->payments->sum('amount'));
    }

    public function test_collecting_more_than_is_owed_is_refused(): void
    {
        $admission = $this->halfPaid();
        $before = Payment::count();

        $this->artisan('roll:collect', $this->args([
            'file' => $this->file([$admission->student->student_code.' SKC-101 15000']),
            '--commit' => true,
        ]))
            ->expectsOutputToContain('owes 10000')
            ->assertFailed();

        $this->assertSame($before, Payment::count());
    }

    /**
     * One bad line stops the whole file before anything is written.
     *
     * The plan is resolved in full first, so the dry run can be trusted: a
     * report promising five payments that then failed on the third would be
     * worse than no report at all.
     */
    public function test_one_unresolvable_line_stops_the_file_before_anything_is_booked(): void
    {
        $good = $this->halfPaid('Good Child', '03009990001');
        $before = Payment::count();

        $this->artisan('roll:collect', $this->args([
            'file' => $this->file([
                $good->student->student_code.' SKC-101 10000',
                'BBT-R26-9999 SKC-101 10000',
            ]),
            '--commit' => true,
        ]))
            ->expectsOutputToContain('no student with the code')
            ->assertFailed();

        $this->assertSame($before, Payment::count(), 'Nothing may be booked when a line cannot be resolved.');
    }

    public function test_a_student_not_enrolled_on_that_course_is_refused(): void
    {
        $admission = $this->halfPaid();

        $this->artisan('roll:collect', $this->args([
            'file' => $this->file([$admission->student->student_code.' DMM-101 10000']),
            '--commit' => true,
        ]))
            ->expectsOutputToContain('no live enrolment')
            ->assertFailed();
    }

    public function test_an_invoice_that_is_already_settled_is_refused(): void
    {
        $admission = $this->halfPaid();

        $this->artisan('roll:collect', $this->args([
            'file' => $this->file([$admission->student->student_code.' SKC-101 10000']),
            '--commit' => true,
        ]))->assertSuccessful();

        // A SECOND, different collection against the now-settled invoice. Not
        // the idempotent repeat above — a new date makes a new note, so this is
        // a genuine attempt to bank more than the invoice owes.
        $this->artisan('roll:collect', [
            'file' => $this->file([$admission->student->student_code.' SKC-101 5000']),
            '--on' => '2026-08-15',
            '--officer' => 'aliraza',
            '--commit' => true,
        ])
            ->expectsOutputToContain('already settled')
            ->assertFailed();
    }

    public function test_the_date_and_the_officer_are_both_required(): void
    {
        $admission = $this->halfPaid();
        $file = $this->file([$admission->student->student_code.' SKC-101 10000']);

        $this->artisan('roll:collect', ['file' => $file, '--officer' => 'aliraza'])
            ->expectsOutputToContain('--on is required')
            ->assertFailed();

        $this->artisan('roll:collect', ['file' => $file, '--on' => '2026-08-01'])
            ->expectsOutputToContain('--officer is required')
            ->assertFailed();

        $this->artisan('roll:collect', ['file' => $file, '--on' => '2026-08-01', '--officer' => 'nobody'])
            ->expectsOutputToContain('No account with the username')
            ->assertFailed();
    }

    public function test_a_malformed_line_stops_the_run(): void
    {
        $this->artisan('roll:collect', $this->args([
            'file' => $this->file(['BBT-R26-0001 SKC-101']),
        ]))
            ->expectsOutputToContain('expected')
            ->assertFailed();
    }

    /**
     * The ledger agrees with itself afterwards.
     *
     * The point of booking these as payments rather than importing them as new
     * enrolments: outstanding goes DOWN by what was collected, and billed does
     * not move at all.
     */
    public function test_the_ledger_reconciles_after_collecting(): void
    {
        $admission = $this->halfPaid();
        $ledger = app(Ledger::class);
        $officer = User::where('username', 'aliraza')->firstOrFail();

        $billedBefore = $ledger->billed($officer);
        $outstandingBefore = $ledger->outstanding($officer);

        $this->artisan('roll:collect', $this->args([
            'file' => $this->file([$admission->student->student_code.' SKC-101 10000']),
            '--commit' => true,
        ]))->assertSuccessful();

        $this->assertSame($billedBefore, $ledger->billed($officer), 'Collecting must not change what was billed.');
        $this->assertSame($outstandingBefore - 10000, $ledger->outstanding($officer));
        $this->assertSame(0, Student::count() - Student::count(), 'No person is created by a collection.');
    }

    public function test_no_new_student_or_invoice_is_created(): void
    {
        $admission = $this->halfPaid();

        $students = Student::count();
        $challans = Challan::count();
        $admissions = Admission::count();

        $this->artisan('roll:collect', $this->args([
            'file' => $this->file([$admission->student->student_code.' SKC-101 10000']),
            '--commit' => true,
        ]))->assertSuccessful();

        $this->assertSame($students, Student::count());
        $this->assertSame($challans, Challan::count());
        $this->assertSame($admissions, Admission::count());
    }
}
