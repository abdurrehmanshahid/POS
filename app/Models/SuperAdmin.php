<?php

namespace App\Models;

use App\Models\Concerns\HasTwoFactorAuth;
use App\Support\Format;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * The platform owner (spec extension). Authenticates on the `superadmin` guard
 * at /superadmin, entirely separate from institute staff.
 *
 * There is intentionally no role or permission set on this model. Staff access
 * is least-privilege and data-driven; the super admin is the opposite, an
 * unconditional break-glass identity. Its safety comes from three places
 * instead of from permissions:
 *
 *   - mandatory TOTP, enforced before any panel screen renders;
 *   - a fresh TOTP code demanded for every destructive action;
 *   - an append-only audit row for everything it touches, written BEFORE the
 *     change so that even a purge leaves evidence of itself.
 */
class SuperAdmin extends Authenticatable
{
    use HasTwoFactorAuth;

    protected $fillable = ['name', 'username', 'email', 'password', 'is_active'];

    protected $hidden = [
        'password', 'remember_token',
        'two_factor_secret', 'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return array_merge($this->twoFactorCasts(), [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ]);
    }

    /**
     * Always. A super admin without a second factor is a single stolen password
     * away from total data loss, so enrolment is forced at first sign-in and
     * the factor can never be switched off, only rotated.
     */
    public function requiresTwoFactor(): bool
    {
        return true;
    }

    public function initials(): string
    {
        return Format::initials($this->name);
    }
}
