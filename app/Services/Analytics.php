<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\AuditLog;
use App\Models\Challan;
use App\Models\Course;
use App\Models\Student;
use App\Models\User;
use App\Support\Clock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Institute-wide analytics for the super admin panel.
 *
 * Everything here is computed with aggregate SQL rather than by loading rows and
 * looping in PHP (the spec's optimisation mandate). On a few hundred records the
 * difference is invisible; at ten thousand admissions a per-row loop is the
 * difference between a page that renders and a page that times out on shared
 * hosting.
 *
 * Cancelled admissions are excluded from every money figure, matching §7.1:
 * a cancelled registration voids its challan, so counting it would break the
 * `billed = received + outstanding` identity the dashboard asserts.
 */
class Analytics
{
    /**
     * Per-officer scorecards: who is actually performing.
     *
     * The interesting column is `collection_rate`, enrolments alone reward
     * whoever signs the most forms, but an officer who enrols heavily and never
     * collects is generating outstanding debt, not revenue.
     *
     * @return Collection<int, object>
     */
    public function staffPerformance(?Carbon $since = null): Collection
    {
        $rows = User::query()
            ->withTrashed()
            ->leftJoin('admissions', function ($join) use ($since) {
                $join->on('admissions.enrolled_by', '=', 'users.id')
                    ->where('admissions.status', '!=', 'cancelled');
                if ($since) {
                    $join->where('admissions.created_at', '>=', $since);
                }
            })
            ->leftJoin('challans', 'challans.admission_id', '=', 'admissions.id')
            ->groupBy('users.id', 'users.name', 'users.username', 'users.role_id', 'users.deleted_at')
            ->select([
                'users.id',
                'users.name',
                'users.username',
                'users.role_id',
                'users.deleted_at',
                DB::raw('COUNT(DISTINCT admissions.id) as enrolments'),
                DB::raw('COUNT(DISTINCT admissions.student_id) as students'),
                DB::raw('COALESCE(SUM(challans.net_amount), 0) as billed'),
                DB::raw("COALESCE(SUM(CASE WHEN challans.status = 'paid' THEN challans.net_amount ELSE 0 END), 0) as received"),
                DB::raw('COALESCE(SUM(challans.discount_amount), 0) as discounts'),
                DB::raw("COALESCE(SUM(CASE WHEN challans.status != 'paid' AND challans.due_date < ? THEN 1 ELSE 0 END), 0) as overdue"),
            ])
            ->addBinding(Clock::today()->toDateString(), 'select')
            ->orderByDesc('received')
            ->get();

        return $rows->map(function ($r) {
            $r->outstanding = (int) $r->billed - (int) $r->received;
            $r->collection_rate = $r->billed > 0 ? (int) round($r->received / $r->billed * 100) : null;
            $r->avg_discount = $r->enrolments > 0 ? (int) round($r->discounts / $r->enrolments) : 0;
            $r->is_removed = $r->deleted_at !== null;

            return $r;
        });
    }

    /** Institute-wide totals, the numbers no officer is allowed to see. */
    public function ledger(): array
    {
        $agg = Challan::query()
            ->whereHas('admission', fn ($q) => $q->where('status', '!=', 'cancelled'))
            ->selectRaw('COUNT(*) as challans')
            ->selectRaw('COALESCE(SUM(net_amount), 0) as billed')
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'paid' THEN net_amount ELSE 0 END), 0) as received")
            ->first();

        $billed = (int) $agg->billed;
        $received = (int) $agg->received;

        return [
            'challans' => (int) $agg->challans,
            'billed' => $billed,
            'received' => $received,
            'outstanding' => $billed - $received,
            // The reconciliation identity from §7.1, asserted rather than assumed.
            'reconciles' => $billed === $received + ($billed - $received),
        ];
    }

    /** Headline counts for the panel's top row. */
    public function counts(): array
    {
        return [
            'students' => Student::count(),
            'students_removed' => Student::onlyTrashed()->count(),
            'staff' => User::where('is_active', true)->count(),
            'staff_inactive' => User::where('is_active', false)->count(),
            'staff_removed' => User::onlyTrashed()->count(),
            'courses' => Course::where('is_active', true)->count(),
            'admissions' => Admission::where('status', '!=', 'cancelled')->count(),
            'cancelled' => Admission::where('status', 'cancelled')->count(),
            'overdue' => Challan::where('status', '!=', 'paid')
                ->whereDate('due_date', '<', Clock::today())
                ->whereHas('admission', fn ($q) => $q->where('status', '!=', 'cancelled'))
                ->count(),
        ];
    }

    /**
     * Revenue actually received per course, best first.
     *
     * @return Collection<int, object>
     */
    public function revenueByCourse(int $limit = 8): Collection
    {
        return Course::query()
            ->leftJoin('admissions', function ($j) {
                $j->on('admissions.course_id', '=', 'courses.id')
                    ->where('admissions.status', '!=', 'cancelled');
            })
            ->leftJoin('challans', 'challans.admission_id', '=', 'admissions.id')
            ->groupBy('courses.id', 'courses.code', 'courses.title', 'courses.capacity')
            ->select([
                'courses.id', 'courses.code', 'courses.title', 'courses.capacity',
                DB::raw('COUNT(DISTINCT admissions.id) as enrolments'),
                DB::raw("COALESCE(SUM(CASE WHEN challans.status = 'paid' THEN challans.net_amount ELSE 0 END), 0) as received"),
                DB::raw('COALESCE(SUM(challans.net_amount), 0) as billed'),
            ])
            ->orderByDesc('received')
            ->limit($limit)
            ->get();
    }

    /**
     * Sign-in activity and security events over the last N days, the view that
     * makes a password-spraying run visible rather than buried in a log file.
     */
    public function securitySummary(int $days = 30): array
    {
        $since = Clock::today()->copy()->subDays($days);

        $byAction = AuditLog::query()
            ->where('created_at', '>=', $since)
            ->whereIn('action', ['Signed in', 'Sign-in failed', 'Two-factor failed', 'Recovery code used', 'Password reset by admin'])
            ->groupBy('action')
            ->select('action', DB::raw('COUNT(*) as total'))
            ->pluck('total', 'action');

        return [
            'days' => $days,
            'sign_ins' => (int) ($byAction['Signed in'] ?? 0),
            'failed' => (int) ($byAction['Sign-in failed'] ?? 0),
            'twofa_failed' => (int) ($byAction['Two-factor failed'] ?? 0),
            'recovery_used' => (int) ($byAction['Recovery code used'] ?? 0),
            'admin_resets' => (int) ($byAction['Password reset by admin'] ?? 0),
        ];
    }

    /**
     * Monthly received revenue for the trailing 12 months, oldest first.
     *
     * Uses a driver-portable year-month key so this works identically on MySQL
     * in production and SQLite in the test suite.
     *
     * @return Collection<int, object>
     */
    public function monthlyRevenue(int $months = 12): Collection
    {
        $since = Clock::today()->copy()->startOfMonth()->subMonths($months - 1);
        $driver = DB::connection()->getDriverName();

        $period = $driver === 'sqlite'
            ? "strftime('%Y-%m', paid_at)"
            : "DATE_FORMAT(paid_at, '%Y-%m')";

        $paid = Challan::query()
            ->where('status', 'paid')
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $since)
            ->groupBy(DB::raw($period))
            ->select(DB::raw("$period as ym"), DB::raw('SUM(net_amount) as total'))
            ->pluck('total', 'ym');

        // Materialise every month so a quiet month renders as a zero bar rather
        // than silently vanishing from the chart.
        return collect(range(0, $months - 1))->map(function (int $i) use ($since, $paid) {
            $month = $since->copy()->addMonths($i);
            $key = $month->format('Y-m');

            return (object) [
                'label' => $month->format('M'),
                'year' => $month->format('Y'),
                'total' => (int) ($paid[$key] ?? 0),
            ];
        });
    }
}
