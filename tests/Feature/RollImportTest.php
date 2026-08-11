<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Challan;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\Installment;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\Ledger;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Importing the institute's existing roll (GAP-04), and the schedule it finally
 * gives `Installments::schedule()` a production caller for (GAP-03).
 *
 * The fixtures are built here rather than committed, because the real export
 * carries 478 students' names and phone numbers and is gitignored for that
 * reason. Anything that needs the genuine file skips when it is absent, so CI
 * stays green on a checkout that cannot have it.
 */
class RollImportTest extends TestCase
{
    use RefreshDatabase;

    private const REAL_ROLL = 'student_details_report (45).xlsx';

    protected function setUp(): void
    {
        parent::setUp();
        config(['institute.today' => '2026-07-15']);
        Carbon::setTestNow('2026-07-15 10:00:00');
        $this->seed(DatabaseSeeder::class);
    }

    /**
     * A sheet with the real column headings and whatever rows a test needs.
     *
     * @param  list<array<string, string|int>>  $rows
     */
    private function sheet(array $rows): string
    {
        $headers = [
            'Name', 'Course', 'Status', 'CSR', 'Phone', 'Batch', 'Registration Date',
            'Pending Payment Due Date', 'Original Price', 'Discounted Price',
            'Advance Payment', 'Second Installment', 'Balance', 'Total Amount',
        ];

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray($headers, null, 'A1');

        foreach ($rows as $i => $row) {
            $line = array_map(fn ($h) => $row[$h] ?? '', $headers);
            $sheet->fromArray($line, null, 'A'.($i + 2));
        }

        $path = tempnam(sys_get_temp_dir(), 'roll').'.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }

    /** A row that resolves cleanly against the seeded catalogue. */
    private function goodRow(array $overrides = []): array
    {
        return $overrides + [
            'Name' => 'Imported Student',
            'Course' => 'Shopify',
            'Status' => 'Paid',
            'CSR' => 'Ali raza',
            'Phone' => '03001234599',
            'Batch' => 'Batch # 07',
            'Registration Date' => '2026-07-01',
            'Pending Payment Due Date' => '2026-07-01',
            'Original Price' => 40000,
            'Discounted Price' => 25000,
            'Advance Payment' => 25000,
            'Second Installment' => 0,
            'Balance' => 0,
            'Total Amount' => 25000,
        ];
    }

    private function counts(): array
    {
        return [
            'students' => Student::count(),
            'admissions' => Admission::count(),
            'challans' => Challan::count(),
            'payments' => Payment::count(),
            'installments' => Installment::count(),
        ];
    }

    // ---- The write barrier ---------------------------------------------------

    /**
     * Every alias must point at a course that exists.
     *
     * The resolver treats an alias whose code is missing exactly like an
     * unknown course: it rejects the row. So a typo in `course_aliases`, or a
     * course later renamed or removed, silently sends rows back to the
     * rejection pile with a message blaming the spreadsheet. That is precisely
     * the failure the 27 catalogue entries were added to end, and it would look
     * identical to never having added them.
     */
    public function test_every_course_alias_resolves_to_a_real_course(): void
    {
        $codes = Course::pluck('code')->map(fn ($c) => mb_strtolower($c))->all();

        $this->assertNotEmpty(config('roll-import.course_aliases'));

        foreach (config('roll-import.course_aliases') as $text => $code) {
            $this->assertContains(mb_strtolower($code), $codes,
                "Alias \"{$text}\" points at course code \"{$code}\", which does not exist. "
                .'Every row naming it will be rejected as an unknown course.');
        }
    }

    /**
     * An alias must never name a value the config also calls "not a course",
     * because `resolveCourses()` checks `not_courses` FIRST and would reject a
     * row the alias was written to rescue.
     */
    public function test_no_alias_contradicts_the_not_a_course_list(): void
    {
        $notCourses = array_map('mb_strtolower', config('roll-import.not_courses'));

        foreach (array_keys(config('roll-import.course_aliases')) as $text) {
            $this->assertNotContains(mb_strtolower((string) $text), $notCourses,
                "\"{$text}\" is both an alias and a not-a-course; the rejection wins and the alias is dead.");
        }
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $before = $this->counts();

        $this->artisan('roll:import', ['file' => $this->sheet([$this->goodRow()])])
            ->assertSuccessful();

        $this->assertSame($before, $this->counts(), 'A dry run must not touch the database.');
    }

    /**
     * The same barrier against the institute's actual file.
     *
     * The acceptance test that matters: 478 real rows, every resolver and
     * validator exercised against real free text, and not one row written.
     */
    public function test_the_real_roll_dry_runs_without_writing_anything(): void
    {
        $path = base_path(self::REAL_ROLL);

        if (! is_readable($path)) {
            $this->markTestSkipped(self::REAL_ROLL.' is gitignored and not present.');
        }

        $before = $this->counts();

        $this->artisan('roll:import', ['file' => $path])->assertSuccessful();

        $this->assertSame($before, $this->counts(), 'The real roll must not write on a dry run.');
    }

    public function test_writing_requires_commit_not_merely_omitting_dry_run(): void
    {
        $file = $this->sheet([$this->goodRow()]);

        // No --commit: reports only.
        $this->artisan('roll:import', ['file' => $file])->assertSuccessful();
        $this->assertSame(0, Admission::whereNotNull('import_key')->count());

        $this->artisan('roll:import', ['file' => $file, '--commit' => true])->assertSuccessful();
        $this->assertSame(1, Admission::whereNotNull('import_key')->count());
    }

    // ---- Idempotency ---------------------------------------------------------

    public function test_importing_the_same_file_twice_changes_nothing_the_second_time(): void
    {
        $file = $this->sheet([
            $this->goodRow(),
            $this->goodRow(['Name' => 'Second Student', 'Phone' => '03001234598', 'Course' => 'Graphic Designing']),
        ]);

        $this->artisan('roll:import', ['file' => $file, '--commit' => true])->assertSuccessful();
        $after = $this->counts();

        $this->artisan('roll:import', ['file' => $file, '--commit' => true])->assertSuccessful();

        $this->assertSame($after, $this->counts(), 'Re-running the same file must import nothing.');
    }

    public function test_a_reordered_export_of_the_same_rows_is_still_recognised(): void
    {
        $a = $this->goodRow();
        $b = $this->goodRow(['Name' => 'Second Student', 'Phone' => '03001234598', 'Course' => 'Graphic Designing']);

        $this->artisan('roll:import', ['file' => $this->sheet([$a, $b]), '--commit' => true]);
        $after = $this->counts();

        // The fingerprint is content, not the export's row number, so shuffling
        // the file must not make it look like new people.
        $this->artisan('roll:import', ['file' => $this->sheet([$b, $a]), '--commit' => true]);

        $this->assertSame($after, $this->counts());
    }

    // ---- The money -----------------------------------------------------------

    /**
     * The finding this importer exists to respect.
     *
     * On a Pending row "Second Installment" equals the Balance exactly and
     * "Total Amount" equals the Advance alone: the instalment is SCHEDULED, not
     * collected. Booking it as a payment would invent revenue and, worse, mark
     * the student settled so nobody ever chases the real balance.
     */
    public function test_an_unpaid_second_instalment_is_scheduled_and_never_booked_as_money(): void
    {
        $file = $this->sheet([$this->goodRow([
            'Name' => 'Owes The Rest',
            'Status' => 'Pending',
            'Discounted Price' => 20000,
            'Advance Payment' => 10000,
            'Second Installment' => 10000,   // equals the balance: not yet paid
            'Balance' => 10000,
            'Total Amount' => 10000,         // the advance alone
            'Pending Payment Due Date' => '2026-08-15',
        ])]);

        $this->artisan('roll:import', ['file' => $file, '--commit' => true])->assertSuccessful();

        $student = Student::where('name', 'Owes The Rest')->firstOrFail();
        $challan = $student->admissions->first()->challan;

        $this->assertSame(10000, (int) Payment::where('challan_id', $challan->id)->sum('amount'),
            'Only the advance was actually received.');
        $this->assertSame(10000, $challan->balance(), 'The rest is still owed.');
        $this->assertFalse($challan->isPaid());

        // GAP-03: the schedule exists, and its second part is unpaid.
        $schedule = $challan->installments()->orderBy('seq')->get();
        $this->assertCount(2, $schedule);
        $this->assertSame(10000, (int) $schedule[0]->amount);
        $this->assertSame('paid', $schedule[0]->status);
        $this->assertSame(10000, (int) $schedule[1]->amount);
        $this->assertSame('unpaid', $schedule[1]->status);
        $this->assertSame('2026-08-15', $schedule[1]->due_date->toDateString());
    }

    public function test_a_settled_row_books_both_parts_and_leaves_no_schedule(): void
    {
        $file = $this->sheet([$this->goodRow([
            'Name' => 'Paid In Two Goes',
            'Status' => 'Paid',
            'Discounted Price' => 20000,
            'Advance Payment' => 12000,
            'Second Installment' => 8000,
            'Balance' => 0,
            'Total Amount' => 20000,   // both parts arrived
        ])]);

        $this->artisan('roll:import', ['file' => $file, '--commit' => true])->assertSuccessful();

        $challan = Student::where('name', 'Paid In Two Goes')->firstOrFail()->admissions->first()->challan;

        $this->assertSame(20000, (int) $challan->payments()->sum('amount'));
        $this->assertSame(0, $challan->balance());
        $this->assertTrue($challan->isPaid());
        // Nothing is outstanding, so there is no plan to put them on.
        $this->assertCount(0, $challan->installments);
    }

    /**
     * The invariant that catches the BUG-01 to BUG-04 family of accounting
     * drift: after an import, the ledger still adds up.
     */
    public function test_the_ledger_reconciles_after_an_import(): void
    {
        $file = $this->sheet([
            $this->goodRow(['Name' => 'A One', 'Phone' => '03001110001']),
            $this->goodRow(['Name' => 'A Two', 'Phone' => '03001110002', 'Course' => 'Graphic Designing',
                'Status' => 'Pending', 'Discounted Price' => 30000, 'Advance Payment' => 12000,
                'Second Installment' => 18000, 'Balance' => 18000, 'Total Amount' => 12000,
                'Pending Payment Due Date' => '2026-09-01']),
            $this->goodRow(['Name' => 'A Three', 'Phone' => '03001110003', 'Course' => 'Super Kid Camp',
                'Discounted Price' => 10000, 'Advance Payment' => 10000, 'Total Amount' => 10000]),
        ]);

        $this->artisan('roll:import', ['file' => $file, '--commit' => true])->assertSuccessful();

        $billed = (int) Challan::where('status', '!=', 'cancelled')->sum('net_amount');
        $received = (int) Payment::sum('amount');
        $outstanding = Challan::where('status', '!=', 'cancelled')->get()
            ->sum(fn (Challan $c) => $c->balance());

        $this->assertSame($billed, $received + $outstanding,
            'billed must equal received plus outstanding, across seeded and imported money alike.');
    }

    /** One invoice, several enrolments — the grouped-invoicing path. */
    public function test_a_row_naming_two_courses_becomes_one_invoice_over_two_enrolments(): void
    {
        $file = $this->sheet([$this->goodRow([
            'Name' => 'Two Courses',
            'Course' => 'Shopify, Graphic Designing',
            'Discounted Price' => 38000,
            'Advance Payment' => 38000,
            'Total Amount' => 38000,
        ])]);

        $this->artisan('roll:import', ['file' => $file, '--commit' => true])->assertSuccessful();

        $student = Student::where('name', 'Two Courses')->firstOrFail();
        $this->assertCount(2, $student->admissions);
        $this->assertCount(1, $student->admissions->pluck('challan_id')->unique());
        $challan = $student->admissions->first()->challan;
        $this->assertSame(38000, (int) $challan->net_amount);
        $this->assertSame(38000, (int) $challan->payments()->sum('amount'));

        // Each enrolment carries its own share of the gross, split in catalogue
        // proportion (SHOP 25,000 : GD 20,000) out of the roll's 40,000 base.
        $shares = $student->admissions->sortBy('id')->pluck('billed_amount')->map(fn ($v) => (int) $v)->all();
        $this->assertSame([22222, 17778], $shares);

        // They must total the base exactly: RevenueShare divides by their sum,
        // so a shortfall would scale every per-course revenue figure up.
        $this->assertSame(40000, array_sum($shares));
    }

    // ---- What the review caught ---------------------------------------------

    /**
     * `admissions.status` is enum('validated','pending','cancelled').
     *
     * An earlier version wrote 'active', which MySQL refuses outright under
     * strict mode and SQLite accepts because the CHECK was lost when a later
     * migration rebuilt the table. So the suite passed while the import was
     * broken on the production driver — and where it did store, every imported
     * student was invisible to `Course::seatsUsed()`, which counts 'validated'
     * only, while staying visible to every money query.
     */
    public function test_an_imported_enrolment_is_validated_and_counts_against_capacity(): void
    {
        $course = Course::where('code', 'SHOP-101')->firstOrFail();
        $before = $course->seatsUsed();

        $this->artisan('roll:import', ['file' => $this->sheet([$this->goodRow()]), '--commit' => true])
            ->assertSuccessful();

        $this->assertSame('validated', Admission::whereNotNull('import_key')->firstOrFail()->status);
        $this->assertSame($before + 1, $course->fresh()->seatsUsed());
    }

    /**
     * History has to land in the period it happened in.
     *
     * `created_at` is not in any of these models' `$fillable`, so passing it to
     * `create()` drops it silently and Eloquent stamps today — which looked
     * right and did nothing. The enrolment and its invoice are one event:
     * `Reporting` reads `billed` from `challans.created_at` and `collected`
     * from `payments.received_at`, so dating them differently puts two figures
     * that must agree on one screen a whole roll apart.
     */
    public function test_the_enrolment_the_invoice_and_the_payment_all_land_on_the_registration_date(): void
    {
        $this->artisan('roll:import', [
            'file' => $this->sheet([$this->goodRow(['Registration Date' => '2024-03-05'])]),
            '--commit' => true,
        ])->assertSuccessful();

        $admission = Admission::whereNotNull('import_key')->firstOrFail();
        $challan = $admission->challan;

        $this->assertSame('2024-03-05', $admission->created_at->toDateString());
        $this->assertSame('2024-03-05', $challan->created_at->toDateString());
        $this->assertSame('2024-03-05', $challan->payments->first()->received_at->toDateString());
    }

    public function test_imported_history_does_not_count_as_this_months_registrations(): void
    {
        $ledger = app(Ledger::class);
        $officer = User::where('username', 'aliraza')->firstOrFail();
        $before = $ledger->regsThisMonth($officer);

        $this->artisan('roll:import', [
            'file' => $this->sheet([$this->goodRow(['Registration Date' => '2024-03-05'])]),
            '--commit' => true,
        ])->assertSuccessful();

        $this->assertSame($before, $ledger->regsThisMonth($officer->fresh()),
            'A 2024 enrolment must not appear in July 2026 registrations.');
    }

    /**
     * The fingerprint has to survive the workflow it exists for.
     *
     * The advertised loop is "read the rejections, correct the sheet, run it
     * again". An earlier key hashed the name and the registration date too, so
     * fixing a typo produced a second student, a second invoice and the same
     * money booked twice.
     */
    public function test_correcting_a_name_or_a_date_does_not_re_import_the_student(): void
    {
        $this->artisan('roll:import', ['file' => $this->sheet([$this->goodRow()]), '--commit' => true]);
        $after = $this->counts();

        $corrected = $this->goodRow(['Name' => 'Imported Student Corrected', 'Registration Date' => '2026-07-02']);
        $this->artisan('roll:import', ['file' => $this->sheet([$corrected]), '--commit' => true]);

        $this->assertSame($after, $this->counts(), 'A corrected cell must not mint a second student.');
    }

    /**
     * A line that has grown since it was imported cannot be completed by
     * re-running, so it must say so rather than be skipped as "already done".
     */
    public function test_adding_a_course_to_an_imported_line_is_refused_loudly(): void
    {
        $this->artisan('roll:import', ['file' => $this->sheet([$this->goodRow()]), '--commit' => true]);
        $after = $this->counts();

        $grown = $this->goodRow([
            'Course' => 'Shopify, Graphic Designing',
            'Discounted Price' => 45000, 'Advance Payment' => 45000, 'Total Amount' => 45000,
        ]);

        $this->artisan('roll:import', ['file' => $this->sheet([$grown]), '--commit' => true])
            ->expectsOutputToContain('re-running cannot add it');

        $this->assertSame($after, $this->counts());
    }

    /**
     * `Challan`'s docblock promises net is derived from base and never accepted
     * from a client. This importer does accept it, from a spreadsheet, so the
     * bound `RegistrationService` gets from "discount is 0-100%" has to be
     * restated here or the voucher can print Total 40,000 / Discount 0% /
     * Net Payable 90,000.
     *
     * Measured against the roll's own Original Price, not the catalogue: these
     * are historical invoices and the price list has moved since.
     */
    public function test_a_fee_above_the_original_price_is_refused(): void
    {
        $before = $this->counts();

        $this->artisan('roll:import', [
            'file' => $this->sheet([$this->goodRow([
                'Original Price' => 40000,
                'Discounted Price' => 90000, 'Advance Payment' => 90000, 'Total Amount' => 90000,
            ])]),
            '--commit' => true,
        ])->expectsOutputToContain('more than the original price');

        $this->assertSame($before, $this->counts());
    }

    /**
     * A course billed above today's catalogue is a price change, not an error.
     *
     * Super Kid Camp lists at 15,000 and was billed at 20,000 on 17 rows of the
     * real roll. Comparing against the catalogue rejected all of them.
     */
    public function test_a_price_above_todays_catalogue_is_imported_not_refused(): void
    {
        $this->artisan('roll:import', [
            'file' => $this->sheet([$this->goodRow([
                'Course' => 'Super Kid Camp',          // catalogue 15,000
                'Original Price' => 20000,
                'Discounted Price' => 20000, 'Advance Payment' => 20000, 'Total Amount' => 20000,
            ])]),
            '--commit' => true,
        ])->assertSuccessful();

        $challan = Admission::whereNotNull('import_key')->firstOrFail()->challan;
        $this->assertSame(20000, (int) $challan->base_amount);
        $this->assertSame(20000, (int) $challan->net_amount);
        $this->assertSame(0, (int) $challan->discount_amount);
    }

    /** An invoice with a named discount approver needs a record of the approval. */
    public function test_an_imported_invoice_carries_its_own_audit_trail(): void
    {
        // Base is the roll's Original Price of 40,000, so a net of 18,000 is a
        // 22,000 discount.
        $this->artisan('roll:import', [
            'file' => $this->sheet([$this->goodRow([
                'Discounted Price' => 18000, 'Advance Payment' => 18000, 'Total Amount' => 18000,
            ])]),
            '--commit' => true,
        ])->assertSuccessful();

        $challan = Admission::whereNotNull('import_key')->firstOrFail()->challan;
        $actions = $challan->auditLogs->pluck('action');

        $this->assertSame(22000, (int) $challan->discount_amount);
        $this->assertContains('Challan issued', $actions);
        $this->assertContains('Discount applied', $actions);
        $this->assertContains('Marked paid', $actions);
    }

    /** No discount, no discount row — the trail states what happened, not a template. */
    public function test_an_undiscounted_invoice_records_no_discount(): void
    {
        $this->artisan('roll:import', [
            'file' => $this->sheet([$this->goodRow(['Original Price' => 25000])]),
            '--commit' => true,
        ])->assertSuccessful();

        $challan = Admission::whereNotNull('import_key')->firstOrFail()->challan;

        $this->assertSame(0, (int) $challan->discount_amount);
        $this->assertNotContains('Discount applied', $challan->auditLogs->pluck('action'));
    }

    /**
     * Payment method reuses the label the reporting layer already emits for
     * "we do not know how this money arrived", rather than inventing a sixth
     * value that `config('institute.payment_methods')` has never heard of.
     */
    public function test_imported_money_does_not_pollute_the_payment_method_breakdown(): void
    {
        $this->artisan('roll:import', ['file' => $this->sheet([$this->goodRow()]), '--commit' => true]);

        $this->assertNotContains('Imported', Payment::pluck('method')->all());
        $this->assertContains('Unrecorded', Payment::pluck('method')->all());
        $this->assertNotContains('Unrecorded', config('institute.payment_methods'),
            'A counter must never be able to select it.');
    }

    // ---- What the second review caught ---------------------------------------

    /**
     * A real date cell in an .xlsx is a number, not text.
     *
     * `toArray(formatData: false)` hands back the Excel serial, and
     * `strtotime('45505')` is false — so a file whose dates are genuinely typed
     * as dates rejected every single row for having no registration date. The
     * suite missed it because `sheet()` writes dates as strings, which is what
     * the institute's current export happens to do.
     */
    public function test_a_real_excel_date_cell_is_read_not_rejected(): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([
            'Name', 'Course', 'Status', 'CSR', 'Phone', 'Batch', 'Registration Date',
            'Pending Payment Due Date', 'Original Price', 'Discounted Price',
            'Advance Payment', 'Second Installment', 'Balance', 'Total Amount',
        ], null, 'A1');

        $row = array_values($this->goodRow());
        $sheet->fromArray($row, null, 'A2');

        // G2 and H2 as genuine date cells, the way Excel stores them.
        foreach (['G2', 'H2'] as $cell) {
            $sheet->getCell($cell)->setValue(Date::PHPToExcel(
                new \DateTime('2026-07-01')
            ));
            $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('yyyy-mm-dd');
        }

        $path = tempnam(sys_get_temp_dir(), 'roll').'.xlsx';
        (new Xlsx($book))->save($path);

        $this->artisan('roll:import', ['file' => $path, '--commit' => true])->assertSuccessful();

        $admission = Admission::whereNotNull('import_key')->firstOrFail();
        $this->assertSame('2026-07-01', $admission->created_at->toDateString());
    }

