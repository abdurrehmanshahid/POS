<?php

namespace App\Services;

use App\Models\User;
use App\Support\Format;
use App\Support\Period;

/**
 * The report, once, as tables — before anything decides what a file looks like.
 *
 * US-7.3 asks for the report as CSV and the screen already had it as a
 * multi-sheet .xlsx. The trap is obvious once stated: two exporters, each
 * running its own queries, drift the first time one of them is fixed. The
 * story says so in as many words — "an export computed by a second code path
 * is an export that drifts".
 *
 * So the queries and the arithmetic happen exactly once, here, and produce a
 * neutral description of the report. `ReportExportController` owns three
 * writers that render that description as .xlsx, as one .csv, or as a .zip of
 * one .csv per section, and none of them is allowed to know what a `Reporting`
 * is. A figure that is wrong is now wrong in all three formats at once, which
 * is the property worth having: the formats cannot disagree with each other,
 * and none of them can disagree with the screen.
 *
 * Permission filtering lives here too, for the same reason. `revenue.view` and
 * `scope.all` decide which SECTIONS EXIST, not which ones get written out, so
 * a format added later cannot reintroduce a money sheet for an account that is
 * not allowed to see money.
 *
 * @phpstan-type Table array{heading: ?string, rows: list<list<mixed>>, money: list<int>, ints: list<int>}
 * @phpstan-type Section array{title: string, preamble: list<string>, note: ?string, widths: array<string, int>, tables: list<Table>}
 */
class ReportBook
{
    public function __construct(private readonly Reporting $reporting) {}

    /**
     * Every section the given user is allowed to see, in sheet order.
     *
     * @return list<Section>
     */
    public function build(User $user, Period $period): array
    {
        $canSeeMoney = $user->can('revenue.view');

        $sections = [$this->summary($user, $period, $canSeeMoney)];

        if ($canSeeMoney) {
            $sections[] = $this->collections($user, $period);
            $sections[] = $this->courses($user, $period);
        }

        $sections[] = $this->dues($user);

        if ($canSeeMoney && $user->can('scope.all')) {
            $sections[] = $this->officers($period);
        }

        return $sections;
    }

    // ---- Sections ------------------------------------------------------------

    /** @return Section */
    private function summary(User $user, Period $period, bool $money): array
    {
        $preamble = [
            config('institute.name', 'Big Binary Tech Institute'),
            'Report period: '.$period->label().' ('.$period->rangeLabel().')',
            // On the institute's clock, and SAID so. This file gets emailed and
            // filed; a bare "10:44" on a report generated at 10:44 UTC read as
            // the middle of the morning when the counter had already been open
            // for six hours.
            'Generated: '.Format::dateTime(now()).' '.Format::zone(),
            'Scope: '.($user->can('scope.all') ? 'All registrations' : 'Own enrolments only'),
        ];

        // An account without `revenue.view` gets the header and an explicit
        // sentence, not an empty sheet. A blank export reads as a broken export
        // and generates a support call; a stated reason does not.
        if (! $money) {
            return [
                'title' => 'Summary',
                'preamble' => $preamble,
                'note' => 'Money figures are not included: this account does not hold the revenue permission.',
                'widths' => ['A' => 70],
                'tables' => [],
            ];
        }

        $s = $this->reporting->summary($user, $period);

        return [
            'title' => 'Summary',
            'preamble' => $preamble,
            'note' => null,
            'widths' => ['A' => 28, 'B' => 18],
            'tables' => [[
                'heading' => null,
                'rows' => [
                    ['Metric', 'Value'],
                    ['Collected in period', $s['collected']],
                    ['Payments received', $s['payments']],
                    ['Average per day', $s['per_day']],
                    ['Collected today', $s['today']],
                    ['Billed in period', $s['billed']],
                    ['Challans issued', $s['issued_count']],
                    ['Days in period', $s['days']],
                ],
                'money' => [1],
                'ints' => [2, 3, 6, 7],
            ]],
        ];
    }

    /** @return Section */
    private function collections(User $user, Period $period): array
    {
        $series = [['Date', 'Period', 'Collected']];
        foreach ($this->reporting->collectionSeries($user, $period) as $b) {
            $series[] = [$b->date, $b->label.' '.$b->sub, $b->total];
        }

        $methods = [['Method', 'Payments', 'Total']];
        foreach ($this->reporting->byPaymentMethod($user, $period) as $m) {
            $methods[] = [$m->method, $m->count, $m->total];
        }

        return [
            'title' => 'Daily collections',
            'preamble' => [],
            'note' => null,
            'widths' => ['A' => 14, 'B' => 16, 'C' => 16],
            'tables' => [
                ['heading' => null, 'rows' => $series, 'money' => [2], 'ints' => []],
                ['heading' => 'By payment method', 'rows' => $methods, 'money' => [2], 'ints' => [1]],
            ],
        ];
    }

    /** @return Section */
    private function courses(User $user, Period $period): array
    {
        $rows = [['Code', 'Course', 'Enrolments', 'Collected']];
        foreach ($this->reporting->revenueByCourse($user, $period, 100) as $c) {
            $rows[] = [$c->code, $c->title, $c->enrolments, $c->total];
        }

        return [
            'title' => 'Revenue by course',
            'preamble' => [],
            'note' => null,
            'widths' => ['A' => 12, 'B' => 42, 'C' => 13, 'D' => 16],
            'tables' => [['heading' => null, 'rows' => $rows, 'money' => [3], 'ints' => [2]]],
        ];
    }

    /** @return Section */
    private function dues(User $user): array
    {
        $dues = $this->reporting->duesAgeing($user);

        $ageing = [['Ageing bucket', 'Challans', 'Amount']];
        foreach ($dues['buckets'] as $b) {
            $ageing[] = [$b['label'], $b['count'], $b['total']];
        }

        $students = [['Student ID', 'Student', 'Courses', 'Days overdue', 'Owes']];
        foreach ($dues['students'] as $s) {
            $students[] = [$s->code, $s->name, $s->courses, $s->days_late, $s->amount];
        }

        return [
            'title' => 'Outstanding dues',
            'preamble' => [],
            'note' => null,
            'widths' => ['A' => 14, 'B' => 26, 'C' => 40, 'D' => 14, 'E' => 16],
            'tables' => [
                ['heading' => null, 'rows' => $ageing, 'money' => [2], 'ints' => [1]],
                ['heading' => 'By student', 'rows' => $students, 'money' => [4], 'ints' => [3]],
            ],
        ];
    }

    /** @return Section */
    private function officers(Period $period): array
    {
        $rows = [['Officer', 'Username', 'Enrolments', 'Billed', 'Collected', 'Outstanding', 'Collection %', 'Avg discount']];
        foreach ($this->reporting->officerPerformance($period) as $o) {
            $rows[] = [
                $o->name, $o->username, $o->enrolments,
                $o->billed, $o->received, $o->outstanding,
                $o->collection_rate, $o->avg_discount,
            ];
        }

        return [
            'title' => 'Officer performance',
            'preamble' => [],
            'note' => null,
            'widths' => ['A' => 24, 'B' => 16, 'C' => 13, 'D' => 15, 'E' => 15, 'F' => 15, 'G' => 14, 'H' => 15],
            'tables' => [[
                'heading' => null,
                'rows' => $rows,
                'money' => [3, 4, 5, 7],
                'ints' => [2, 6],
            ]],
        ];
    }
}
