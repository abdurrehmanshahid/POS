<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * role → permission_key join row (spec §2.3).
 */
class RolePermission extends Model
{
    protected $fillable = ['role_id', 'permission_key'];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