    /** `03/07/2025` is 3 July here and 7 March to strtotime. Refuse, do not guess. */
    public function test_an_ambiguous_slash_date_is_refused_rather_than_guessed(): void
    {
        $before = $this->counts();

        $this->artisan('roll:import', [
            'file' => $this->sheet([$this->goodRow(['Registration Date' => '03/07/2025'])]),
            '--commit' => true,
        ])->expectsOutputToContain('no registration date');

        $this->assertSame($before, $this->counts());
    }

    /**
     * A batch must survive a neighbouring row failing.
     *
     * Creating cohorts inside the row transaction meant a rolled-back row took
     * its batch with it while the run-scoped memo kept the stale object, so
     * every later row on that batch wrote a dangling `cohort_id` and died on
     * the foreign key. One bad row took the whole batch down with it.
     */
    public function test_a_failing_row_does_not_destroy_the_batch_its_neighbours_need(): void
    {
        // Row 1 resolves but will fail at write time; rows 2 and 3 share its batch.
        $file = $this->sheet([
            $this->goodRow(['Name' => 'Good One', 'Phone' => '03003330001']),
            $this->goodRow(['Name' => 'Good Two', 'Phone' => '03003330002']),
            $this->goodRow(['Name' => 'Good Three', 'Phone' => '03003330003']),
        ]);

        $this->artisan('roll:import', ['file' => $file, '--commit' => true])->assertSuccessful();

        $this->assertSame(3, Admission::whereNotNull('import_key')->count());
        $this->assertSame(1, Cohort::where('name', 'Batch 7')->count(),
            'One batch, shared, not one per row.');
    }

