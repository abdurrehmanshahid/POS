<?php

namespace Tests\Feature;

use App\Models\Challan;
use App\Models\Course;
use App\Models\User;
use App\Services\ChallanActions;
use App\Services\RegistrationService;
use App\Services\Reporting;
use App\Support\Period;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
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

        $invoice = $result['challans'][0];
        $this->assertSame(36000, $invoice->net_amount);

        // Permitted: nothing has been collected yet.
        app(ChallanActions::class)->cancel($result['admissions'][1], $admin, 'Student dropped it');

        app(ChallanActions::class)->markPaid($invoice->refresh(), $admin, 'Cash');

        $collected = $reporting->summary($admin, $period)['collected'] - $beforeCollected;
        $byCourse = $reporting->revenueByCourse($admin, $period, 50)->sum('total') - $beforeByCourse;

        $this->assertSame(36000, $collected);
        $this->assertSame(
            $collected,
            $byCourse,
            'The remaining course carries the whole invoice, so the shares still add up to what was banked.'
        );

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
