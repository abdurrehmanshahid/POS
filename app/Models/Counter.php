<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A named, atomically-incremented counter. Used by RegistrationNumberGenerator to
 * hand out collision-free registration numbers under concurrent writes.
 */
class Counter extends Model
{
    protected $fillable = ['key', 'value'];

    protected $casts = ['value' => 'integer'];
}
