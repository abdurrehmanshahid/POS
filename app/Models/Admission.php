<?php

namespace App\Models;

use App\Support\RevenueShare;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One enrolment: student × course (spec §2.7). `enrolled_by` is the source of
 * truth for who registered whom and the anchor for officer scoping.
 */
class Admission extends Model
{
    use HasFactory;

    protected $fillable = [
        'reg_no', 'student_id', 'course_id', 'cohort_id', 'enrolled_by', 'status', 'rejection_reason',
        'challan_id', 'billed_amount', 'import_key',
    ];

    protected $casts = [
        'billed_amount' => 'integer',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * The batch this enrolment sits in. Nullable: enrolments that predate
     * cohorts have none, and inventing one would be fabricating history.
     */
    public function cohort(): BelongsTo
    {
        return $this->belongsTo(Cohort::class);
    }

    /**
     * The officer who signed this enrolment.
     *
     * `withTrashed()` for the same reason {@see AuditLog::actor()}
     * uses it: a signature has to outlive the account that made it. Staff soft
     * delete, so without this the relation resolves to null the moment an
     * officer is removed, and every screen that prints `$a->enroller->name`
     * — the registrations list, its drawer, the challan drawer — fatals with
     * "Attempt to read property on null" on rows that were fine yesterday.
     * `enrolled_by` is the source of truth for who registered whom and is never
     * editable; the history must still be able to say the name out loud.
     */
    public function enroller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enrolled_by')->withTrashed();
    }

    /**
     * What this one enrolment costs, after the invoice's discount.
     *
     * `billed_amount` is this course's gross fee. The discount is negotiated
     * over the invoice as a whole, so a course's real cost is its gross share
     * scaled by the same ratio the invoice was discounted by.
     *
     * Exists because every per-course row in the UI used to render the whole
     * invoice's `net_amount`. That was right while an invoice billed exactly
     * one course, and became badly wrong the moment one could bill three: the
     * student drawer listed three courses each showing the full fee, reading
     * as three times what was owed.
     *
     * Rounding means N shares need not re-sum to the invoice to the rupee, so
     * this is for display only. Anything that has to reconcile reads the
     * invoice itself.
     */
    public function netShare(): int
    {
        $challan = $this->challan;

        if (! $challan) {
            return 0;
        }

        // A dropped course costs nothing.
        if ($this->status === 'cancelled') {
            return 0;
        }

        // Delegated so the figure in the drawer and the figure in the reports
        // come from one rule, and divided by the LIVE total for the same reason
        // the SQL is: after a cancellation the remaining courses carry the whole
        // invoice between them, and the shares still add up to it.
        return RevenueShare::of(
            (int) $challan->net_amount,
            $this->billed_amount,
            $challan->liveBilledTotal(),
        );
    }

    /**
     * The invoice this enrolment is billed on.
     *
     * A BelongsTo through `challan_id` rather than the old HasOne through
     * `challans.admission_id`, because an invoice can now bill several
     * enrolments and only one of them is the anchor the challan points back at.
     * Under the old relation the second and third courses of a grouped
     * registration would each report having no challan while being billed on
     * one.
     *
     * Still nullable: enrolling without raising a fee is supported.
     */
    public function challan(): BelongsTo
    {
        return $this->belongsTo(Challan::class);
    }

    // ---- Scopes ------------------------------------------------------------

    /** Officers see only their own enrolments; admins (scope.all) see all. */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        if ($user->hasPermission('scope.all')) {
            return $q;
        }

        return $q->where('enrolled_by', $user->id);
    }

    /** Not cancelled, counts toward money totals and active lists. */
    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', '!=', 'cancelled');
    }
}
