<?php

namespace App\Models;

use App\Models\Concerns\HasTwoFactorAuth;
use App\Services\TwoFactor;
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
     * Whenever the factor is switched on at all — and it is on by default.
     *
     * This used to be an unconditional `true`, on the reasoning that a super
     * admin without a second factor is a single stolen password away from total
     * data loss. That reasoning has not changed and is worth re-reading before
     * anyone leaves `twofa_required` off: this account can read and restore the
     * whole database, so it is the single worst account to leave on a password
     * alone.
     *
     * It is no longer hardcoded because the institute asked for the factor to be
     * switchable, and a super admin who could not be QA'd was the practical
     * result of the old rule. Ticking `twofa_required` in Settings restores the
     * previous behaviour exactly, with no re-enrolment.
     */
    public function requiresTwoFactor(): bool
    {
        return TwoFactor::enabled();
    }

    public function initials(): string
    {
        return Format::initials($this->name);
    }
}
