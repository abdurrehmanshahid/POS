<?php

namespace App\Models;

use App\Support\Format;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * The platform owner (spec extension). Authenticates on the `superadmin` guard
 * at /superadmin, entirely separate from institute staff.
 *
 * There is intentionally no role or permission set on this model. Staff access
 * is least-privilege and data-driven; the super admin is the opposite, an
 * unconditional break-glass identity. Its safety comes from two places
 * instead of from permissions:
 *
 *   - the password demanded again for every destructive action;
 *   - an append-only audit row for everything it touches, written BEFORE the
 *     change so that even a purge leaves evidence of itself.
 */
class SuperAdmin extends Authenticatable
{
    protected $fillable = ['name', 'username', 'email', 'password', 'is_active'];

    protected $hidden = [
        'password', 'remember_token',
        'two_factor_secret', 'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function initials(): string
    {
        return Format::initials($this->name);
    }
}
