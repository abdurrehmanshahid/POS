<?php

use App\Http\Controllers\SuperAdmin\BackupController;
use App\Services\Impersonation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

/*
|--------------------------------------------------------------------------
| Super admin panel, /superadmin
|--------------------------------------------------------------------------
|
| A completely separate guard from the staff portal (see the `super_admins`
| migration for why). Every authenticated route carries three guards, and the
| order matters:
|
|   auth:superadmin  → a session on THIS guard, not the staff one. A signed-in
|                      Administrator has no more access here than a stranger.
|   active:superadmin → deactivation ends a live session on the next request.
|   2fa:superadmin    → pins an un-enrolled owner to the setup screen.
|
| Destructive actions layer a fourth check at the point of use (a fresh TOTP
| code plus type-to-confirm), because holding a session is not the same as
| being at the keyboard right now.
|
*/

Route::prefix('superadmin')->name('superadmin.')->group(function () {

    // ---- Guest ------------------------------------------------------------
    // `guest:superadmin` rather than plain `guest`: a signed-in officer must
    // still be able to reach this login page.
    Route::middleware('guest:superadmin')->group(function () {
        Volt::route('/', 'superadmin.login')->name('login');
    });

    // ---- Authenticated -----------------------------------------------------
    Route::middleware(['auth:superadmin', 'active:superadmin'])->group(function () {

        Route::post('logout', function () {
            // Stop any impersonation first, so the `web` session cannot outlive
            // the `superadmin` one that authorised it.
            app(Impersonation::class)->stop();

            Auth::guard('superadmin')->logout();
            request()->session()->invalidate();
            request()->session()->regenerateToken();

            return redirect()->route('superadmin.login');
        })->name('logout');

        // Enrolment sits outside the 2fa gate, it is the one screen an
        // un-enrolled owner is allowed to reach.
        Volt::route('two-factor/setup', 'superadmin.two-factor-setup')->name('two-factor.setup');

        Route::middleware('2fa:superadmin')->group(function () {
            Volt::route('dashboard', 'superadmin.dashboard')->name('dashboard');
            Volt::route('performance', 'superadmin.performance')->name('performance');
            Volt::route('staff', 'superadmin.staff')->name('staff');
            Volt::route('students', 'superadmin.students')->name('students');
            Volt::route('activity', 'superadmin.activity')->name('activity');
            Volt::route('backups', 'superadmin.backups')->name('backups');

            Route::get('backups/sql', [BackupController::class, 'sql'])->name('backups.sql');
            Route::get('backups/csv/{table}', [BackupController::class, 'csv'])->name('backups.csv');
        });
    });
});

/*
 * Ending impersonation lives OUTSIDE the superadmin auth group on purpose.
 *
 * While impersonating, the request authenticates on the `web` guard as the
 * target user; the `superadmin` identity is held alongside it in the session.
 * Requiring auth:superadmin here would work, but routing it separately keeps
 * the exit reachable from the staff-portal banner without depending on which
 * guard the middleware stack resolves first, and the handler itself is inert
 * unless a real impersonation session exists.
 */
Route::post('superadmin/impersonate/stop', function () {
    app(Impersonation::class)->stop();

    return redirect()->route('superadmin.staff');
})->middleware('auth:web')->name('superadmin.impersonate.stop');
