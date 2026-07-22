<?php

namespace App\Http\Middleware;

use App\Services\TwoFactorChallenge;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Force enrolment on anyone whose role obliges them to hold a second factor.
 *
 * Why enrolment is forced *after* sign-in rather than blocking it: a user who
 * has never enrolled has no code to give, so refusing the session outright
 * would lock them out permanently with no self-service way back. Instead they
 * get a session that can reach exactly one place, the enrolment screen, and
 * nothing else until the factor is confirmed.
 *
 * The pairing that makes this safe is {@see TwoFactorChallenge}:
 * once enrolled, the second factor is demanded BEFORE a session exists at all.
 * So the permissive path here applies only to the one-time setup, never again.
 */
class EnsureTwoFactorEnrolled
{
    /** Routes reachable while still un-enrolled, setup and the way out. */
    private const ALLOWED = [
        'two-factor.setup',
        'two-factor.confirm',
        'logout',
        'superadmin.two-factor.setup',
        'superadmin.logout',
    ];

    public function handle(Request $request, Closure $next, string $guard = 'web'): Response
    {
        $user = Auth::guard($guard)->user();

        if ($user?->mustEnrolTwoFactor() && ! $request->routeIs(self::ALLOWED)) {
            return redirect()->route(
                $guard === 'superadmin' ? 'superadmin.two-factor.setup' : 'two-factor.setup'
            );
        }

        return $next($request);
    }
}
