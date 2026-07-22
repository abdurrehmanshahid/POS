<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route guard for the thin permission layer (spec §15). `permission:key`, or
 * `permission:key1,key2` for any-of. Every UI gate is duplicated here so access
 * is denied at the server, not just hidden in the view.
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string ...$keys): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            abort(403);
        }

        foreach ($keys as $key) {
            if ($user->can($key)) {
                return $next($request);
            }
        }

        abort(403);
    }
}
