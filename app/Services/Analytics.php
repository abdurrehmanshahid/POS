<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\AuditLog;
use App\Models\Challan;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Support\Clock;
use App\Support\RevenueShare;
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
        // Collected in its own pass, keyed by officer. Joining `payments` into
        // the query below would repeat a challan once per part payment and
        // double that officer's `billed` total.
        $collected = $this->collectedByOfficer($since);

        $rows = User::query()
            ->withTrashed()
            ->leftJoin('admissions', function ($join) use ($since) {
                $join->on('admissions.enrolled_by', '=', 'users.id')
                    ->where('admissions.status', '!=', 'cancelled');
                if ($since) {
                    $join->where('admissions.created_at', '>=', $since);
                }
            })
            // Through the enrolment's own invoice link. Joining on
            // `challans.admission_id` only ever matched the anchor, so every
            // non-anchor course an officer enrolled counted as billing nothing
            // and their collection rate was measured against a total that
            // excluded most of their own work.
            ->leftJoin('challans', 'challans.id', '=', 'admissions.challan_id')
            ->groupBy('users.id', 'users.name', 'users.username', 'users.role_id', 'users.deleted_at')
            ->select([
                'users.id',
                'users.name',
                'users.username',
                'users.role_id',
                'users.deleted_at',
                DB::raw('COUNT(DISTINCT admissions.id) as enrolments'),
                DB::raw('COUNT(DISTINCT admissions.student_id) as students'),
                DB::raw('COALESCE('.RevenueShare::sumOfBilled().', 0) as billed'),
                DB::raw('COALESCE(SUM(challans.discount_amount), 0) as discounts'),
                // Counts DISTINCT invoices, and tests the same deadline the rest
                // of the app does. Left as a bare due_date comparison it
                // undercounted against counts()['overdue'] two methods below,
                // which had been taught about split plans and this had not.
                DB::raw("COUNT(DISTINCT CASE WHEN challans.status != 'paid' AND (challans.due_date < ? OR EXISTS (SELECT 1 FROM installments i WHERE i.challan_id = challans.id AND i.status = 'unpaid' AND i.due_date < ?)) THEN challans.id END) as overdue"),
            ])
            // Two bindings: the overdue expression tests today against both the
            // challan's own due date and its instalment schedule.
            ->addBinding([Clock::today()->toDateString(), Clock::today()->toDateString()], 'select')
            ->get();

        return $rows->map(function ($r) use ($collected) {
            $r->billed = (int) $r->billed;
            $r->received = (int) ($collected[$r->id] ?? 0);
            $r->outstanding = $r->billed - $r->received;
            $r->collection_rate = $r->billed > 0 ? (int) round($r->received / $r->billed * 100) : null;
            $r->avg_discount = $r->enrolments > 0 ? (int) round($r->discounts / $r->enrolments) : 0;
            $r->is_removed = $r->deleted_at !== null;

            return $r;
        })->sortByDesc('received')->values();
    }

    /**
     * Total collected against each officer's enrolments.
     *
     * @return array<int, int> officer id => collected
     */
    private function collectedByOfficer(?Carbon $since = null): array
    {
        return Payment::query()
            ->join('challans', 'challans.id', '=', 'payments.challan_id')
            ->join('admissions', 'admissions.challan_id', '=', 'challans.id')
            ->where('admissions.status', '!=', 'cancelled')
            ->when($since, fn ($q) => $q->where('admissions.created_at', '>=', $since))
            ->groupBy('admissions.enrolled_by')
            // Apportioned, so an invoice covering courses enrolled by two
            // different officers credits each with the share they actually
            // brought in rather than giving all of it to whoever anchored it.
            ->select('admissions.enrolled_by', DB::raw(RevenueShare::sumOfPayments().' as total'))
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->enrolled_by => (int) $r->total])
            ->all();
    }

    /** Institute-wide totals, the numbers no officer is allowed to see. */
    public function ledger(): array
    {
        $agg = Challan::query()
            ->whereHas('admissions', fn ($q) => $q->where('status', '!=', 'cancelled'))
            ->selectRaw('COUNT(*) as challans')
            ->selectRaw('COALESCE(SUM(net_amount), 0) as billed')
            ->first();

        $billed = (int) $agg->billed;

        // Σ payments, matching the staff dashboard. Reading the paid flag here
        // made the owner console under-report the institute's own revenue by
        // every advance it had taken but not yet settled.
        $received = (int) Payment::query()
            ->whereIn('payments.challan_id', Challan::query()
                ->whereHas('admissions', fn ($q) => $q->where('status', '!=', 'cancelled'))
                ->select('challans.id'))
            ->sum('amount');

        return [
            'challans' => (int) $agg->challans,
            'billed' => $billed,
            'received' => $received,
            'outstanding' => $billed - $received,
            // Collections can legitimately exceed nothing here, but they must
            // never exceed what was billed. Previously this asserted
            // `$billed === $received + ($billed - $received)`, which is true for
            // every possible input and therefore checked nothing at all.
            'reconciles' => $received <= $billed,
        ];
    }

    /** Headline counts for the panel's top row. */
    public function counts(): array
    {
        return [
            // People the institute teaches. Contacts — a desk tenant, a
            // certificate reissue — are billed and collected from but are not
            // students, and counting them here put a number on the owner's
            // console that no trainer's register could ever reproduce.
            'students' => Student::query()->students()->count(),
            'contacts' => Student::query()->contacts()->count(),
            'students_removed' => Student::onlyTrashed()->count(),
            'staff' => User::where('is_active', true)->count(),
            'staff_inactive' => User::where('is_active', false)->count(),
            'staff_removed' => User::onlyTrashed()->count(),
            'courses' => Course::where('is_active', true)->count(),
            'admissions' => Admission::where('status', '!=', 'cancelled')->count(),
            'cancelled' => Admission::where('status', 'cancelled')->count(),
            // Live enrolment invoices, plus charges — which have no admission
            // and so were silently excluded by the `whereHas` alone. A room
            // booking a month past its due date is exactly as overdue as a fee
            // is, and it went uncounted on the one screen that reports overdues.
            'overdue' => Challan::query()
                ->overdue()
                ->where(fn ($q) => $q
                    ->whereHas('admissions', fn ($a) => $a->where('status', '!=', 'cancelled'))
                    ->orWhereNull('admission_id'))
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
        // Through `admissions.challan_id` and apportioned, matching the staff
        // dashboard and the Reports screen. Reaching the invoice by its anchor
        // credited a grouped invoice entirely to whichever course headed it.
        $collected = Payment::query()
            ->join('challans', 'challans.id', '=', 'payments.challan_id')
            ->join('admissions', 'admissions.challan_id', '=', 'challans.id')
            ->where('admissions.status', '!=', 'cancelled')
            ->groupBy('admissions.course_id')
            ->select('admissions.course_id', DB::raw(RevenueShare::sumOfPayments().' as total'))
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->course_id => (int) $r->total])
            ->all();

        // Billed and enrolments come from the join; received is merged in from
        // the payments aggregate above, so the limit is applied AFTER ordering
        // by the figure the caller actually asked to rank on.
        return Course::query()
            ->leftJoin('admissions', function ($j) {
                $j->on('admissions.course_id', '=', 'courses.id')
                    ->where('admissions.status', '!=', 'cancelled');
            })
            ->leftJoin('challans', 'challans.id', '=', 'admissions.challan_id')
            ->groupBy('courses.id', 'courses.code', 'courses.title', 'courses.capacity')
            ->select([
                'courses.id', 'courses.code', 'courses.title', 'courses.capacity',
                DB::raw('COUNT(DISTINCT admissions.id) as enrolments'),
                // The course's share of the invoice's net, not the whole
                // invoice. Summing net_amount per enrolment counted a grouped
                // invoice once per course and inflated "billed" by the course
                // count on the owner's own console.
                DB::raw('COALESCE('.RevenueShare::sumOfBilled().', 0) as billed'),
            ])
            ->get()
            ->map(function ($c) use ($collected) {
                $c->billed = (int) $c->billed;
                $c->received = $collected[(int) $c->id] ?? 0;

                return $c;
            })
            ->sortByDesc('received')
            ->take($limit)
            ->values();
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
            ? "strftime('%Y-%m', payments.received_at)"
            : "DATE_FORMAT(payments.received_at, '%Y-%m')";

        // Dated by when each handover arrived, so a fee collected across two
        // months appears in both rather than landing wholly in the month it
        // happened to finish in.
        $paid = Payment::query()
            ->where('payments.received_at', '>=', $since)
            ->groupBy(DB::raw($period))
            ->select(DB::raw("$period as ym"), DB::raw('SUM(payments.amount) as total'))
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