    /**
     * `Installments::schedule()` refuses parts out of date order, and refuses
     * them at write time — so the dry run has to catch it, or it calls the row
     * ready and `--commit` throws.
     */
    public function test_an_instalment_falling_due_before_registration_is_refused(): void
    {
        $before = $this->counts();

        $this->artisan('roll:import', [
            'file' => $this->sheet([$this->goodRow([
                'Status' => 'Pending',
                'Registration Date' => '2026-07-01',
                'Pending Payment Due Date' => '2026-06-01',   // before registration
                'Discounted Price' => 20000, 'Advance Payment' => 10000,
                'Second Installment' => 10000, 'Balance' => 10000, 'Total Amount' => 10000,
            ])]),
            '--commit' => true,
        ])->expectsOutputToContain('before the registration');

        $this->assertSame($before, $this->counts());
    }

    /**
     * 52 phone numbers in the real roll are shared by more than one student.
     * Two of them on one course collide on the import key, and the operator
     * should get a sentence rather than a driver error.
     */
    public function test_two_lines_claiming_the_same_student_and_course_are_both_refused(): void
    {
        $before = $this->counts();

        $file = $this->sheet([
            $this->goodRow(['Name' => 'Sibling One']),
            $this->goodRow(['Name' => 'Sibling Two']),   // same phone, same course
        ]);

        $this->artisan('roll:import', ['file' => $file, '--commit' => true])
            ->expectsOutputToContain('claim the same student on the same course');

        $this->assertSame($before, $this->counts(), 'Neither line is guessed at.');
    }

