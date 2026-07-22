<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Single institute config row (spec §2.12). Use Setting::current().
 */
class Setting extends Model
{
    protected $fillable = [
        'name', 'bank', 'account', 'iban', 'next_challan_serial', 'twofa_required',
    ];

    protected $casts = [
        'next_challan_serial' => 'integer',
        'twofa_required' => 'boolean',
    ];

    public static function current(): self
    {
        return static::query()->firstOrFail();
    }
}
