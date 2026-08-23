<?php

use App\Http\Controllers\ReadinessController;
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
            /*
             * Readiness, beside liveness.
             *
             * `health: '/up'` above registers Laravel's own probe and, in doing
             * so, calls PreventRequestsDuringMaintenance::except('/up') — so
             * /up answers 200 throughout a deploy. That is right for liveness
             * (is the process alive) and no use at all for the question an
             * uptime monitor actually needs answered (can this thing serve a
             * fee payment right now).
             *
             * /ready is registered here rather than in routes/web.php on
             * purpose: the `web` group starts a session, and SESSION_DRIVER is
             * `database`, so a monitor polling every five minutes would write
             * 288 junk rows a day into the database it is checking. Outside the
             * group it still picks up the GLOBAL middleware stack, which is
             * where PreventRequestsDuringMaintenance lives — so it correctly
             * returns 503 for the duration of every deploy, with no exception
             * registered for it.
             *
             * It returns a bare 200 or a bare 503 and nothing else; see
             * ReadinessController for why an empty body is the whole point.
             */
            Route::get('/ready', ReadinessController::class)->name('ready');

            // The super admin panel is a separate guard on its own prefix.
            Route::middleware('web')->group(base_path('routes/superadmin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Proxy trust and URL pinning are configured in AppServiceProvider,
        // where `config()` is bound. It is NOT bound here: reading it from this
        // closure takes the whole console down with "Target class [config] does
        // not exist", including the `config:clear` needed to recover.

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
