{{-- Access denied.

     Enforcement has always been correct (EnsurePermission aborts server-side,
     never relying on hidden nav), but the result was the framework's bare
     "403 Forbidden" page. Someone following a stale bookmark or a link from a
     colleague with a richer role deserves to be told what happened and given a
     way back, rather than dropped out of the product entirely. --}}
@php
    // Resolved per guard, not via auth()->user(). The two portals are separate
    // guards, and on a /superadmin route the signed-in identity is a SuperAdmin,
    // which Nav::firstScreen() cannot take: it answers "which staff screen may
    // this role see", a question the owner account is not part of.
    $user = auth('web')->user();
    $super = auth('superadmin')->user();

    $homeRoute = match (true) {
        $super !== null => 'superadmin.dashboard',
        $user !== null => \App\Support\Nav::firstScreen($user),
        default => null,
    };
    $homeLabel = match (true) {
        $super !== null => 'the console',
        $user !== null => \App\Support\Nav::pageMeta($homeRoute, $user)[0],
        default => null,
    };
@endphp

<x-layouts.guest>
    <div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px">
        <div class="card" style="max-width:460px;width:100%;padding:34px 32px;text-align:center">
            <div style="display:flex;align-items:center;justify-content:center;width:52px;height:52px;border-radius:14px;background:var(--over-bg);margin:0 auto 18px">
                <x-icon name="lock" :size="24" style="color:var(--over)" />
            </div>

            <h1 style="font-size:var(--fs-xl);font-weight:800;color:var(--ink);margin:0 0 8px">You do not have access to this screen</h1>

            <p style="font-size:var(--fs-sm);color:var(--muted);line-height:1.6;margin:0 0 22px">
                Your role does not grant the permission this page needs. If you
                believe it should, ask an administrator to review your role on
                the Staff &amp; Roles screen.
            </p>

            @if ($homeRoute)
                <a href="{{ route($homeRoute) }}" class="btn btn-primary" style="width:100%;height:44px">
                    <x-icon name="arrow-left" :size="15" /> Back to {{ $homeLabel }}
                </a>
            @else
                <a href="{{ route('login') }}" class="btn btn-primary" style="width:100%;height:44px">Sign in</a>
            @endif

            @if ($user)
                <div style="margin-top:16px;font-size:var(--fs-xs);color:var(--faint)">
                    Signed in as {{ $user->name }} · {{ $user->roleLabel() }}
                </div>
            @endif
        </div>
    </div>
</x-layouts.guest>
