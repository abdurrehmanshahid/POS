<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\User;
use App\Support\Clock;
use App\Support\Period;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Period-aware, scope-aware figures for the Reports screen.
 *
 * Two rules hold everywhere in this class:
 *
 *  1. **Scope first.** Every query starts from the caller's visible set, so an
 *     Admission Officer's "collections" means the money they took, never the
 *     institute's. `revenue.view` separately gates whether the money blocks
 *     render at all (spec §6); scoping and visibility are different questions.
 *
 *  2. **Collections are dated by when the money arrived**, not by when the
 *     challan was issued. A challan issued in June and collected in July is
 *     July's money. Reporting it in June would mean the daily totals never
 *     reconcile against what was actually banked that day, which is the entire
 *     point of a daily collections report.
 *
 *  3. **Every figure is Σ `payments`, never Σ net of the challans flagged paid.**
 *     This class used to read the flag, which silently reported zero for a
 *     student who had handed over an advance, and then dumped that student's
 *     entire fee into whichever period finally settled it. The dashboard has
 *     always summed payments, so the two surfaces disagreed with each other on
 *     the same data the moment anybody took a part payment.
 *
 * Aggregates are computed in SQL rather than by loading rows and looping, so
 * these stay flat as the ledger grows.
 */
class Reporting
{
    public function __construct(private readonly Ledger $ledger) {}

    /** Collections banked inside the window, within the user's scope. */
    private function collected(User $user, Period $period): Builder
    {
        return $this->ledger->scopedPayments($user)
            ->whereBetween('payments.received_at', [$period->from, $period->to]);
    }

    /** Challans issued inside the window, within the user's scope. */
    private function issued(User $user, Period $period): Builder
    {
        return $this->ledger->scopedChallans($user)
            ->whereBetween('created_at', [$period->from, $period->to]);
    }

    // ---- Headline figures ---------------------------------------------------

    /**
     * The period summary: what was billed, what came in, and what it averages
     * per day. The per-day average is what makes two periods of different
     * lengths comparable at a glance.
     */
    public function summary(User $user, Period $period): array
    {
        $collected = (int) $this->collected($user, $period)->sum('amount');
        // Now a genuine count of handovers rather than of settled challans, so
        // two part payments on one fee read as the two movements they were.
        $payments = (int) $this->collected($user, $period)->count();
        $billed = (int) $this->issued($user, $period)->sum('net_amount');
        $days = max(1, $period->days());

        return [
            'collected' => $collected,
            'payments' => $payments,
            'billed' => $billed,
            'issued_count' => (int) $this->issued($user, $period)->count(),
            'per_day' => (int) round($collected / $days),
            'days' => $days,
            // Today is called out separately because "what did we take today"
            // is the question the counter asks at closing, whatever period the
            // rest of the page is showing.
            'today' => $this->collectedOn($user, Clock::today()),
        ];
    }

