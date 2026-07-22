<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A challan installment (spec §2.9).
 */
class Installment extends Model
{
    public $timestamps = false;

    protected $fillable = ['challan_id', 'seq', 'amount', 'due_date', 'status', 'paid_at'];

    protected $casts = [
        'seq' => 'integer',
        'amount' => 'integer',
        'due_date' => 'date',
        'paid_at' => 'datetime',
    ];

    public function challan(): BelongsTo
    {
        return $this->belongsTo(Challan::class);
    }
}