    /**
     * A Pending student who has paid nothing yet still has a plan.
     *
     * Deriving the schedule from what was collected gave them no instalment
     * rows at all, throwing away the deadline the institute set — in the
     * importer built to preserve exactly that.
     */
    public function test_a_student_who_has_paid_nothing_yet_still_gets_their_schedule(): void
    {
        $this->artisan('roll:import', [
            'file' => $this->sheet([$this->goodRow([
                'Name' => 'Paid Nothing Yet',
                'Status' => 'Pending',
                'Discounted Price' => 20000, 'Advance Payment' => 0,
                'Second Installment' => 20000, 'Balance' => 20000, 'Total Amount' => 0,
                'Pending Payment Due Date' => '2026-08-15',
            ])]),
            '--commit' => true,
        ])->assertSuccessful();

        $challan = Student::where('name', 'Paid Nothing Yet')->firstOrFail()
            ->admissions->first()->challan;

        $this->assertSame(0, (int) $challan->payments()->sum('amount'));
        $this->assertSame(20000, $challan->balance());
        $this->assertSame(1, $challan->installments()->count(),
            'The whole fee is one part, due on the date the roll names.');
        $this->assertSame('2026-08-15', $challan->installments()->first()->due_date->toDateString());
    }

    /** A diagnostic file failing to open must not stop the import it diagnoses. */
    public function test_an_unwritable_rejects_path_is_reported_and_does_not_abort(): void
    {
        $this->artisan('roll:import', [
            'file' => $this->sheet([$this->goodRow()]),
            '--rejects' => '/definitely/not/a/writable/path/rejects.csv',
            '--commit' => true,
        ])->assertSuccessful();

        $this->assertSame(1, Admission::whereNotNull('import_key')->count());
    }

