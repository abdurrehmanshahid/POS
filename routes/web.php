<?php

use App\Http\Controllers\ChallanController;
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

    // The second factor sits BETWEEN password and session, so it must be
    // reachable while still a guest, there is no session yet by design.
    Volt::route('two-factor', 'pages.auth.two-factor-challenge')->name('two-factor.challenge');

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

    // TOTP enrolment. Outside the RequirePasswordReset/2fa group because it is
    // the one place an un-enrolled user is allowed to reach.
    Volt::route('two-factor/setup', 'pages.auth.two-factor-setup')->name('two-factor.setup');

    // `active` runs on every authenticated request so a deactivated account
    // loses its live session immediately, not at next sign-in (spec §15).
    Route::middleware(['active', '2fa', RequirePasswordReset::class])->group(function () {
        // Each screen is permission-gated on the server, not just hidden in nav (spec §15).
        Volt::route('dashboard', 'pages.dashboard')->middleware('permission:dashboard.view')->name('dashboard');
        Volt::route('registrations', 'pages.registrations')->middleware('permission:registrations.view')->name('registrations');
        Volt::route('challans', 'pages.challans')->middleware('permission:challans.view')->name('challans');
        Volt::route('courses', 'pages.courses')->middleware('permission:courses.view')->name('courses');
        Volt::route('cohorts', 'pages.cohorts')->middleware('permission:cohorts.manage')->name('cohorts');
        Volt::route('attendance', 'pages.attendance')->middleware('permission:attendance.manage')->name('attendance');
        Volt::route('students', 'pages.students')->middleware('permission:students.view')->name('students');
        Volt::route('staff', 'pages.staff')->middleware('permission:staff.view')->name('staff');
        Volt::route('reports', 'pages.reports')->middleware('permission:reports.view')->name('reports');
        Volt::route('datamodel', 'pages.datamodel')->middleware('permission:datamodel.view')->name('datamodel');
        Volt::route('settings', 'pages.settings')->middleware('permission:settings.manage')->name('settings');

        Route::get('challans/{challan}/pdf', [ChallanController::class, 'download'])
            ->middleware('permission:challans.view')->name('challans.pdf');
        Route::get('students/export', [StudentExportController::class, 'export'])
            ->middleware('permission:students.view')->name('students.export');
        Route::get('reports/export', ReportExportController::class)
            ->middleware('permission:reports.view')->name('reports.export');
    });
});
