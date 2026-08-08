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

    /**
     * The institute's settings row.
     *
     * Ordered explicitly. This is meant to be a singleton but nothing in the
     * schema enforces it, and an unordered `first()` lets the database return
     * whichever row it likes. If a second row ever appears, the institute name,
     * bank account and IBAN printed on fee challans would be chosen at the
     * engine's discretion, and could change between two requests.
     */
    public static function current(): self
    {
        return static::query()->orderBy('id')->firstOrFail();
    }
}
