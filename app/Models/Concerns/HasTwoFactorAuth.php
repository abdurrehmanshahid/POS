<?php

namespace App\Models\Concerns;

use App\Services\TwoFactor;
use Illuminate\Support\Facades\Hash;

/**
 * Shared TOTP state for any authenticatable identity (staff `User` and
 * `SuperAdmin` both use it). The cryptography itself lives in
 * {@see TwoFactor}; this trait only owns the stored state.
 *
 * TOTP in one paragraph: the server and the phone share a random secret. Both
 * hash that secret together with the current 30-second "timestep" (unix time
 * / 30) and take six digits from the result. No network call is involved,
 * which is precisely why Google Authenticator is free and works offline, and
 * why the secret is the only thing that ever needs protecting.
 *
 * Storage rules enforced here:
 *  - The secret is encrypted at rest (AES-256-GCM under APP_KEY). A stolen DB
 *    dump alone must not yield working codes.
 *  - Recovery codes are HASHED, not merely encrypted, so they cannot be read
 *    back even by us. That is why they are displayed exactly once at
 *    generation: losing them means regenerating, never recovering.
 *  - Enrolment is only complete once `two_factor_confirmed_at` is set, which
 *    requires the user to prove their phone produces a valid code. A partially
 *    enrolled account can never lock its owner out.
 */
trait HasTwoFactorAuth
{
    /**
     * Casts contributed by this trait. Models merge these into their own
     * `casts()` so the encryption is impossible to forget.
     *
     * @return array<string, string>
     */
    protected function twoFactorCasts(): array
    {
        return [
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /** True once the identity has proved it can generate a valid code. */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Whether this identity is OBLIGED to hold a second factor. Overridden per
     * model: super admins always are; staff inherit it from their role.
     */
    abstract public function requiresTwoFactor(): bool;

    /**
     * Must this identity be pushed into enrolment before it may do anything?
     * True when 2FA is required but not yet confirmed.
     */
    public function mustEnrolTwoFactor(): bool
    {
        return $this->requiresTwoFactor() && ! $this->hasTwoFactorEnabled();
    }

    /**
     * Spend a recovery code. Returns false if it does not match a live code.
     *
     * Codes are single-use: the matched hash is removed before returning, so a
     * shoulder-surfed code is worthless the moment it has been used once.
     */
    public function consumeRecoveryCode(string $plain): bool
    {
        $plain = strtoupper(trim($plain));
        $codes = $this->two_factor_recovery_codes ?? [];

        foreach ($codes as $i => $hash) {
            if (Hash::check($plain, $hash)) {
                unset($codes[$i]);
                $this->forceFill([
                    'two_factor_recovery_codes' => array_values($codes),
                ])->save();

                return true;
            }
        }

        return false;
    }

    /** How many unused recovery codes remain, surfaced in the UI as a warning. */
    public function recoveryCodesRemaining(): int
    {
        return count($this->two_factor_recovery_codes ?? []);
    }

    /** Tear down the second factor entirely (super-admin reset path). */
    public function clearTwoFactor(): void
    {
        $this->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_timestep' => null,
        ])->save();
    }
}
