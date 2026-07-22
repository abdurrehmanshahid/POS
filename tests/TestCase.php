<?php

namespace Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Mark an identity as having completed TOTP enrolment.
     *
     * Roles that require a second factor are pinned to the setup screen until
     * they enrol (see EnsureTwoFactorEnrolled), so any test that wants to
     * exercise a real screen as an Administrator has to get past that gate
     * first. The gate itself is tested directly in TwoFactorTest.
     */
    protected function enrolTwoFactor(Model $identity, string $secret = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'): Model
    {
        $identity->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
            'two_factor_last_timestep' => null,
        ])->save();

        return $identity->refresh();
    }
}
