<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\Challan;
use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use App\Support\Clock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * All derived, scoped figures (spec §7.1 / §9). Money is never hand-typed: every
 * total is Σ net over the scoped, non-cancelled set. billed = received +
 * outstanding always reconciles. Officers (no scope.all) see only their own
 * enrolments; the money blocks are further gated by revenue.view at the view.
 */
class Ledger
{
    /** Challans on non-cancelled admissions visible to the user. */
    public function scopedChallans(User $user): Builder
    {
        return Challan::query()->whereHas('admission', function (Builder $a) use ($user) {
            $a->where('status', '!=', 'cancelled');
            if (! $user->hasPermission('scope.all')) {
                $a->where('enrolled_by', $user->id);
            }
        });
    }

    public function billed(User $user): int
    {
        return (int) $this->scopedChallans($user)->sum('net_amount');
    }

    /**
     * Collections against challans visible to the user.
     *
     * The single entry point every money figure in the system starts from,
     * including the whole of {@see Reporting}. Summing the `payments` table
     * rather than the challans flagged paid is the difference between reporting
     * what was banked and reporting what was finished, and those two answers
     * diverge for every student who has paid an advance.
     */
    public function scopedPayments(User $user): Builder
    {
        return Payment::query()
            ->whereIn('challan_id', $this->scopedChallans($user)->select('challans.id'));
    }

    /**
     * Money actually collected: Σ payments, not Σ net of the challans flagged
     * paid. The two agree whenever every challan was settled in one movement,
     * and diverge exactly when a student has paid an advance, which is the case
     * the flag could never express.
     */
    public function received(User $user): int
    {
        return (int) $this->scopedPayments($user)->sum('amount');
    }

    public function outstanding(User $user): int
    {
        return $this->billed($user) - $this->received($user);
    }

    public function receivedPct(User $user): int
    {
        $billed = $this->billed($user);

        return $billed > 0 ? (int) round($this->received($user) / $billed * 100) : 0;
    }

    public function challanCount(User $user): int
    {
        return $this->scopedChallans($user)->count();
    }

    /** Distinct students in the scoped, non-cancelled admissions (spec §6). */
    public function activeStudentsCount(User $user): int
    {
        return $this->scopedAdmissions($user)->distinct()->count('student_id');
    }

    public function regsThisMonth(User $user): int
    {
        $today = Clock::today();

        return $this->scopedAdmissions($user)
            ->whereYear('created_at', $today->year)
            ->whereMonth('created_at', $today->month)
            ->count();
    }

    public function overdueCount(User $user): int
    {
        return $this->overdueChallansQuery($user)->count();
    }

    /** @return Collection<int,Challan> */
    public function overdueChallans(User $user): Collection
    {
        return $this->overdueChallansQuery($user)
            ->with(['admission.student', 'admission.course'])
            ->orderBy('due_date')
            ->get();
    }

    /**
     * Top courses by paid net in scope (spec §9.1).
     *
     * @return list<array{title:string,code:string,amount:int}>
     */
    public function revenueByCourse(User $user, int $limit = 5): array
    {
        // Σ payments, matching received(): a course where students are halfway
        // through paying should show the half that arrived, not zero and not all.
        return $this->scopedChallans($user)
            ->join('payments', 'payments.challan_id', '=', 'challans.id')
            ->join('admissions', 'admissions.id', '=', 'challans.admission_id')
            ->join('courses', 'courses.id', '=', 'admissions.course_id')
            ->groupBy('courses.id', 'courses.title', 'courses.code')
            ->orderByDesc('amount')
            ->limit($limit)
            ->get([
                'courses.title as title',
                'courses.code as code',
                DB::raw('SUM(payments.amount) as amount'),
            ])
            ->map(fn ($r) => ['title' => $r->title, 'code' => $r->code, 'amount' => (int) $r->amount])
            ->all();
    }

    /**
     * Collections per month over the trailing window, oldest first.
     *
     * Every bar is now computed from `payments.received_at`. The first six were
     * previously read from `config('institute.revenue_history')`, a hardcoded
     * list of six invented figures, and only the last bar was real. That is the
     * same fabrication the Reports screen's 92% attendance card was deleted for,
     * and it was worse here: invented bars sitting directly beside reconciled
     * totals borrow their credibility.
     *
     * The last bar also used to show all-time received rather than the current
     * month, so the chart's final column silently answered a different question
     * from every column before it.
     *
     * Months with no takings render as a visible zero rather than vanishing, so
     * the chart cannot imply a quiet month never happened.
     *
     * @return list<array{mon:string,amount:int}>
     */
    public function revenueTrend(User $user, int $months = 7): array
    {
        $start = Clock::today()->copy()->startOfMonth()->subMonths($months - 1);
        $driver = DB::connection()->getDriverName();

        $expr = $driver === 'sqlite'
            ? "strftime('%Y-%m', payments.received_at)"
            : "DATE_FORMAT(payments.received_at, '%Y-%m')";

        $totals = $this->scopedPayments($user)
            ->where('payments.received_at', '>=', $start)
            ->groupBy(DB::raw($expr))
            ->select(DB::raw("$expr as ym"), DB::raw('SUM(payments.amount) as total'))
            ->pluck('total', 'ym');

        $out = [];
        for ($i = 0; $i < $months; $i++) {
            $month = $start->copy()->addMonths($i);
            $out[] = [
                'mon' => $month->format('M'),
                'amount' => (int) ($totals[$month->format('Y-m')] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Courses with seats running low, admin-only (spec §7.9).
     *
     * @return list<array{course:Course,left:int,used:int}>
     */
    public function nearFullCourses(User $user): array
    {
        if (! $user->hasPermission('scope.all')) {
            return [];
        }

        $out = [];
        $courses = Course::query()->where('is_active', true)->with('admissions')->get();
        foreach ($courses as $course) {
            if ($course->capacity === null) {
                continue;
            }
            $used = $course->seatsUsed();
            $left = $course->capacity - $used;
            $threshold = max(2, $course->capacity * 0.15);
            if ($left >= 0 && $left <= $threshold) {
                $out[] = ['course' => $course, 'left' => $left, 'used' => $used];
            }
        }

        return $out;
    }

    // ---- internals ---------------------------------------------------------

    private function scopedAdmissions(User $user): Builder
    {
        return Admission::query()
            ->where('status', '!=', 'cancelled')
            ->when(! $user->hasPermission('scope.all'), fn (Builder $q) => $q->where('enrolled_by', $user->id));
    }

    private function overdueChallansQuery(User $user): Builder
    {
        return $this->scopedChallans($user)
            ->where('challans.status', '!=', 'paid')
            ->whereDate('due_date', '<', Clock::today()->toDateString());
    }
}
