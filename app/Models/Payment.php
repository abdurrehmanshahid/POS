<?php

namespace App\Models;

use App\Services\Sequences;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One handover of money against a challan (spec §7.6, extended).
 *
 * Money is integer PKR. A challan with no payment rows has been collected on
 * zero times, which is a different statement from "unpaid" and is why the
 * balance is derived here rather than stored on the challan.
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

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