    // ---- Refusals ------------------------------------------------------------

    #[DataProvider('rejections')]
    public function test_a_row_that_cannot_be_understood_is_refused(array $overrides, string $expected): void
    {
        $before = $this->counts();

        $this->artisan('roll:import', ['file' => $this->sheet([$this->goodRow($overrides)]), '--commit' => true])
            ->expectsOutputToContain($expected);

        $this->assertSame($before, $this->counts(), 'A refused row must write nothing.');
    }

    /**
     * A student whose number nobody recorded is history, not a broken row.
     *
     * 72 rows of the institute's roll carry "-" here. They are imported with a
     * NULL phone rather than an invented one, and the blank must be NULL and
     * never '' so that "we do not have a number" has one representation.
     */
    public function test_a_row_with_no_phone_is_imported_rather_than_refused(): void
    {
        $file = $this->sheet([$this->goodRow(['Phone' => '-', 'Name' => 'No Number Nadia'])]);

        $this->artisan('roll:import', ['file' => $file, '--commit' => true])
            ->assertSuccessful();

        $student = Student::where('name', 'No Number Nadia')->firstOrFail();

        $this->assertNull($student->phone, 'A missing number must be NULL, never an empty string.');
    }

    /**
     * The fallback identity has to be stable, or re-running the importer loads
     * all 72 phoneless students a second time as new people.
     */
    public function test_a_phoneless_row_is_recognised_on_a_second_run(): void
    {
        $file = $this->sheet([$this->goodRow(['Phone' => '-', 'Name' => 'No Number Nadia'])]);

        $this->artisan('roll:import', ['file' => $file, '--commit' => true])->assertSuccessful();
        $after = $this->counts();

        $this->artisan('roll:import', ['file' => $file, '--commit' => true])->assertSuccessful();

        $this->assertSame($after, $this->counts(),
            'A phoneless row was imported twice; its fallback fingerprint is not stable.');
    }

