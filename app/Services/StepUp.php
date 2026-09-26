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
 * we therefore ask for the actor's PASSWORD again, which defends against the
 * realistic threat in an institute office: an unlocked, unattended machine.
 */
class StepUp
{
    /**
     * Re-authenticate the actor or abort.
     *
     * @param  Model  $actor  the signed-in User or SuperAdmin
     * @param  string  $secret  the actor's password
     *
     * @throws RuntimeException with a message safe to show the user
     */
    public function confirm(Model $actor, string $secret): void
    {
        $secret = trim($secret);

        if ($secret === '') {
            throw new RuntimeException('Enter your password to confirm.');
        }

        if (! Hash::check($secret, $actor->password)) {
            throw new RuntimeException('That password is not correct.');
        }
    }
}
