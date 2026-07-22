<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * New staff reset their password on first sign-in (spec §2.1 / §9.7). Until they
 * do, every app screen redirects to the set-password page.
 */
class RequirePasswordReset
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_reset_password) {
            return redirect()->route('password.set');
        }

        return $next($request);
    }
}