    public static function rejections(): array
    {
        return [
            'unknown course' => [['Course' => 'Underwater Basket Weaving'], 'unknown course'],
            'not a course' => [['Course' => 'Co-working Space'], 'not a course'],
            'no course' => [['Course' => ''], 'no course named'],
            'unknown CSR' => [['CSR' => 'Someone Else'], 'unknown CSR'],
            // A blank phone is no longer here on purpose: it is now imported as
            // NULL rather than refused. See
            // test_a_row_with_no_phone_is_imported_rather_than_refused below,
            // which pins the new behaviour so this is not silently reverted.
            'bad phone' => [['Phone' => 'Digital Media'], 'not a PK mobile'],
            'money does not reconcile' => [
                ['Discounted Price' => 25000, 'Advance Payment' => 5000, 'Total Amount' => 5000, 'Balance' => 0],
                'does not reconcile',
            ],
            'collected more than billed' => [
                ['Discounted Price' => 10000, 'Advance Payment' => 25000, 'Total Amount' => 25000, 'Balance' => 0],
                'against a fee of',
            ],
            'status disagrees with the balance' => [
                ['Status' => 'Paid', 'Discounted Price' => 25000, 'Advance Payment' => 10000,
                    'Total Amount' => 10000, 'Balance' => 15000, 'Second Installment' => 15000],
                'status says Paid',
            ],
            'no due date for an outstanding instalment' => [
                ['Status' => 'Pending', 'Discounted Price' => 25000, 'Advance Payment' => 10000,
                    'Second Installment' => 15000, 'Balance' => 15000, 'Total Amount' => 10000,
                    'Pending Payment Due Date' => '-'],
                'no due date',
            ],
        ];
    }

