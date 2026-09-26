<?php

namespace Tests\Feature;

use App\Models\Challan;
use App\Models\Course;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Student;
use App\Models\User;
use App\Services\ChallanActions;
use App\Services\Ledger;
use App\Services\RegistrationService;
use App\Services\Reporting;
use App\Support\Period;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The rebuilt Reports screen: a period filter that actually filters, the
 * per-day metric, ageing buckets and a real spreadsheet.
 */
class ReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The seed data is anchored to July 2026; pin "today" to match so the
        // period windows resolve over real rows.
        config(['institute.today' => '2026-07-15']);
        Carbon::setTestNow('2026-07-15 10:00:00');
        $this->seed(DatabaseSeeder::class);
    }

    private function admin(): User
    {
        return $this->enrolTwoFactor(User::where('username', 'adminansar')->firstOrFail());
    }

    private function officer(): User
    {
        return User::where('username', 'aliraza')->firstOrFail();
    }

    // ---- Non-course charges --------------------------------------------------

    /**
     * A charge with no admission must still be money.
     *
     * `Ledger::scopedChallans()` is the single gate every money figure passes
     * through, and it used to read `whereHas('admissions', ...)` alone. A
     * challan with no admission matched nothing, so a charge would have been
     * billed, collected, audited — and then absent from every total in the
     * application.
     *
     * That is BUG-27's shape, and the reason it needs an explicit test is that
     * it cannot be caught from inside: `outstanding = billed − received` stays
     * perfectly balanced while a row is missing from BOTH sides, so no
     * reconciliation check can ever witness it. Only asserting the total moves
     * will.
     */
    public function test_a_non_course_charge_counts_as_billed_and_collected(): void
    {
        $admin = $this->admin();
        $ledger = app(Ledger::class);

        $billedBefore = $ledger->billed($admin);
        $receivedBefore = $ledger->received($admin);

        $charge = Challan::create([
            'challan_no' => 'BBT-CH-2026-9001',
            'admission_id' => null,                 // not an enrolment
            'student_id' => Student::first()->id,
            'raised_by' => $admin->id,
            'description' => 'Co-working Space',
            'base_amount' => 15000,
            'discount_amount' => 0,
            'net_amount' => 15000,
            'plan' => 'full',
            'due_date' => '2026-07-31',
            'status' => 'unpaid',
        ]);

        $this->assertSame($billedBefore + 15000, $ledger->billed($admin),
            'A charge that is not billed is money the institute cannot see.');

        app(ChallanActions::class)->recordPayment($charge, $admin, 15000, 'Cash');

        $this->assertSame($receivedBefore + 15000, $ledger->received($admin->fresh()),
            'A collection against a charge must reach the ledger.');
    }

    /** A charge is scoped to the officer who raised it, like an enrolment. */
    public function test_a_charge_is_visible_only_to_its_own_officer(): void
    {
        $admin = $this->admin();
        $officer = $this->officer();
        $ledger = app(Ledger::class);

        $before = $ledger->billed($officer);

        Challan::create([
            'challan_no' => 'BBT-CH-2026-9002',
            'admission_id' => null,
            'student_id' => Student::first()->id,
            'raised_by' => $admin->id,             // not the officer
            'description' => 'Certificate Fee',
            'base_amount' => 650,
            'discount_amount' => 0,
            'net_amount' => 650,
            'plan' => 'full',
            'due_date' => '2026-07-31',
            'status' => 'unpaid',
        ]);

        $this->assertSame($before, $ledger->billed($officer->fresh()),
            "An officer must not see another officer's charge.");
    }

    // ---- Period resolution ---------------------------------------------------

    public function test_presets_resolve_to_real_windows(): void
    {
        $this->assertSame(1, Period::resolve('today')->days());
        $this->assertSame(7, Period::resolve('week')->days());
        $this->assertSame(31, Period::resolve('month')->days());   // July
        $this->assertSame(92, Period::resolve('quarter')->days()); // Jul-Sep
        $this->assertSame(365, Period::resolve('year')->days());
    }

    public function test_custom_range_is_honoured_and_bad_input_falls_back(): void
    {
        $custom = Period::resolve('custom', '2026-07-01', '2026-07-10');
        $this->assertSame('custom', $custom->key);
        $this->assertSame(10, $custom->days());

        // Inverted range must not throw; it falls back to the month.
        $this->assertSame('month', Period::resolve('custom', '2026-07-10', '2026-07-01')->key);
        $this->assertSame('month', Period::resolve('custom', 'nonsense', null)->key);
    }

    public function test_chart_granularity_widens_with_the_window(): void
    {
        $this->assertSame('day', Period::resolve('month')->granularity());
        $this->assertSame('week', Period::resolve('quarter')->granularity());
        $this->assertSame('month', Period::resolve('year')->granularity());
    }

    // ---- The period actually filters ------------------------------------------

    public function test_changing_the_period_changes_the_figures(): void
    {
        $reporting = app(Reporting::class);
        $admin = $this->admin();

        $year = $reporting->summary($admin, Period::resolve('year'))['collected'];
        $today = $reporting->summary($admin, Period::resolve('today'))['collected'];

        // Seeded payments are all in June/July, none on 15 Jul.
        $this->assertGreaterThan(0, $year, 'The year window must contain the seeded payments.');
        $this->assertSame(0, $today, 'Nothing was paid on the pinned today.');
        $this->assertNotSame($year, $today, 'The filter must actually filter.');
    }

    public function test_collections_are_dated_by_payment_not_by_issue(): void
    {
        $reporting = app(Reporting::class);
        $admin = $this->admin();

        // A challan issued long ago but collected today belongs to today.
        //
        // The collection is recorded through the service rather than by writing
        // the paid flag by hand: every figure is now Σ payments, so a challan
        // flagged paid with no payment row behind it is not a settled fee, it is
        // inconsistent data that the application itself cannot produce.
        $challan = Challan::where('status', '!=', 'paid')->firstOrFail();
        $challan->forceFill(['created_at' => '2026-01-05 09:00:00'])->save();

        app(ChallanActions::class)->markPaid($challan, $admin, 'Cash');

        $today = $reporting->summary($admin, Period::resolve('today'));

        $this->assertSame((int) $challan->net_amount, $today['collected']);
    }

    /**
     * A grouped invoice must credit every course it bills, in proportion.
     *
     * Before the invoice could span several enrolments, revenue-by-course
     * joined through `challans.admission_id`, which names only the first
     * course. A student paying 40,000 for two courses on one document reported
     * the whole 40,000 against whichever course headed the invoice and nothing
     * at all against the other, so a course could look dead while genuinely
     * earning.
     */
    public function test_a_grouped_invoice_splits_its_revenue_across_its_courses(): void
    {
        $admin = $this->admin();
        $reporting = app(Reporting::class);

        $before = $reporting->revenueByCourse($admin, Period::resolve('today'), 50)
            ->keyBy('code')->map(fn ($c) => $c->total);

        // WD-101 at 20,000 and AI-201 at 20,000 on one invoice, no discount.
        $result = app(RegistrationService::class)->register($admin, [
            'new_student' => [
                'type' => 'R', 'name' => 'Split Revenue', 'guardian_name' => 'Guardian',
                'phone' => '+92 300 7770000', 'cnic' => '35201-7770000-9',
            ],
            'course_ids' => Course::whereIn('code', ['WD-101', 'AI-201'])->pluck('id')->all(),
        ]);

        $invoice = $result['challans'][0];
        $this->assertSame(40000, $invoice->base_amount);

        app(ChallanActions::class)->markPaid($invoice, $admin, 'Cash');

        $after = $reporting->revenueByCourse($admin, Period::resolve('today'), 50)
            ->keyBy('code')->map(fn ($c) => $c->total);

        $wdGain = $after['WD-101'] - ($before['WD-101'] ?? 0);
        $aiGain = $after['AI-201'] - ($before['AI-201'] ?? 0);

        $this->assertSame(20000, $wdGain, 'WD-101 earned its own half of the invoice.');
        $this->assertSame(20000, $aiGain, 'AI-201 earned its half too, rather than nothing.');

        // And the split still reconciles with what was actually collected.
        $this->assertSame(40000, $wdGain + $aiGain);
    }

    /**
     * Cancelling one course before paying must not lose money from the
     * per-course report.
     *
     * Every apportioning query filters its rows to live enrolments, so dividing
     * by the invoice's full `base_amount` meant the shares stopped summing to
     * the invoice the moment a course was dropped — and dropping a course
     * before any money is collected is explicitly permitted. On a 40,000
     * invoice discounted to 36,000, the Reports screen showed "Collected
     * 36,000" beside a revenue-by-course table totalling 18,000. Two figures,
     * one screen, 18,000 apart.
     */
    public function test_a_cancelled_course_does_not_lose_revenue_from_the_per_course_report(): void
    {
        $admin = $this->admin();
        $reporting = app(Reporting::class);
        $period = Period::resolve('today');

        $beforeByCourse = $reporting->revenueByCourse($admin, $period, 50)->sum('total');
        $beforeCollected = $reporting->summary($admin, $period)['collected'];

        $result = app(RegistrationService::class)->register($admin, [
            'new_student' => [
                'type' => 'R', 'name' => 'Dropped A Course', 'guardian_name' => 'Guardian',
                'phone' => '+92 300 2220000', 'cnic' => '35201-2220000-4',
            ],
            'course_ids' => Course::whereIn('code', ['WD-101', 'AI-201'])->pluck('id')->all(),
            'discount_pct' => 10,
            'discount_reason' => 'Referral',
        ]);

        // 40,000 tuition less 10%, plus 700 certificate charges per course.
        $invoice = $result['challans'][0];
        $this->assertSame(36000, $invoice->base_amount - $invoice->discount_amount);
        $this->assertSame(1400, $invoice->certificate_amount);
        $this->assertSame(37400, $invoice->net_amount);

        // Permitted: nothing has been collected yet.
        app(ChallanActions::class)->cancel($result['admissions'][1], $admin, 'Student dropped it');

        app(ChallanActions::class)->markPaid($invoice->refresh(), $admin, 'Cash');

        $collected = $reporting->summary($admin, $period)['collected'] - $beforeCollected;
        $byCourse = $reporting->revenueByCourse($admin, $period, 50)->sum('total') - $beforeByCourse;

        $this->assertSame(37400, $collected);

        // The shares still reconcile with what was banked — but against the
        // TUITION, because the certificate charge is levied on the invoice
        // rather than earned by a course and is deliberately left out of
        // revenue-by-course. Collected = course revenue + certificate charges,
        // and the remaining course carries the whole of the first.
        $this->assertSame(36000, $byCourse);
        $this->assertSame($collected, $byCourse + (int) $invoice->certificate_amount);

        // And the dropped course is worth nothing to the student.
        $this->assertSame(0, $result['admissions'][1]->refresh()->netShare());
        $this->assertSame(36000, $result['admissions'][0]->refresh()->netShare());
    }

    public function test_a_part_payment_is_reported_the_day_it_arrives(): void
    {
        $reporting = app(Reporting::class);
        $admin = $this->admin();

        $challan = Challan::where('status', '!=', 'paid')->firstOrFail();
        $half = intdiv((int) $challan->net_amount, 2);

        app(ChallanActions::class)->recordPayment($challan, $admin, $half, 'Cash');

        // The advance is money in the drawer today, even though the challan is
        // still short of settled. Reading the paid flag reported zero here.
        $this->assertSame($half, $reporting->summary($admin, Period::resolve('today'))['collected']);

        // And it is attributed to the method it was actually taken by.
        $cash = $reporting->byPaymentMethod($admin, Period::resolve('today'))
            ->firstWhere('method', 'Cash');
        $this->assertNotNull($cash);
        $this->assertSame($half, $cash->total);
    }

    public function test_dues_ageing_counts_the_balance_not_the_face_value(): void
    {
        $reporting = app(Reporting::class);
        $admin = $this->admin();

        $before = $reporting->duesAgeing($admin)['total'];

        $challan = Challan::where('status', '!=', 'paid')->firstOrFail();
        $half = intdiv((int) $challan->net_amount, 2);

        app(ChallanActions::class)->recordPayment($challan, $admin, $half, 'Cash');

        // Taking half the fee reduces what is owed by half, not by nothing.
        $this->assertSame($before - $half, $reporting->duesAgeing($admin)['total']);
    }

    public function test_per_day_average_divides_by_the_window_length(): void
    {
        $reporting = app(Reporting::class);
        $summary = $reporting->summary($this->admin(), Period::resolve('month'));

        $this->assertSame(
            (int) round($summary['collected'] / 31),
            $summary['per_day'],
        );
    }

    public function test_daily_series_materialises_empty_days(): void
    {
        $series = app(Reporting::class)->collectionSeries($this->admin(), Period::resolve('month'));

        // Every day of July, including the ones with no takings.
        $this->assertCount(31, $series);
        $this->assertTrue($series->contains(fn ($b) => $b->total === 0), 'Quiet days must render as zero, not vanish.');
    }

    public function test_payment_method_breakdown_totals_match_collections(): void
    {
        $reporting = app(Reporting::class);
        $period = Period::resolve('year');
        $admin = $this->admin();

        $byMethod = $reporting->byPaymentMethod($admin, $period)->sum('total');
        $collected = $reporting->summary($admin, $period)['collected'];

        $this->assertSame($collected, $byMethod, 'Method split must reconcile with the total.');
    }

    // ---- Scoping ---------------------------------------------------------------

    public function test_an_officer_only_sees_their_own_collections(): void
    {
        $reporting = app(Reporting::class);
        $period = Period::resolve('year');

        $all = $reporting->summary($this->admin(), $period)['collected'];
        $mine = $reporting->summary($this->officer(), $period)['collected'];

        $this->assertGreaterThan($mine, $all, 'An officer must not see institute-wide money.');
    }

    public function test_officers_get_no_money_blocks_on_the_screen(): void
    {
        $this->assertFalse($this->officer()->can('revenue.view'));

        Livewire::actingAs($this->officer())
            ->test('pages.reports')
            ->assertSet('canSeeMoney', false)
            ->assertDontSee('Average per day');
    }

    // ---- Dues ageing --------------------------------------------------------------

    public function test_dues_are_bucketed_by_how_late_they_are(): void
    {
        $dues = app(Reporting::class)->duesAgeing($this->admin());

        $this->assertArrayHasKey('current', $dues['buckets']);
        $this->assertArrayHasKey('d60_plus', $dues['buckets']);

        // Buckets must sum to the headline total.
        $this->assertSame(
            $dues['total'],
            array_sum(array_column($dues['buckets'], 'total')),
        );

        // And to the per-student list.
        $this->assertSame($dues['total'], $dues['students']->sum('amount'));
    }

    public function test_ageing_ignores_the_period_filter(): void
    {
        // Debt raised outside the window is still debt today.
        $reporting = app(Reporting::class);
        $a = $reporting->duesAgeing($this->admin());
        config(['institute.today' => '2026-07-15']);
        $b = $reporting->duesAgeing($this->admin());

        $this->assertSame($a['total'], $b['total']);
        $this->assertGreaterThan(0, $a['total']);
    }

    // ---- Screen + export ------------------------------------------------------------

    public function test_the_screen_renders_and_the_period_buttons_work(): void
    {
        Livewire::actingAs($this->admin())
            ->test('pages.reports')
            ->assertOk()
            ->assertSee('Collected today')
            ->call('setPeriod', 'today')
            ->assertSet('periodKey', 'today')
            ->call('setPeriod', 'nonsense')
            ->assertSet('periodKey', 'month');
    }

    public function test_the_fabricated_attendance_card_is_gone(): void
    {
        Livewire::actingAs($this->admin())
            ->test('pages.reports')
            ->assertDontSee('Attendance summary')
            ->assertDontSee('92%');
    }

    public function test_export_streams_a_real_xlsx(): void
    {
        $response = $this->actingAs($this->admin())
            ->get(route('reports.export', ['period' => 'year']));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        // An .xlsx is a zip archive: it must start with the PK signature.
        $this->assertStringStartsWith('PK', $response->streamedContent());
    }

    public function test_export_is_permission_gated(): void
    {
        $this->assertFalse($this->officer()->can('reports.view'));

        $this->actingAs($this->officer())
            ->get(route('reports.export'))
            ->assertForbidden();
    }

    // ---- The other two formats (US-7.3 asked for CSV) -------------------------

    /**
     * US-7.3 asks for the report as CSV. The report is five sections and CSV is
     * one table, so the stacked file labels each section and separates them
     * with a blank line — which is what makes it separable again by hand, by
     * Excel's "format as table", or by `pandas.read_csv`.
     */
    public function test_the_csv_export_stacks_every_labelled_section(): void
    {
        $response = $this->actingAs($this->admin())
            ->get(route('reports.export', ['period' => 'year', 'format' => 'csv']));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('.csv', $response->headers->get('content-disposition'));

        $body = $response->streamedContent();

        // Without the BOM, Excel on a Windows machine set to a local codepage
        // renders every non-ASCII name as mojibake.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);

        foreach (['Summary', 'Daily collections', 'Revenue by course', 'Outstanding dues', 'Officer performance'] as $section) {
            $this->assertStringContainsString($section, $body, "The {$section} section is missing from the CSV.");
        }

        // Section headers, so the stack is machine-separable rather than a wall.
        $this->assertStringContainsString("Metric,Value\r\n", $body);
        $this->assertStringContainsString("Date,Period,Collected\r\n", $body);
        $this->assertStringContainsString('By payment method', $body);
        $this->assertStringContainsString('By student', $body);

        // CRLF, per RFC 4180 and per what Excel on Windows expects.
        $this->assertStringContainsString("\r\n", $body);
    }

    /**
     * Money stays a NUMBER. A thousands separator inside a CSV is a field
     * separator, so "119,000" would not merely look wrong — it would shift
     * every column after it by one.
     */
    public function test_the_csv_writes_money_as_a_bare_integer(): void
    {
        $body = $this->actingAs($this->admin())
            ->get(route('reports.export', ['period' => 'year', 'format' => 'csv']))
            ->streamedContent();

        $collected = app(Reporting::class)->summary($this->admin(), Period::resolve('year'))['collected'];
        $this->assertGreaterThan(999, $collected, 'This test is vacuous unless the figure is big enough to be separated.');

        $this->assertStringContainsString('Collected in period,'.$collected."\r\n", $body);
        $this->assertStringNotContainsString(number_format($collected), $body);
    }

    /** One .csv per section, numbered so a zip listing keeps the reading order. */
    public function test_the_zip_export_holds_one_csv_per_section(): void
    {
        $response = $this->actingAs($this->admin())
            ->get(route('reports.export', ['period' => 'year', 'format' => 'zip']));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/zip');

        $path = tempnam(sys_get_temp_dir(), 'bbt-zip-test-');
        file_put_contents($path, $response->streamedContent());

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'The response is not a readable zip archive.');

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[] = $zip->getNameIndex($i);
        }

        $this->assertSame([
            '01 Summary.csv',
            '02 Daily collections.csv',
            '03 Revenue by course.csv',
            '04 Outstanding dues.csv',
            '05 Officer performance.csv',
        ], $entries);

        // Each entry is a real CSV with its own BOM, not an empty placeholder.
        $summary = $zip->getFromName('01 Summary.csv');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $summary);
        $this->assertStringContainsString("Metric,Value\r\n", $summary);

        $zip->close();
        @unlink($path);
    }

    /**
     * The property the whole refactor exists to hold: three writers, one set of
     * queries. If a figure is ever computed twice it will differ here first.
     */
    public function test_all_three_formats_agree_on_the_figures(): void
    {
        $collected = app(Reporting::class)->summary($this->admin(), Period::resolve('year'))['collected'];

        $csv = $this->actingAs($this->admin())
            ->get(route('reports.export', ['period' => 'year', 'format' => 'csv']))->streamedContent();
        $this->assertStringContainsString('Collected in period,'.$collected, $csv);

        $path = tempnam(sys_get_temp_dir(), 'bbt-zip-agree-');
        file_put_contents($path, $this->actingAs($this->admin())
            ->get(route('reports.export', ['period' => 'year', 'format' => 'zip']))->streamedContent());
        $zip = new \ZipArchive;
        $zip->open($path);
        $this->assertStringContainsString('Collected in period,'.$collected, $zip->getFromName('01 Summary.csv'));
        $zip->close();
        @unlink($path);

        $book = tempnam(sys_get_temp_dir(), 'bbt-xlsx-agree-').'.xlsx';
        file_put_contents($book, $this->actingAs($this->admin())
            ->get(route('reports.export', ['period' => 'year', 'format' => 'xlsx']))->streamedContent());
        $sheet = IOFactory::load($book)->getSheetByName('Summary');
        $this->assertSame($collected, (int) $sheet->getCell('B7')->getValue());
        @unlink($book);
    }

    /**
     * A mistyped query string should still hand back a report rather than a
     * 400, which is how `Period::resolve` already treats a nonsense period.
     */
    public function test_an_unknown_format_falls_back_to_the_workbook(): void
    {
        $this->actingAs($this->admin())
            ->get(route('reports.export', ['period' => 'year', 'format' => 'exe']))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    /**
     * The permission gate decides which sections EXIST, not which get written,
     * so a format added later cannot leak a money sheet back in. Asserted on
     * every format rather than on the default one.
     */
    #[DataProvider('exportFormats')]
    public function test_every_format_is_permission_gated(string $format): void
    {
        $this->assertFalse($this->officer()->can('reports.view'));

        $this->actingAs($this->officer())
            ->get(route('reports.export', ['format' => $format]))
            ->assertForbidden();
    }

    /** @return list<array{0:string}> */
    public static function exportFormats(): array
    {
        return [['xlsx'], ['csv'], ['zip']];
    }

    /**
     * Roles are editable data, so "may read reports, may not see money" is a
     * configuration a real institute can build in the role editor. The money
     * sections must then not exist in ANY format — the gate decides which
     * sections are constructed, not which of them get written out, so this is
     * the test that stops a fourth format leaking them back in.
     */
    #[DataProvider('exportFormats')]
    public function test_a_reports_only_account_gets_no_money_in_any_format(string $format): void
    {
        $role = Role::create(['id' => 'role_auditor_1', 'name' => 'Auditor', 'tone' => 'navy']);
        foreach (['dashboard.view', 'reports.view', 'students.view'] as $key) {
            RolePermission::create(['role_id' => $role->id, 'permission_key' => $key]);
        }

        $auditor = User::where('username', 'fatimanoor')->firstOrFail();
        $auditor->forceFill(['role_id' => $role->id])->save();
        $auditor->refresh();

        $this->assertTrue($auditor->can('reports.view'));
        $this->assertFalse($auditor->can('revenue.view'));

        $response = $this->actingAs($auditor)
            ->get(route('reports.export', ['period' => 'year', 'format' => $format]));
        $response->assertOk();

        $text = $format === 'xlsx'
            ? $this->sheetNames($response->streamedContent())
            : $response->streamedContent();

        // Outstanding dues is NOT money-gated (an officer chasing a payment
        // needs it), so it stays. The three revenue sections must be gone.
        foreach (['Daily collections', 'Revenue by course', 'Officer performance'] as $section) {
            $this->assertStringNotContainsString($section, $text, "{$section} leaked into the {$format} export.");
        }

        $this->assertStringContainsString('Outstanding dues', $text);

        if ($format === 'csv') {
            $this->assertStringContainsString('does not hold the revenue permission', $text);
        }
    }

    /** Sheet names of a streamed .xlsx, as one string to assert against. */
    private function sheetNames(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bbt-sheets-').'.xlsx';
        file_put_contents($path, $bytes);
        $names = implode("\n", IOFactory::load($path)->getSheetNames());
        @unlink($path);

        return $names;
    }

    public function test_choosing_custom_reveals_its_date_inputs(): void
    {
        // Regression: the inputs used to render only when the RESOLVED period
        // was 'custom', but with no dates yet it resolves to 'month'. That made
        // Custom impossible to use, because the inputs needed to set the dates
        // only appeared once the dates were already set.
        Livewire::actingAs($this->admin())
            ->test('pages.reports')
            ->call('setPeriod', 'custom')
            ->assertSet('periodKey', 'custom')
            ->assertSee('to');
    }

    public function test_a_custom_range_filters_the_figures(): void
    {
        $reporting = app(Reporting::class);
        $june = $reporting->summary($this->admin(), Period::resolve('custom', '2026-06-01', '2026-06-30'));
        $july = $reporting->summary($this->admin(), Period::resolve('custom', '2026-07-01', '2026-07-31'));

        $this->assertGreaterThan(0, $june['collected']);
        $this->assertNotSame($june['collected'], $july['collected']);
    }
}
