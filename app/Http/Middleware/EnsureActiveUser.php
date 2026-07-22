<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Terminate the session of anyone who has been deactivated or removed.
 *
 * The hole this closes: `is_active` was previously only consulted by
 * EnsurePermission, and only on permission-gated routes. Revoking an officer's
 * access therefore did nothing to a session they already held, they kept
 * working until they chose to sign out, and a "remember me" cookie let them
 * come back days later. For a staff member who has just been dismissed, that is
 * precisely the window that matters.
 *
 * Running on every authenticated request means deactivation takes effect on the
 * offender's very next click, on both guards. Soft-deleted accounts are caught
 * too: `SoftDeletes` hides them from the user provider on the next resolve, but
 * a live session already holds the instance, so we check explicitly.
 */
class EnsureActiveUser
{
    public function __construct(private readonly string $guard = 'web') {}

    public function handle(Request $request, Closure $next, string $guard = 'web'): Response
    {
        $user = Auth::guard($guard)->user();

        // `trashed()` only exists on models using SoftDeletes. Staff are
        // soft-deletable; the super admin is not (there is nowhere to "remove"
        // the owner account to), so the check has to be conditional rather than
        // assumed, otherwise every panel request fatals.
        $removed = method_exists($user, 'trashed') && $user->trashed();

        if ($user && (! $user->is_active || $removed)) {
            Auth::guard($guard)->logout();

            // Invalidate rather than merely flushing, so the session ID itself
            // is retired and any parallel tab holding it dies with this one.
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route($guard === 'superadmin' ? 'superadmin.login' : 'login')
                ->withErrors(['user' => 'Your account is no longer active.']);
        }

        return $next($request);
    }
}
