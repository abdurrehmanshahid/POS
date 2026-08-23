<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One correction against one payment.
 *
 * Append-only, exactly like {@see Payment} and {@see AuditLog}. Nothing in the
 * application updates or deletes a row here: a reversal recorded in error is
 * itself a fact about what happened, and the answer to it is a fresh payment,
 * not an edit. That rule is what lets any report reconstruct the ledger from
 * two tables and arithmetic.
 *
 * Money is integer PKR and always POSITIVE. The direction lives in the table
 * name, not in the sign — see the migration for why a negative `payments` row
 * was the wrong shape.
 */
class PaymentReversal extends Model
{
    protected $fillable = ['payment_id', 'amount', 'reason', 'approved_by'];

    protected $casts = [
        'amount' => 'integer',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** The supervisor who authorised it. Null only if that account was later removed. */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
