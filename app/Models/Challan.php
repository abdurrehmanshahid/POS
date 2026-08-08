<?php

namespace App\Models;

use App\Support\Clock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Exactly one challan per admission (spec §2.8). Money is integer PKR; net is
 * derived = base − discount and never accepted from the client.
 */
class Challan extends Model
{
    use HasFactory;

    protected $fillable = [
        'challan_no', 'admission_id', 'base_amount', 'discount_amount',
        'discount_reason', 'discount_approved_by', 'net_amount', 'plan',
        'due_date', 'status', 'paid_via', 'paid_at',
    ];

    protected $casts = [
        'base_amount' => 'integer',
        'discount_amount' => 'integer',
        'net_amount' => 'integer',
        'due_date' => 'date',
        'paid_at' => 'datetime',
    ];

    /**
     * The ANCHOR enrolment: the one whose student and course headline this
     * invoice, and the only one `challans.admission_id` points at.
     *
     * An invoice may bill several enrolments (see {@see admissions()}), but a
     * fee document still needs one course to lead with, and every list, export
     * and report in the application reaches through this relation. Keeping it
     * is what let one invoice bill many enrolments without rewriting them all.
     */
    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }

    /**
     * Every enrolment billed on this invoice, anchor included.
     *
     * The institute bills a student for several courses on one document, so
     * this is what the voucher lists and what revenue-by-course apportions
     * across. Ordered by id so the courses print in the order they were
     * selected rather than in whatever order the database returns them.
     */
    public function admissions(): HasMany
    {
        return $this->hasMany(Admission::class)->orderBy('id');
    }

    /**
     * What this invoice bills across the courses still on it.
     *
     * The denominator for every per-course share, in PHP as in SQL (see
     * App\Support\RevenueShare). Cancelled enrolments are excluded so the
     * shares keep summing to the invoice after a course is dropped; falls back
     * to `base_amount` when nothing is live so a fully cancelled invoice does
     * not divide by zero.
     */
    public function liveBilledTotal(): int
    {
        $live = $this->relationLoaded('admissions')
            ? $this->admissions->where('status', '!=', 'cancelled')
            : $this->admissions()->where('status', '!=', 'cancelled')->get();

        return (int) ($live->sum('billed_amount') ?: $this->base_amount);
    }

    public function discountApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discount_approved_by');
    }

    public function installments(): HasMany
    {
        return $this->hasMany(Installment::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    // ---- Derived state -----------------------------------------------------

    /**
     * Total collected so far. Uses the loaded relation when it is already in
     * memory, so rendering a table of challans does not fire a query per row.
     */
    public function paidAmount(): int
    {
        return (int) ($this->relationLoaded('payments')
            ? $this->payments->sum('amount')
            : $this->payments()->sum('amount'));
    }

    /** What is still owed. Never negative: an overpayment is not a debt. */
    public function balance(): int
    {
        return max(0, $this->net_amount - $this->paidAmount());
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    /** Collected something, but not all of it. Drives the challan's advance line. */
    public function isPartiallyPaid(): bool
    {
        $paid = $this->paidAmount();

        return $paid > 0 && $paid < $this->net_amount;
    }

    /**
     * Whether any money has been banked against this challan.
     *
     * Cancellation tests this rather than `isPaid()`, because an advance leaves
     * `status` short of paid while the institute has genuinely taken the money.
     * Checking only the status would let a part-collected enrolment be cancelled
     * and silently erase that advance from every report (see ChallanActions).
     */
    public function hasCollections(): bool
    {
        return $this->isPaid() || $this->paidAmount() > 0;
    }

    /**
     * Unpaid and past a deadline, as a query.
     *
     * The SQL twin of {@see isOverdue()}. It existed in three places before
     * this — Ledger, Analytics::counts and a third, stale copy in the officer
     * scorecard that still tested only `due_date` and therefore undercounted
     * against the two beside it. One of them carried a comment reading
     * "Mirrors Ledger::overdueChallansQuery", which is the codebase asking for
     * this scope.
     *
     * `due_date` is a DATE column, so it is compared directly rather than
     * through whereDate(): wrapping it in DATE() costs the index for nothing.
     */
    public function scopeOverdue(Builder $query): Builder
    {
        $today = Clock::today()->toDateString();

        return $query
            ->where('challans.status', '!=', 'paid')
            ->where(function (Builder $q) use ($today) {
                $q->where('challans.due_date', '<', $today)
                    // A split plan's due_date tracks the LAST instalment, so it
                    // is blind to a first one already missed.
                    ->orWhereHas('installments', fn ($i) => $i
                        ->where('status', 'unpaid')
                        ->where('due_date', '<', $today));
            });
    }

    /** Unpaid and past its due date (spec §7.8). */
    public function isOverdue(): bool
    {
        if ($this->status === 'paid') {
            return false;
        }

        // On a split plan `due_date` tracks the LAST installment, so it is blind
        // to a first installment whose deadline has already passed. A student
        // who missed their advance by three weeks would read as perfectly
        // current until the final date arrived, which is the opposite of what
        // the schedule is for. Ask the schedule directly.
        if ($this->plan === 'split' && $this->nextInstallment()?->due_date?->lt(Clock::today())) {
            return true;
        }

        return $this->due_date !== null && $this->due_date->lt(Clock::today());
    }

    /**
     * The earliest installment still owed, or null when there is no schedule
     * or nothing left to pay.
     *
     * Answers from the loaded relation when there is one, so rendering a list
     * of challans does not fire a query per row. Callers that list challans
     * should eager load `installments` for the same reason they already eager
     * load `payments`.
     */
    public function nextInstallment(): ?Installment
    {
        if ($this->relationLoaded('installments')) {
            return $this->installments
                ->where('status', 'unpaid')
                ->sortBy('seq')
                ->first();
        }

        return $this->installments()->where('status', 'unpaid')->orderBy('seq')->first();
    }

    /** paid | overdue | unpaid, drives the status pill (spec §7.8). */
    public function paymentState(): string
    {
        if ($this->isPaid()) {
            return 'paid';
        }

        return $this->isOverdue() ? 'overdue' : 'unpaid';
    }
}
