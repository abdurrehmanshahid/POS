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

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    // ---- Derived state -----------------------------------------------------

    public function isPaid(): bool
    {
        return $this->status === 'paid';
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
