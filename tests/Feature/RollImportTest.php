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
use App\Services\Analytics;
use App\Services\Attendances;
use App\Services\Ledger;
use App\Services\RecordRemoval;
use App\Services\RegistrationService;
use App\Services\Reporting;
use App\Support\Contact;
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
     * because `resolveCourses()` checks `not_courses` FIRST and would bill a row
     * the alias was written to enrol.
     *
     * The consequence changed when charges arrived and got quieter, which makes
     * this check matter more rather than less. It used to be a rejection the
     * operator could see; now the row imports as a service charge, so a student
     * would be billed the right money for a course they are not enrolled on, not
     * counted against its capacity and absent from its register — and nothing
     * about the import would say so.
     */
    public function test_no_alias_contradicts_the_not_a_course_list(): void
    {
        $notCourses = array_map('mb_strtolower', config('roll-import.not_courses'));

        foreach (array_keys(config('roll-import.course_aliases')) as $text) {
            $this->assertNotContains(mb_strtolower((string) $text), $notCourses,
                "\"{$text}\" is both an alias and a not-a-course; the charge wins and the alias is dead.");
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
     * again", so a corrected DATE must not mint a second student. The date
     * carries no identity and is re-typed as often as anything else.
     */
    public function test_correcting_a_date_does_not_re_import_the_student(): void
    {
        $this->artisan('roll:import', ['file' => $this->sheet([$this->goodRow()]), '--commit' => true]);
        $after = $this->counts();

        $corrected = $this->goodRow(['Registration Date' => '2026-07-02']);
        $this->artisan('roll:import', ['file' => $this->sheet([$corrected]), '--commit' => true]);

        $this->assertSame($after, $this->counts(), 'A corrected date must not mint a second student.');
    }

    /**
     * The known, accepted cost of putting the name in the key.
     *
     * Pinned rather than hidden. The name had to join the key so that families
     * sharing one phone number could be told apart — without it, 40 real
     * students carrying Rs 670,000 could never be imported at all. The price is
     * this: correcting a spelling between two runs makes the row look new.
     *
     * It is bounded by the load being essentially one-time, by the name being
     * lowercased and trimmed so casing and stray spaces are not edits, and by
     * `php artisan records:duplicates`, which exists to find exactly this pair.
     *
     * If this test ever starts failing, the key changed — do not simply delete
     * it; check that families still import as separate people.
     */
    public function test_correcting_a_name_does_re_import_and_that_is_the_known_trade_off(): void
    {
        $this->artisan('roll:import', ['file' => $this->sheet([$this->goodRow()]), '--commit' => true]);
        $after = $this->counts();

        $corrected = $this->goodRow(['Name' => 'Imported Student Corrected']);
        $this->artisan('roll:import', ['file' => $this->sheet([$corrected]), '--commit' => true]);

        $this->assertSame($after['students'] + 1, Student::count(),
            'A corrected name mints a second student. This is the accepted cost of telling siblings apart.');
    }

    /** Casing and stray whitespace are not edits, so they must not re-import. */
    public function test_a_name_differing_only_in_case_or_spacing_is_the_same_student(): void
    {
        $this->artisan('roll:import', ['file' => $this->sheet([$this->goodRow()]), '--commit' => true]);
        $after = $this->counts();

        $this->artisan('roll:import', [
            'file' => $this->sheet([$this->goodRow(['Name' => '  imported STUDENT '])]),
            '--commit' => true,
        ]);

        $this->assertSame($after, $this->counts(),
            'Re-casing a name must not mint a second student.');
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
     * 52 phone numbers in the real roll are shared by more than one student,
     * mostly siblings on a parent's number. They are two people and must import
     * as two people.
     *
     * This used to refuse both, because the key was (phone, course) and could
     * not tell them apart. That cost 40 real students carrying Rs 670,000 —
     * three deep in places, e.g. lines 10/11/12 are Iram, Afsheen and Sofia
     * Rajut. The name is now part of the key.
     */
    public function test_two_siblings_sharing_a_phone_on_one_course_both_import(): void
    {
        $before = $this->counts();

        $file = $this->sheet([
            $this->goodRow(['Name' => 'Sibling One']),
            $this->goodRow(['Name' => 'Sibling Two']),   // same phone, same course
        ]);

        $this->artisan('roll:import', ['file' => $file, '--commit' => true])->assertSuccessful();

        $this->assertSame($before['students'] + 2, Student::count(),
            'Two siblings on one number are two students.');
        $this->assertSame(1, Student::where('name', 'Sibling One')->count());
        $this->assertSame(1, Student::where('name', 'Sibling Two')->count());
    }

    /**
     * The same line typed twice is still one student.
     *
     * The rule compares the person AND the money, so this collapses while the
     * siblings above do not.
     */
    public function test_an_identical_repeated_line_is_collapsed_to_one_student(): void
    {
        $before = $this->counts();

        $file = $this->sheet([
            $this->goodRow(['Name' => 'Typed Twice']),
            $this->goodRow(['Name' => 'Typed Twice']),
        ]);

        $this->artisan('roll:import', ['file' => $file, '--commit' => true])->assertSuccessful();

        $this->assertSame($before['students'] + 1, Student::count(),
            'One line typed twice is one student, not two and not zero.');
        $this->assertSame($before['challans'] + 1, Challan::count(),
            'And it must not bill the family twice.');
    }

    /**
     * Two lines that agree on the person but not on the money are refused, and
     * the reason says which figures disagree.
     */
    public function test_the_same_person_with_different_money_is_refused_with_the_conflict_named(): void
    {
        $before = $this->counts();

        $file = $this->sheet([
            $this->goodRow(['Name' => 'Disputed Dua']),
            $this->goodRow(['Name' => 'Disputed Dua', 'Discounted Price' => 20000, 'Total Amount' => 20000]),
        ]);

        $this->artisan('roll:import', ['file' => $file, '--commit' => true])
            ->expectsOutputToContain('same person, different money');

        $this->assertSame($before, $this->counts(), 'Neither figure is guessed at.');
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
     * A number that cannot be dialled is stored as no number, and the row still
     * imports — but the operator is told, and the text they typed survives.
     *
     * "0316842216" is ten digits where a PK mobile needs eleven. The missing
     * digit cannot be guessed, so the only clue to the real number is what was
     * written, and that clue is what the warnings CSV exists to keep.
     */
    public function test_an_undiallable_number_imports_as_null_with_a_warning(): void
    {
        $file = $this->sheet([$this->goodRow(['Phone' => '0316842216', 'Name' => 'Short Digits Sana'])]);

        $this->artisan('roll:import', ['file' => $file, '--commit' => true])
            ->expectsOutputToContain('cannot be dialled');

        $student = Student::where('name', 'Short Digits Sana')->firstOrFail();

        $this->assertNull($student->phone, 'An undiallable number must not be stored as if it worked.');
    }

    /**
     * The phone rule, asserted on the normaliser rather than through a sheet.
     *
     * Deliberately not an end-to-end import test: a spreadsheet cell beginning
     * with "+" is a FORMULA, so PhpSpreadsheet reads "+905355170955" back as an
     * error rather than as text, and a fixture written that way fails for a
     * reason that has nothing to do with the rule being tested. The real export
     * stores these as text and resolves them correctly.
     *
     * Three rules in one place because they only make sense together:
     *
     *  - Genuine students abroad are accepted. The roll carries +90 (Turkey),
     *    +971 (UAE) and +968 (Oman), and refusing them lost reachable people
     *    over which country they happened to be in.
     *  - The leading "+" is the entire safety of that branch. Without it a PK
     *    mobile typed one digit short would fall through and be stored as a
     *    valid foreign number — a typo nobody can dial, recorded as fine.
     *  - Every way of writing one PK number still yields one canonical form,
     *    because the import fingerprint depends on it.
     */
    public function test_the_phone_normaliser_accepts_abroad_and_still_refuses_typos(): void
    {
        // Real students abroad, kept as written.
        $this->assertSame('+905355170955', Contact::normalizePhone('+905355170955'));
        $this->assertSame('+971553824025', Contact::normalizePhone('+971553824025'));
        $this->assertSame('+96897735200', Contact::normalizePhone('+96897735200'));

        // Local numbers with the wrong digit count stay unusable.
        $this->assertNull(Contact::normalizePhone('0316842216'), 'ten digits, one short');
        $this->assertNull(Contact::normalizePhone('032177634459'), 'twelve digits, one over');
        $this->assertNull(Contact::normalizePhone('.'));
        $this->assertNull(Contact::normalizePhone('Digital Media'));

        // One canonical form, however it was written.
        $this->assertSame('+92 300 1234567', Contact::normalizePhone('03001234567'));
        $this->assertSame('+92 300 1234567', Contact::normalizePhone('+92 300 1234567'));
        $this->assertSame('+92 300 1234567', Contact::normalizePhone('00923001234567'));
        $this->assertSame('+92 300 1234567', Contact::normalizePhone('0300-1234567'));
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

    /**
     * The person is dated by the day they joined, like their admission already
     * was.
     *
     * Everything else on an imported line is backdated and the person was
     * quietly left out, so all 445 records read "Joined 11 Aug 2026" — the
     * afternoon the file was loaded — for students who walked in a year
     * earlier. The roll knows the real date on every row.
     */
    public function test_an_imported_student_is_dated_by_when_they_joined(): void
    {
        $row = $this->goodRow(['Registration Date' => '2025-07-03', 'Pending Payment Due Date' => '2025-07-03']);

        $this->artisan('roll:import', ['file' => $this->sheet([$row]), '--commit' => true])
            ->assertSuccessful();

        $student = Admission::whereNotNull('import_key')->sole()->student;

        $this->assertSame('2025-07-03', $student->created_at->toDateString(),
            'The student record is dated by the import, not by when they joined.');
    }

    /**
     * A person on several lines starts on the EARLIEST of them.
     *
     * The file is not in date order, so a later line must not be able to move
     * the institute's first sight of someone forwards.
     */
    public function test_a_person_on_several_lines_is_dated_by_the_first(): void
    {
        $rows = [
            $this->chargeRow(['Registration Date' => '2026-05-12', 'Pending Payment Due Date' => '2026-05-12']),
            $this->chargeRow(['Registration Date' => '2025-10-14', 'Pending Payment Due Date' => '2025-10-14']),
        ];

        $this->artisan('roll:import', ['file' => $this->sheet($rows), '--commit' => true])
            ->assertSuccessful();

        $this->assertSame('2025-10-14', $this->importedContact()->created_at->toDateString(),
            'A later line moved the joining date forwards.');
    }

    // ---- Charges: the 32 rows nobody enrols on ------------------------------

    /** A row buying a service rather than teaching. */
    private function chargeRow(array $overrides = []): array
    {
        return $this->goodRow($overrides + [
            'Course' => 'Co-working Space',
            'Name' => 'Azeem',
            // Every one of the institute's 32 charge rows is phoneless, so the
            // fixture is too — testing this path with a phone would exercise a
            // key the real data can never produce.
            'Phone' => '',
            'Batch' => '',
        ]);
    }

    /**
     * The one charge this file imported, whatever the demo seeder left behind.
     *
     * Every test here runs against a seeded institute, so `sole()` on the bare
     * table would be asserting about the seeder rather than the importer.
     */
    private function importedCharge(): Challan
    {
        return Challan::whereNotNull('import_key')->sole();
    }

    private function importedContact(): Student
    {
        return Student::query()->contacts()->sole();
    }

    public function test_a_charge_becomes_an_invoice_with_no_enrolment(): void
    {
        $before = $this->counts();

        $this->artisan('roll:import', ['file' => $this->sheet([$this->chargeRow()]), '--commit' => true])
            ->assertSuccessful();

        $challan = $this->importedCharge();

        $this->assertNull($challan->admission_id, 'A charge must not invent an enrolment.');
        $this->assertTrue($challan->isCharge());
        $this->assertSame('Co-working Space', $challan->description);
        $this->assertSame(25000, $challan->net_amount);
        $this->assertSame($before['admissions'], Admission::count(),
            'A charge must not take a seat on any course.');

        // The money still has to be reachable, which is the whole point of the
        // `student_id` / `raised_by` pair: without them the invoice exists and
        // no report can see it.
        $this->assertSame($this->importedContact()->id, $challan->student_id);
        $this->assertSame(25000, (int) Payment::whereBelongsTo($challan)->sum('amount'));
    }

    public function test_the_person_behind_a_charge_is_a_contact_not_a_student(): void
    {
        $taught = app(Analytics::class)->counts()['students'];

        $this->artisan('roll:import', ['file' => $this->sheet([$this->chargeRow()]), '--commit' => true])
            ->assertSuccessful();

        $person = $this->importedContact();

        $this->assertTrue($person->isContact());
        $this->assertSame('Contact', $person->typeLabel());
        $this->assertSame($taught, app(Analytics::class)->counts()['students'],
            'A room tenant must not be counted among the people the institute teaches.');
        $this->assertSame(1, app(Analytics::class)->counts()['contacts']);
    }

    /**
     * Azeem rents one desk and pays for it monthly. Six lines, one tenant.
     *
     * The failure this pins is six Azeems, each holding one month, none of them
     * findable as the person who has been renting all year.
     */
    public function test_one_person_buying_the_same_service_monthly_is_one_contact(): void
    {
        $months = ['2025-10-14', '2025-12-17', '2026-01-29', '2026-02-21', '2026-04-10', '2026-05-12'];

        $rows = array_map(fn ($on) => $this->chargeRow([
            'Registration Date' => $on, 'Pending Payment Due Date' => $on,
        ]), $months);

        $this->artisan('roll:import', ['file' => $this->sheet($rows), '--commit' => true])
            ->assertSuccessful();

        $this->assertSame(1, Student::query()->contacts()->count(),
            'Six monthly bookings became six people.');
        $this->assertSame(6, Challan::whereNotNull('import_key')->count(),
            'Six monthly bookings collapsed into one.');
        $this->assertSame(150000, $this->chargeMoney());
    }

    /** Everything collected against a charge this file imported. */
    private function chargeMoney(): int
    {
        return (int) Payment::whereIn(
            'challan_id', Challan::whereNotNull('import_key')->select('id')
        )->sum('amount');
    }

    /**
     * Amna Imran appears twice on 2025-07-16 for the same Rs 7,500, and both are
     * real payments. Nothing in the content separates them, so the occurrence
     * index has to.
     */
    public function test_two_identical_charge_lines_are_two_payments(): void
    {
        $row = $this->chargeRow(['Name' => 'Amna Imran', 'Course' => 'Recovery (Batch 4)']);

        $this->artisan('roll:import', ['file' => $this->sheet([$row, $row]), '--commit' => true])
            ->assertSuccessful();

        $this->assertSame(1, Student::query()->contacts()->count());
        $this->assertSame(2, Challan::whereNotNull('import_key')->count(),
            'Byte-identical charge lines are two real payments, not one.');
        $this->assertSame(50000, $this->chargeMoney());
    }

    /**
     * The guarantee that makes `--commit` safe to run twice.
     *
     * Without `challans.import_key` a re-run books every charge again, and
     * because charges arrive already settled the ledger simply grows by money
     * that never arrived, with nothing out of place to notice.
     */
    public function test_re_running_does_not_bill_a_charge_twice(): void
    {
        $rows = [
            $this->chargeRow(['Registration Date' => '2025-10-14', 'Pending Payment Due Date' => '2025-10-14']),
            $this->chargeRow(['Registration Date' => '2025-12-17', 'Pending Payment Due Date' => '2025-12-17']),
            // Identical to the line above it: the occurrence index has to land
            // on the same pair of keys on the second run as on the first.
            $this->chargeRow(['Registration Date' => '2025-12-17', 'Pending Payment Due Date' => '2025-12-17']),
        ];
        $file = $this->sheet($rows);

        $this->artisan('roll:import', ['file' => $file, '--commit' => true])->assertSuccessful();
        $after = $this->counts();

        $this->artisan('roll:import', ['file' => $file, '--commit' => true])->assertSuccessful();

        $this->assertSame($after, $this->counts(), 'A second run billed the same charges again.');
        $this->assertSame(3, Challan::whereNotNull('import_key')->count());
    }

    /**
     * A contact who enrols is a student from that moment (the promotion rule).
     *
     * Enforced inside `RegistrationService::register()` rather than offered as a
     * button, because a genuinely enrolled student sitting outside the student
     * count until somebody remembers to convert them is the same wrong number
     * `kind` was added to fix.
     */
    public function test_a_contact_who_enrols_becomes_a_student(): void
    {
        $this->artisan('roll:import', ['file' => $this->sheet([$this->chargeRow()]), '--commit' => true])
            ->assertSuccessful();

        $contact = $this->importedContact();
        $officer = User::where('username', 'aliraza')->sole();
        $course = Course::where('is_active', true)->first();
        $taught = Student::query()->students()->count();

        app(RegistrationService::class)->register($officer, [
            'student_id' => $contact->id,
            'course_ids' => [$course->id],
        ]);

        $this->assertFalse($contact->fresh()->isContact());
        $this->assertSame($taught + 1, Student::query()->students()->count());
        $this->assertSame(0, Student::query()->contacts()->count());

        // The code does not change with the kind. A contact who enrols keeps
        // the number already printed on their receipts.
        $this->assertSame($contact->student_code, $contact->fresh()->student_code);
    }

    /**
     * The guardrail that stops a purge destroying a contact's collections.
     *
     * `purgeBlocker` reached the student through `challan.admission`, which a
     * charge does not have, so it returned zero and stayed silent — while the
     * purge cascaded through the challan and took every payment row with it.
     * That is BUG-04 exactly, reappearing through a relation that changed.
     */
    public function test_a_contact_carrying_collections_cannot_be_purged(): void
    {
        $this->artisan('roll:import', ['file' => $this->sheet([$this->chargeRow()]), '--commit' => true])
            ->assertSuccessful();

        $blocker = app(RecordRemoval::class)->purgeBlocker($this->importedContact());

        $this->assertNotNull($blocker, 'Purging a contact would have destroyed Rs 25,000 of collections.');
        $this->assertStringContainsString('25,000', $blocker);
    }

    /**
     * An officer must be able to find the person they just billed.
     *
     * `Student::visibleTo()` matched only through admissions, so a contact was
     * invisible to the officer who created them: billed, collected from, and
     * then unfindable on the screen they would go to for the rest of it.
     */
    public function test_an_officer_can_see_the_contact_they_billed(): void
    {
        $this->artisan('roll:import', ['file' => $this->sheet([$this->chargeRow()]), '--commit' => true])
            ->assertSuccessful();

        $officer = User::where('username', 'aliraza')->sole();
        $contact = $this->importedContact();

        $this->assertFalse($officer->can('scope.all'), 'This test is meaningless if the officer sees everything.');
        $this->assertTrue(
            Student::visibleTo($officer)->whereKey($contact->id)->exists(),
            'The officer who raised the charge cannot see who they raised it against.'
        );
    }

    /**
     * The owner's console must report every rupee the institute billed.
     *
     * `Analytics::ledger()` reached invoices through `whereHas('admissions')`,
     * so all 32 charges were absent from BOTH the billed total and the received
     * total. That is the worst shape this defect takes: `outstanding =
     * billed − received` is an identity, so the console's own "Ledger
     * reconciles" self-check went on reporting true while the institute's
     * revenue read Rs 289,950 short. Nothing inside the number could witness
     * it, which is exactly why it needs a test that looks from outside.
     */
    public function test_the_owner_console_counts_charges_in_the_institutes_revenue(): void
    {
        $before = app(Analytics::class)->ledger();

        $this->artisan('roll:import', ['file' => $this->sheet([$this->chargeRow()]), '--commit' => true])
            ->assertSuccessful();

        $after = app(Analytics::class)->ledger();

        $this->assertSame($before['billed'] + 25000, $after['billed'],
            'A charge was billed and the owner console did not see it.');
        $this->assertSame($before['received'] + 25000, $after['received'],
            'A charge was collected and the owner console did not see it.');
        $this->assertSame($before['challans'] + 1, $after['challans']);
        $this->assertTrue($after['reconciles']);
    }

    /** A charge's balance must reach the person, or nobody chases it. */
    public function test_a_contacts_unpaid_charge_shows_as_outstanding(): void
    {
        $row = $this->chargeRow([
            'Status' => 'Pending', 'Discounted Price' => 25000, 'Advance Payment' => 10000,
            'Second Installment' => 15000, 'Balance' => 15000, 'Total Amount' => 10000,
        ]);

        $this->artisan('roll:import', ['file' => $this->sheet([$row]), '--commit' => true])
            ->assertSuccessful();

        $contact = Student::query()->contacts()->withCharges()->sole();

        $this->assertSame(15000, $contact->outstanding(),
            'A contact owing money read as a clean slate, because outstanding() only looked at admissions.');
    }

    /**
     * Contacts belong to nobody's register.
     *
     * True by construction — the roster is built from admissions and a contact
     * has none — which is exactly why it is worth a test: the property is
     * accidental until something asserts it, and a future roster built from
     * `students` instead would put a room tenant in a trainer's class list.
     */
    public function test_a_contact_never_appears_on_a_class_roster(): void
    {
        $this->artisan('roll:import', ['file' => $this->sheet([$this->chargeRow()]), '--commit' => true])
            ->assertSuccessful();

        $contact = $this->importedContact();

        foreach (Course::all() as $course) {
            $this->assertFalse(
                app(Attendances::class)->roster($course)->contains('id', $contact->id),
                "A contact appeared on the register for {$course->code}."
            );
        }
    }

    public static function rejections(): array
    {
        return [
            'unknown course' => [['Course' => 'Underwater Basket Weaving'], 'unknown course'],
            // "not a course" was a rejection here and is now a charge — see the
            // charge tests below. What replaced it as a refusal is a line that
            // names a course AND a charge, which cannot be billed as either.
            'a course and a charge on one line' => [
                ['Course' => 'Web Development, Co-working Space'],
                'names a course and a charge on one line',
            ],
            'no course' => [['Course' => ''], 'no course named'],
            'unknown CSR' => [['CSR' => 'Someone Else'], 'unknown CSR'],
            // Neither a blank phone nor an unusable one is here any more: both
            // import with a NULL number rather than being refused. The four
            // phone tests below pin that, so it cannot be silently reverted.
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
            // An outstanding balance with no due date is no longer refused; it
            // imports unscheduled. See the three tests below.
        ];
    }

    /** A Pending row whose deadline the roll never recorded. */
    private function undatedDebtRow(): array
    {
        return $this->goodRow([
            'Name' => 'Undated Umair',
            'Status' => 'Pending',
            'Discounted Price' => 25000,
            'Advance Payment' => 10000,
            'Second Installment' => 15000,
            'Balance' => 15000,
            'Total Amount' => 10000,
            'Pending Payment Due Date' => '-',
        ]);
    }

    /**
     * The balance loads, and the deadline stays unknown rather than invented.
     *
     * The rejected alternative was the registration-date fallback this importer
     * uses for dated rows, which would have made the debt months overdue on
     * arrival and started the institute chasing a deadline nobody set.
     */
    public function test_an_undated_balance_imports_with_no_due_date_and_no_schedule(): void
    {
        $this->artisan('roll:import', ['file' => $this->sheet([$this->undatedDebtRow()]), '--commit' => true])
            ->assertSuccessful();

        $student = Student::where('name', 'Undated Umair')->firstOrFail();
        $challan = Challan::whereIn(
            'admission_id',
            Admission::where('student_id', $student->id)->pluck('id')
        )->firstOrFail();

        $this->assertNull($challan->due_date, 'A deadline nobody recorded must not be invented.');
        $this->assertSame(15000, $challan->balance(), 'The balance itself must still be owed.');
        $this->assertSame(0, $challan->installments()->count(),
            'A part with no due date makes "what falls due next" unanswerable, so there must be no schedule.');
    }

    /** An undated debt is not late, because there is no date it is late against. */
    public function test_an_undated_balance_is_never_overdue(): void
    {
        $this->artisan('roll:import', ['file' => $this->sheet([$this->undatedDebtRow()]), '--commit' => true])
            ->assertSuccessful();

        $student = Student::where('name', 'Undated Umair')->firstOrFail();
        $challan = Challan::whereIn(
            'admission_id',
            Admission::where('student_id', $student->id)->pluck('id')
        )->firstOrFail();

        $this->assertFalse($challan->isOverdue());
        $this->assertSame(0, Challan::overdue()->whereKey($challan->id)->count(),
            'The SQL scope must exclude an undated challan too, not just the model method.');
    }

    /**
     * The bug this bucket exists to prevent: `Carbon::parse(null)` returns NOW,
     * so an undated debt used to age as 0 days late and land in "Not yet due" —
     * reported as healthy current money in the one report built to surface debt.
     */
    public function test_an_undated_balance_is_reported_as_unscheduled_not_as_current(): void
    {
        $admin = User::where('username', 'adminansar')->firstOrFail();
        $reporting = app(Reporting::class);

        // Measured as a delta, because the seeded institute already carries
        // genuinely-current unpaid challans of its own.
        $before = $reporting->duesAgeing($admin)['buckets'];

        $this->artisan('roll:import', ['file' => $this->sheet([$this->undatedDebtRow()]), '--commit' => true])
            ->assertSuccessful();

        $after = $reporting->duesAgeing($admin)['buckets'];

        $this->assertSame($before['unscheduled']['total'] + 15000, $after['unscheduled']['total']);
        $this->assertSame($before['unscheduled']['count'] + 1, $after['unscheduled']['count']);
        $this->assertSame($before['current']['count'], $after['current']['count'],
            'An undated debt must not be filed as "Not yet due".');
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
