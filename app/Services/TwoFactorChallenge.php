<?php

namespace App\Services;

use App\Models\SuperAdmin;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;

/**
 * The half-authenticated handshake between "password accepted" and "signed in".
 *
 * The critical rule of a second factor is that the FIRST factor must not grant
 * a session on its own. A naive implementation logs the user in and then
 * redirects to a code prompt, but at that point they already hold an
 * authenticated session, and anything that skips the redirect (a direct URL, an
 * XHR, a stale tab) is straight past the second factor.
 *
 * So instead we park the verified identity in the session as a *pending*
 * intent, never calling Auth::login(), and only promote it to a real session
 * once a valid code arrives. If the browser walks away mid-handshake, nothing
 * was granted.
 *
 * The pending record stores an id and a guard name, not a serialised model, so
 * a deactivation or deletion between the two steps is picked up when we re-read
 * the row at completion time.
 */
class TwoFactorChallenge
{
    private const KEY = 'auth.2fa.pending';

    public function __construct(private readonly TwoFactor $totp) {}

    /** Park a password-verified identity awaiting its second factor. */
    public function start(Authenticatable $user, string $guard, bool $remember): void
    {
        session()->put(self::KEY, [
            'id' => $user->getAuthIdentifier(),
            'guard' => $guard,
            'remember' => $remember,
            // Bound so an abandoned handshake cannot be resumed hours later.
            'expires_at' => now()->addMinutes(5)->timestamp,
        ]);
    }

    /** The identity mid-handshake, or null if there is none / it has expired. */
    public function pendingUser(): ?Authenticatable
    {
        $pending = session(self::KEY);

        if (! $pending || now()->timestamp > $pending['expires_at']) {
            $this->abandon();

            return null;
        }

        $model = $pending['guard'] === 'superadmin' ? SuperAdmin::class : User::class;

        // Re-read rather than trusting the session: the account may have been
        // deactivated or deleted in the seconds since the password was checked.
        $user = $model::find($pending['id']);

        // `trashed()` exists only on soft-deletable models, staff are, the
        // super admin is not, so the check must be conditional.
        $removed = $user && method_exists($user, 'trashed') && $user->trashed();

        return ($user && $user->is_active && ! $removed) ? $user : null;
    }

    public function pendingGuard(): ?string
    {
        return session(self::KEY.'.guard');
    }

    public function hasPending(): bool
    {
        return $this->pendingUser() !== null;
    }

    /**
     * Verify a six-digit TOTP code, or an eight-character recovery code, and on
     * success promote the pending intent into a real session.
     *
     * @return bool false if the code was wrong; the handshake stays open.
     */
    public function attempt(string $code): bool
    {
        $user = $this->pendingUser();
        $pending = session(self::KEY);

        if (! $user || ! $pending) {
            return false;
        }

        $code = trim($code);

        // A recovery code is the documented escape hatch for a lost phone.
        // Recognised by shape: TOTP is six digits, recovery codes are not.
        $ok = preg_match('/^\d{6}$/', $code)
            ? $this->totp->verify($user, $code)
            : $user->consumeRecoveryCode($code);

        if (! $ok) {
            return false;
        }

        $this->abandon();
        Auth::guard($pending['guard'])->login($user, $pending['remember']);
        session()->regenerate();

        return true;
    }

    public function abandon(): void
    {
        session()->forget(self::KEY);
    }
}