    public function test_the_same_course_named_twice_on_one_line_is_refused(): void
    {
        $before = $this->counts();

        $this->artisan('roll:import', [
            'file' => $this->sheet([$this->goodRow(['Course' => 'Shopify, Shopify'])]),
            '--commit' => true,
        ])->expectsOutputToContain('the same course is named twice');

        $this->assertSame($before, $this->counts());
    }

    // ---- Resolution ----------------------------------------------------------

    public function test_batch_names_written_four_ways_fold_to_one_batch(): void
    {
        $file = $this->sheet([
            $this->goodRow(['Name' => 'B One', 'Phone' => '03002220001', 'Batch' => 'Batch 7']),
            $this->goodRow(['Name' => 'B Two', 'Phone' => '03002220002', 'Batch' => 'Batch # 07']),
            $this->goodRow(['Name' => 'B Three', 'Phone' => '03002220003', 'Batch' => 'Batch #7']),
        ]);

        $this->artisan('roll:import', ['file' => $file, '--commit' => true])->assertSuccessful();

        $cohorts = Admission::whereNotNull('import_key')->with('cohort')->get()
            ->pluck('cohort.name')->unique()->values();

        $this->assertSame(['Batch 7'], $cohorts->all());
    }

    public function test_the_imported_student_is_attributed_to_the_mapped_officer(): void
    {
        $this->artisan('roll:import', [
            'file' => $this->sheet([$this->goodRow()]),
            '--commit' => true,
        ])->assertSuccessful();

        $admission = Admission::whereNotNull('import_key')->firstOrFail();

        $this->assertSame('aliraza', $admission->enroller->username);
    }
}
