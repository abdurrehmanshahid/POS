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
     * Money actually collected: Σ payments, not Σ net of the challans flagged
     * paid. The two agree whenever every challan was settled in one movement,
     * and diverge exactly when a student has paid an advance, which is the case
     * the flag could never express.
     */
    public function received(User $user): int
    {
        return (int) Payment::query()
            ->whereIn('challan_id', $this->scopedChallans($user)->select('challans.id'))
            ->sum('amount');
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
     * Jan–Jun fixed history + current month live received (spec §9.1).
     *
     * @return list<array{mon:string,amount:int}>
     */
    public function revenueTrend(User $user): array
    {
        $history = config('institute.revenue_history', []);
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'];

        $out = [];
        foreach ($history as $i => $amount) {
            $out[] = ['mon' => $months[$i] ?? '', 'amount' => (int) $amount];
        }
        $out[] = ['mon' => Clock::today()->format('M'), 'amount' => $this->received($user)];

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
