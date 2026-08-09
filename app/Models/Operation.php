<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A write operation that has already happened. See the migration for why.
 *
 * Only `created_at` is kept: a row here is a fact about a moment, and there is
 * no later moment at which it could change.
 *
 * Deliberately bare. Nothing reads this table — the whole mechanism is one
 * insert and the unique index that rejects the second one — so there is no
 * `user()` relation until something needs to render one.
 */
class Operation extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['key', 'name', 'user_id'];
}
