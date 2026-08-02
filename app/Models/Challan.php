<?php

namespace App\Models;

use App\Support\Clock;
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

    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
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

    /** Unpaid and past its due date (spec §7.8). */
    public function isOverdue(): bool
    {
        return $this->status !== 'paid' && $this->due_date !== null
            && $this->due_date->lt(Clock::today());
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
