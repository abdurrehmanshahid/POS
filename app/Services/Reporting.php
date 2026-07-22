<?php

namespace App\Services;

use App\Models\Challan;
use App\Models\Course;
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
 *  2. **Collections are dated by `paid_at`, not `created_at`.** A challan
 *     issued in June and settled in July is July's money. Reporting it in June
 *     would mean the daily totals never reconcile against what was actually
 *     banked that day, which is the entire point of a daily collections report.
 *
 * Aggregates are computed in SQL rather than by loading rows and looping, so
 * these stay flat as the ledger grows.
 */
class Reporting
{
    public function __construct(private readonly Ledger $ledger) {}

    /** Paid challans settled inside the window, within the user's scope. */
    private function collected(User $user, Period $period): Builder
    {
        return $this->ledger->scopedChallans($user)
            ->where('status', 'paid')
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$period->from, $period->to]);
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
        $collected = (int) $this->collected($user, $period)->sum('net_amount');
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
        return (int) $this->ledger->scopedChallans($user)
            ->where('status', 'paid')
            ->whereBetween('paid_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->sum('net_amount');
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
            'month' => $driver === 'sqlite' ? "strftime('%Y-%m', paid_at)" : "DATE_FORMAT(paid_at, '%Y-%m')",
            default => $driver === 'sqlite' ? "strftime('%Y-%m-%d', paid_at)" : "DATE_FORMAT(paid_at, '%Y-%m-%d')",
        };

        $totals = $this->collected($user, $period)
            ->groupBy(DB::raw($expr))
            ->select(DB::raw("$expr as bucket"), DB::raw('SUM(net_amount) as total'))
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
     */
    public function byPaymentMethod(User $user, Period $period): Collection
    {
        $rows = $this->collected($user, $period)
            ->groupBy('paid_via')
            ->select('paid_via', DB::raw('SUM(net_amount) as total'), DB::raw('COUNT(*) as count'))
            ->orderByDesc(DB::raw('SUM(net_amount)'))
            ->get();

        return $rows->map(fn ($r) => (object) [
            'method' => $r->paid_via ?: 'Unrecorded',
            'total' => (int) $r->total,
            'count' => (int) $r->count,
        ]);
    }

    // ---- Revenue by course -----------------------------------------------------

    /** @return Collection<int, object> */
    public function revenueByCourse(User $user, Period $period, int $limit = 8): Collection
    {
        $paidIds = $this->collected($user, $period)->pluck('id');

        if ($paidIds->isEmpty()) {
            return collect();
        }

        return Course::query()
            ->join('admissions', 'admissions.course_id', '=', 'courses.id')
            ->join('challans', 'challans.admission_id', '=', 'admissions.id')
            ->whereIn('challans.id', $paidIds)
            ->groupBy('courses.id', 'courses.code', 'courses.title')
            ->select([
                'courses.id', 'courses.code', 'courses.title',
                DB::raw('SUM(challans.net_amount) as total'),
                DB::raw('COUNT(DISTINCT admissions.id) as enrolments'),
            ])
            ->orderByDesc(DB::raw('SUM(challans.net_amount)'))
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
     */
    public function duesAgeing(User $user): array
    {
        $today = Clock::today();

        $rows = $this->ledger->scopedChallans($user)
            ->where('status', '!=', 'paid')
            ->with(['admission.student', 'admission.course', 'admission.enroller'])
            ->get();

        $buckets = [
            'current' => ['label' => 'Not yet due', 'tone' => 'paid', 'total' => 0, 'count' => 0],
            'd1_30' => ['label' => '1 to 30 days', 'tone' => 'unpaid', 'total' => 0, 'count' => 0],
            'd31_60' => ['label' => '31 to 60 days', 'tone' => 'unpaid', 'total' => 0, 'count' => 0],
            'd60_plus' => ['label' => 'Over 60 days', 'tone' => 'overdue', 'total' => 0, 'count' => 0],
        ];

        $students = [];

        foreach ($rows as $challan) {
            $due = Carbon::parse($challan->due_date)->startOfDay();
            $daysLate = $due->lessThan($today) ? $due->diffInDays($today) : 0;

            $key = match (true) {
                $daysLate === 0 => 'current',
                $daysLate <= 30 => 'd1_30',
                $daysLate <= 60 => 'd31_60',
                default => 'd60_plus',
            };

            $buckets[$key]['total'] += (int) $challan->net_amount;
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
            $students[$id]['amount'] += (int) $challan->net_amount;
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
     * @return Collection<int, object>
     */
    public function officerPerformance(Period $period): Collection
    {
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
                DB::raw("COALESCE(SUM(CASE WHEN challans.status = 'paid' THEN challans.net_amount ELSE 0 END), 0) as received"),
                DB::raw('COALESCE(SUM(challans.discount_amount), 0) as discounts'),
            ])
            ->orderByDesc(DB::raw('COUNT(DISTINCT admissions.id)'))
            ->get()
            ->map(function ($r) {
                $r->billed = (int) $r->billed;
                $r->received = (int) $r->received;
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
}