    /** Money banked on one specific day, in scope. */
    public function collectedOn(User $user, Carbon $day): int
    {
        return (int) $this->ledger->scopedPayments($user)
            ->whereBetween('payments.received_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->sum('amount');
    }

    // ---- Daily collections ---------------------------------------------------

    /**
     * Collections bucketed across the window.
     *
     * Every bucket is materialised even when empty, so a day with no takings
     * renders as a visible zero rather than silently vanishing and making the
     * chart lie about which days were quiet.
     *
     * @return Collection<int, object{label:string, sub:string, total:int, date:string}>
     */
    public function collectionSeries(User $user, Period $period): Collection
    {
        $granularity = $period->granularity();
        $driver = DB::connection()->getDriverName();

        // Driver-portable date key: SQLite in tests, MySQL in production.
        $expr = match ($granularity) {
            'month' => $driver === 'sqlite' ? "strftime('%Y-%m', payments.received_at)" : "DATE_FORMAT(payments.received_at, '%Y-%m')",
            default => $driver === 'sqlite' ? "strftime('%Y-%m-%d', payments.received_at)" : "DATE_FORMAT(payments.received_at, '%Y-%m-%d')",
        };

        $totals = $this->collected($user, $period)
            ->groupBy(DB::raw($expr))
            ->select(DB::raw("$expr as bucket"), DB::raw('SUM(payments.amount) as total'))
            ->pluck('total', 'bucket');

        $out = collect();
        $cursor = $period->from->copy();

        while ($cursor->lessThanOrEqualTo($period->to)) {
            if ($granularity === 'month') {
                $key = $cursor->format('Y-m');
                $out->push((object) [
                    'label' => $cursor->format('M'),
                    'sub' => $cursor->format('Y'),
                    'date' => $cursor->toDateString(),
                    'total' => (int) ($totals[$key] ?? 0),
                ]);
                $cursor->addMonthNoOverflow()->startOfMonth();

                continue;
            }

            if ($granularity === 'week') {
                // Sum the seven days of the week into one bar.
                $weekEnd = $cursor->copy()->addDays(6);
                $total = 0;
                for ($d = $cursor->copy(); $d->lessThanOrEqualTo($weekEnd); $d->addDay()) {
                    $total += (int) ($totals[$d->format('Y-m-d')] ?? 0);
                }
                $out->push((object) [
                    'label' => $cursor->format('d M'),
                    'sub' => 'week',
                    'date' => $cursor->toDateString(),
                    'total' => $total,
                ]);
                $cursor->addDays(7);

                continue;
            }

            $key = $cursor->format('Y-m-d');
            $out->push((object) [
                'label' => $cursor->format('d'),
                'sub' => $cursor->format('D'),
                'date' => $cursor->toDateString(),
                'total' => (int) ($totals[$key] ?? 0),
            ]);
            $cursor->addDay();
        }

        return $out;
    }

    /**
     * Collections split by payment method, biggest first.
     *
     * This is the reconciliation view: cash in the drawer should match the Cash
     * row, and the bank statement should match Bank transfer.
     *
     * Grouped on `payments.method`, the method of the individual handover. The
     * old grouping on `challans.paid_via` recorded only the LAST method used, so
     * a fee half paid in cash and half by card reported the whole amount against
     * Card and nothing against Cash, which is precisely the reconciliation this
     * table exists to support.
     */
    public function byPaymentMethod(User $user, Period $period): Collection
    {
        $rows = $this->collected($user, $period)
            ->groupBy('payments.method')
            ->select('payments.method', DB::raw('SUM(payments.amount) as total'), DB::raw('COUNT(*) as count'))
            ->orderByDesc(DB::raw('SUM(payments.amount)'))
            ->get();

        return $rows->map(fn ($r) => (object) [
            'method' => $r->method ?: 'Unrecorded',
            'total' => (int) $r->total,
            'count' => (int) $r->count,
        ]);
    }

    // ---- Revenue by course -----------------------------------------------------

    /**
     * Collections in the window attributed to the course they were taken for.
     *
     * A course whose students are halfway through paying shows the half that
     * arrived, not zero and not the full fee.
     *
     * @return Collection<int, object>
     */
    public function revenueByCourse(User $user, Period $period, int $limit = 8): Collection
    {
        return $this->collected($user, $period)
            ->join('challans', 'challans.id', '=', 'payments.challan_id')
            ->join('admissions', 'admissions.id', '=', 'challans.admission_id')
            ->join('courses', 'courses.id', '=', 'admissions.course_id')
            ->groupBy('courses.id', 'courses.code', 'courses.title')
            ->select([
                'courses.id', 'courses.code', 'courses.title',
                DB::raw('SUM(payments.amount) as total'),
                DB::raw('COUNT(DISTINCT admissions.id) as enrolments'),
            ])
            ->orderByDesc(DB::raw('SUM(payments.amount)'))
            ->limit($limit)
            ->get()
            ->map(fn ($c) => (object) [
                'code' => $c->code,
                'title' => $c->title,
                'total' => (int) $c->total,
                'enrolments' => (int) $c->enrolments,
            ]);
    }

    // ---- Outstanding dues ageing ------------------------------------------------

    /**
     * Unpaid challans grouped into ageing buckets.
     *
     * Deliberately NOT filtered by the reporting period. Debt does not belong to
     * the window in which it was raised: money owed since March is still owed
     * today, and hiding it because the filter says "this month" is exactly how
     * bad debt goes unnoticed. Ageing is measured against Clock::today().
     *
     * What is aged is the BALANCE, not the face value of the challan. A student
     * who has handed over half their fee owes half, and reporting the whole
     * amount as overdue overstates the institute's bad debt by everything it has
     * already banked.
     */
    public function duesAgeing(User $user): array
    {
        $today = Clock::today();

        $rows = $this->ledger->scopedChallans($user)
            ->where('status', '!=', 'paid')
            ->with(['admission.student', 'admission.course', 'admission.enroller', 'payments'])
            ->get();

        $buckets = [
            'current' => ['label' => 'Not yet due', 'tone' => 'paid', 'total' => 0, 'count' => 0],
            'd1_30' => ['label' => '1 to 30 days', 'tone' => 'unpaid', 'total' => 0, 'count' => 0],
            'd31_60' => ['label' => '31 to 60 days', 'tone' => 'unpaid', 'total' => 0, 'count' => 0],
            'd60_plus' => ['label' => 'Over 60 days', 'tone' => 'overdue', 'total' => 0, 'count' => 0],
        ];

        $students = [];

        foreach ($rows as $challan) {
            $owed = $challan->balance();

            // A settled balance is not a due, whatever the flag says. Defensive:
            // recordPayment flips the status the moment the balance reaches zero,
            // so this only fires on data written outside the service.
            if ($owed <= 0) {
                continue;
            }

            $due = Carbon::parse($challan->due_date)->startOfDay();
            $daysLate = $due->lessThan($today) ? (int) $due->diffInDays($today) : 0;

            $key = match (true) {
                $daysLate === 0 => 'current',
                $daysLate <= 30 => 'd1_30',
                $daysLate <= 60 => 'd31_60',
                default => 'd60_plus',
            };

            $buckets[$key]['total'] += $owed;
            $buckets[$key]['count']++;

            $student = $challan->admission?->student;
            if (! $student) {
                continue;
            }

            $id = $student->id;
            $students[$id] ??= [
                'name' => $student->name,
                'code' => $student->student_code,
                'courses' => [],
                'amount' => 0,
                'days_late' => 0,
            ];
            $students[$id]['amount'] += $owed;
            $students[$id]['days_late'] = max($students[$id]['days_late'], $daysLate);
            if ($title = $challan->admission?->course?->title) {
                $students[$id]['courses'][$title] = true;
            }
        }

        $list = collect($students)
            ->map(fn ($s) => (object) [
                'name' => $s['name'],
                'code' => $s['code'],
                'courses' => implode(' · ', array_keys($s['courses'])),
                'amount' => $s['amount'],
                'days_late' => $s['days_late'],
            ])
            ->sortByDesc('amount')
            ->values();

        return [
            'buckets' => $buckets,
            'students' => $list,
            'total' => array_sum(array_column($buckets, 'total')),
        ];
    }

    // ---- Officer performance -------------------------------------------------------

    /**
     * Per-officer scorecards for the window.
     *
     * Only meaningful institute-wide, so callers gate this on `revenue.view`
     * plus `scope.all`. The column that matters is the collection rate:
     * enrolment counts alone reward whoever signs the most forms, while an
     * officer who enrols heavily and never follows up is generating debt.
     *
     * `received` is collected in a separate pass rather than by joining
     * `payments` into the main query: a challan with two part payments would
     * appear twice in the joined rows and double the officer's `billed` total.
     * One aggregate keyed by officer avoids that without a correlated subquery
     * that MySQL and SQLite would need different syntax for.
     *
     * @return Collection<int, object>
     */
    public function officerPerformance(Period $period): Collection
    {
        $collectedByOfficer = $this->collectedByOfficer($period);

        return User::query()
            ->withTrashed()
            ->leftJoin('admissions', function ($join) use ($period) {
                $join->on('admissions.enrolled_by', '=', 'users.id')
                    ->where('admissions.status', '!=', 'cancelled')
                    ->whereBetween('admissions.created_at', [$period->from, $period->to]);
            })
            ->leftJoin('challans', 'challans.admission_id', '=', 'admissions.id')
            ->groupBy('users.id', 'users.name', 'users.username', 'users.role_id', 'users.deleted_at')
            ->select([
                'users.id', 'users.name', 'users.username', 'users.role_id', 'users.deleted_at',
                DB::raw('COUNT(DISTINCT admissions.id) as enrolments'),
                DB::raw('COALESCE(SUM(challans.net_amount), 0) as billed'),
                DB::raw('COALESCE(SUM(challans.discount_amount), 0) as discounts'),
            ])
            ->orderByDesc(DB::raw('COUNT(DISTINCT admissions.id)'))
            ->get()
            ->map(function ($r) use ($collectedByOfficer) {
                $r->billed = (int) $r->billed;
                $r->received = (int) ($collectedByOfficer[$r->id] ?? 0);
                $r->enrolments = (int) $r->enrolments;
                $r->outstanding = $r->billed - $r->received;
                $r->collection_rate = $r->billed > 0 ? (int) round($r->received / $r->billed * 100) : null;
                $r->avg_discount = $r->enrolments > 0 ? (int) round($r->discounts / $r->enrolments) : 0;
                $r->is_removed = $r->deleted_at !== null;

                return $r;
            })
            // Staff with no activity in the window are noise on a scorecard.
            ->filter(fn ($r) => $r->enrolments > 0)
            ->values();
    }

    /**
     * Total collected against each officer's enrolments in the window.
     *
     * The window filters the ADMISSIONS, not the payments, matching what the
     * scorecard asks: of everything this officer signed up in the period, how
     * much have they actually brought in. Money that arrived later still counts,
     * because chasing it is the officer's job.
     *
     * @return array<int, int> officer id => collected
     */
    private function collectedByOfficer(Period $period): array
    {
        return Payment::query()
            ->join('challans', 'challans.id', '=', 'payments.challan_id')
            ->join('admissions', 'admissions.id', '=', 'challans.admission_id')
            ->where('admissions.status', '!=', 'cancelled')
            ->whereBetween('admissions.created_at', [$period->from, $period->to])
            ->groupBy('admissions.enrolled_by')
            ->select('admissions.enrolled_by', DB::raw('SUM(payments.amount) as total'))
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->enrolled_by => (int) $r->total])
            ->all();
    }
}
