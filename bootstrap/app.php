<?php

use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureTwoFactorEnrolled;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // The super admin panel is a separate guard on its own prefix.
            Route::middleware('web')->group(base_path('routes/superadmin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Believe the proxy about how the request arrived.
         *
         * Every URL Laravel generates — `asset()`, `route()`, `@vite`, the
         * password-reset link in an email — is built from `$request->root()`,
         * and that reads the scheme and host off the connection PHP actually
         * received. Behind anything that terminates TLS and forwards plain HTTP
         * (Vercel, a load balancer, nginx on the institute's own box) the
         * connection PHP sees is `http://`, so every one of those URLs comes out
         * `http://` on a page the browser loaded over `https://`. The browser
         * then blocks them as mixed content: the stylesheet, the compiled JS,
         * the institute's logo, and the fee-voucher viewer, all at once, with
         * the only evidence in the browser console.
         *
         * The forwarded headers say what really happened. `at: '*'` because a
         * platform proxy's address is not knowable in advance and changes
         * without notice — the standard configuration for a cloud deployment.
         * It means trusting `X-Forwarded-*` from whatever reaches the app, so
         * the app must not also be reachable directly on a public address; on
         * Vercel and behind a properly configured nginx it is not.
         */
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            // Thin permission layer (spec §3). Usage: middleware('permission:challans.pay').
            'permission' => EnsurePermission::class,
            // Kill the session of anyone deactivated or deleted mid-session.
            // Usage: middleware('active') or middleware('active:superadmin').
            'active' => EnsureActiveUser::class,
            // Pin un-enrolled users to the TOTP setup screen when their role
            // requires a second factor. Usage: middleware('2fa').
            '2fa' => EnsureTwoFactorEnrolled::class,
        ]);

        /*
         * Two portals share one origin, so the framework's single global
         * redirect targets send people to the wrong one.
         *
         * Without these, an unauthenticated visit to /superadmin/dashboard
         * lands on the STAFF login (where the owner's credentials will never
         * work), and an already-signed-in super admin visiting /superadmin is
         * bounced to the STAFF dashboard, which they have no session for,
         * so they get bounced again, back to the staff login. Confusing at
         * best, and it reads like the panel does not exist.
         */
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('superadmin', 'superadmin/*')
                ? route('superadmin.login')
                : route('login')
        );

        $middleware->redirectUsersTo(
            fn (Request $request) => $request->is('superadmin', 'superadmin/*')
                ? route('superadmin.dashboard')
                : route('dashboard')
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
