<?php

namespace App\Models;

use App\Services\Sequences;
use App\Support\NetReceipts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One handover of money against a challan (spec §7.6, extended).
 *
 * Money is integer PKR. A challan with no payment rows has been collected on
 * zero times, which is a different statement from "unpaid" and is why the
 * balance is derived here rather than stored on the challan.
 *
 * `amount` is the GROSS handover and never changes — the table is append-only
 * and `amount` is UNSIGNED. A correction is a row in `payment_reversals`, and
 * what every report actually wants is {@see netAmount()}, or in SQL
 * {@see NetReceipts}. Reaching for `amount` in an aggregate is
 * almost always a bug: it overstates net receipts, which is the direction that
 * makes a cashier look like a thief.
 */
class Payment extends Model
{
    protected $fillable = ['challan_id', 'amount', 'method', 'received_by', 'received_at', 'note'];

    protected $casts = [
        'amount' => 'integer',
        'received_at' => 'datetime',
    ];

    /**
     * The receipt number, derived from this row's id rather than stored.
     *
     * See {@see Sequences::receiptNo()} for why it is derived: a
     * reprint has to produce the number the student is already holding.
     */
    public function receiptNo(): string
    {
        return Sequences::receiptNo($this->id);
    }

    public function challan(): BelongsTo
    {
        return $this->belongsTo(Challan::class);
    }

    /** Corrections recorded against this handover. Usually none. */
    public function reversals(): HasMany
    {
        return $this->hasMany(PaymentReversal::class);
    }

    /**
     * Total reversed against this payment.
     *
     * Answers from the loaded relation when it is already in memory, so
     * rendering a challan's payment history does not fire a query per row —
     * the same trick, and the same reason, as {@see Challan::paidAmount()}.
     */
    public function reversedAmount(): int
    {
        return (int) ($this->relationLoaded('reversals')
            ? $this->reversals->sum('amount')
            : $this->reversals()->sum('amount'));
    }

    /**
     * What this handover is actually worth: gross minus corrections.
     *
     * The PHP twin of {@see NetReceipts::ofPayment()}. Both must
     * mean the same thing; RevenueShare has already been bitten once by a
     * scalar and a SQL fragment drifting apart.
     */
    public function netAmount(): int
    {
        return $this->amount - $this->reversedAmount();
    }

    /** Fully reversed: banked, then given back in full. */
    public function isReversed(): bool
    {
        return $this->reversedAmount() >= $this->amount;
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
