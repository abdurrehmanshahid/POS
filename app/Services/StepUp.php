<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Step-up authentication for destructive actions.
 *
 * Holding a session proves you signed in at some point; it does not prove the
 * person at the keyboard right now is still you. Before anything irreversible
 * we therefore re-challenge, which defends against the realistic threat in an
 * institute office: an unlocked, unattended machine.
 *
 * Policy (chosen deliberately over a time-boxed "sudo window"):
 *
 *   - Anyone holding a confirmed second factor must supply a FRESH TOTP code
 *     for EVERY destructive action. There is no grace period. Because
 *     {@see TwoFactor::verify()} burns the timestep it accepted, the same six
 *     digits cannot authorise two deletions, the second one has to wait for
 *     the authenticator to roll over.
 *
 *   - Anyone without a second factor (an officer cancelling a registration,
 *     say) re-enters their PASSWORD instead. Weaker, but it still requires a
 *     secret the passer-by at an unlocked desk does not have, and it keeps the
 *     flow usable for staff who are not enrolled.
 *
 * Recovery codes are NOT accepted here. They exist to get you back in after
 * losing a phone; letting them stand in for a live code would mean a written-
 * down slip of paper is enough to purge records.
 */
class StepUp
{
    public function __construct(private readonly TwoFactor $totp) {}

    /**
     * Re-authenticate the actor or abort.
     *
     * @param  Model  $actor  the signed-in User or SuperAdmin
     * @param  string  $secret  the TOTP code, or the password when not enrolled
     *
     * @throws RuntimeException with a message safe to show the user
     */
    public function confirm(Model $actor, string $secret): void
    {
        $secret = trim($secret);

        if ($secret === '') {
            throw new RuntimeException($this->usesTotp($actor)
                ? 'Enter the 6-digit code from your authenticator app.'
                : 'Enter your password to confirm.');
        }

        $ok = $this->usesTotp($actor)
            ? $this->totp->verify($actor, $secret)
            : Hash::check($secret, $actor->password);

        if (! $ok) {
            // Deliberately vague about *why*, a "code already used" message
            // would confirm to an observer that they had guessed correctly once.
            throw new RuntimeException($this->usesTotp($actor)
                ? 'That code is not valid. Wait for the next code and try again.'
                : 'That password is not correct.');
        }
    }

    /** Which challenge this actor will be asked for, drives the dialog copy. */
    public function usesTotp(Model $actor): bool
    {
        return method_exists($actor, 'hasTwoFactorEnabled') && $actor->hasTwoFactorEnabled();
    }

    /** Label for the confirmation input. */
    public function challengeLabel(Model $actor): string
    {
        return $this->usesTotp($actor)
            ? 'Authenticator code'
            : 'Your password';
    }
}
