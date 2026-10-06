<?php

use App\Http\Controllers\ChallanController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\ReportExportController;
use App\Http\Controllers\StudentExportController;
use App\Http\Middleware\RequirePasswordReset;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', fn () => redirect()->route(Auth::check() ? 'dashboard' : 'login'));

// ---- Guest auth (spec §5) --------------------------------------------------
Route::middleware('guest')->group(function () {
    Volt::route('login', 'pages.auth.login')->name('login');
    Volt::route('forgot-password', 'pages.auth.forgot')->name('password.request');

    // Landing page for the emailed reset link. The component 404s unless
    // INSTITUTE_SELF_SERVICE_RESET is on; the route always exists so the
    // password broker can generate URLs for it.
    Volt::route('reset-password/{token}', 'pages.auth.reset-password')->name('password.reset');
});

// ---- Authenticated ---------------------------------------------------------
Route::middleware('auth')->group(function () {
    Route::post('logout', function () {
        Auth::guard('web')->logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');

    // First-login password reset (spec §2.1 must_reset_password).
    Volt::route('set-password', 'pages.auth.set-password')->name('password.set');

    // Rotating your own password, voluntarily. Deliberately in this group and
    // not behind `RequirePasswordReset`: somebody who has just been issued a
    // temporary password is pushed to `set-password` above, and both doors
    // leading to the same forced screen would be a redirect loop. No permission
    // is required — every account may change its own credential, and gating
    // that behind a role would leave the least-privileged staff the least able
    // to secure themselves.
    Volt::route('change-password', 'pages.auth.change-password')->name('password.change');

    // `active` runs on every authenticated request so a deactivated account
    // loses its live session immediately, not at next sign-in (spec §15).
    Route::middleware(['active', RequirePasswordReset::class])->group(function () {
        // Each screen is permission-gated on the server, not just hidden in nav (spec §15).
        Volt::route('dashboard', 'pages.dashboard')->middleware('permission:dashboard.view')->name('dashboard');
        Volt::route('registrations', 'pages.registrations')->middleware('permission:registrations.view')->name('registrations');
        Volt::route('challans', 'pages.challans')->middleware('permission:challans.view')->name('challans');
        Volt::route('courses', 'pages.courses')->middleware('permission:courses.view')->name('courses');
        Volt::route('cohorts', 'pages.cohorts')->middleware('permission:cohorts.manage')->name('cohorts');
        Volt::route('attendance', 'pages.attendance')->middleware('permission:attendance.manage')->name('attendance');
        Volt::route('students', 'pages.students')->middleware('permission:students.view')->name('students');
        // The same screen narrowed to walk-ins, under the same permission and
        // the same visibility scope as the Students tab it is split from.
        Volt::route('walk-ins', 'pages.students')->middleware('permission:students.view')->name('walkins');
        Volt::route('staff', 'pages.staff')->middleware('permission:staff.view')->name('staff');
        Volt::route('reports', 'pages.reports')->middleware('permission:reports.view')->name('reports');
        Volt::route('datamodel', 'pages.datamodel')->middleware('permission:datamodel.view')->name('datamodel');
        Volt::route('settings', 'pages.settings')->middleware('permission:settings.manage')->name('settings');

        // Three routes per document, one permission, one renderer. `view` is
        // the PDF.js viewer; `stream` is the bytes it fetches and displays;
        // `pdf` is the same bytes saved, for sending on. Splitting display from
        // saving is what lets both be honest — the browser is never asked to
        // display something it may decline, and the file is never named by
        // anything but us. All three carry the same permission, because they
        // are three doors onto one document.
        // Stated ONCE, structurally, rather than chained onto six declarations.
        // The failure this guards against is a seventh document route added
        // without the guard, which is invisible in a list of near-identical
        // lines and total in effect — an unguarded stream route hands any
        // signed-in user every voucher in the institute.
        Route::middleware('permission:challans.view')->group(function () {
            Route::get('challans/{challan}/view', [ChallanController::class, 'view'])
                ->name('challans.view');
            Route::get('challans/{challan}/stream', [ChallanController::class, 'stream'])
                ->name('challans.stream');
            Route::get('challans/{challan}/pdf', [ChallanController::class, 'download'])
                ->name('challans.pdf');
            // Evidence that a collection happened, as opposed to the voucher
            // above, which is a demand for one. Keyed by the payment rather
            // than the challan: a challan settled in three instalments has
            // three receipts.
            Route::get('payments/{payment}/receipt/view', [ReceiptController::class, 'view'])
                ->name('payments.receipt.view');
            Route::get('payments/{payment}/receipt/stream', [ReceiptController::class, 'stream'])
                ->name('payments.receipt.stream');
            Route::get('payments/{payment}/receipt', [ReceiptController::class, 'download'])
                ->name('payments.receipt');
        });
        Route::get('students/export', [StudentExportController::class, 'export'])
            ->middleware('permission:students.view')->name('students.export');
        Route::get('reports/export', ReportExportController::class)
            ->middleware('permission:reports.view')->name('reports.export');
    });
});
