{{-- Not found. Same treatment as 403: a way back rather than a dead end. --}}
@php
    // Per guard, for the same reason as errors/403.blade.php.
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
            <div style="display:flex;align-items:center;justify-content:center;width:52px;height:52px;border-radius:14px;background:var(--surface3);margin:0 auto 18px">
                <x-icon name="search" :size="24" style="color:var(--muted)" />
            </div>

            <h1 style="font-size:var(--fs-xl);font-weight:800;color:var(--ink);margin:0 0 8px">That page does not exist</h1>

            <p style="font-size:var(--fs-sm);color:var(--muted);line-height:1.6;margin:0 0 22px">
                The link may be out of date, or the record it pointed at may have
                been removed.
            </p>

            @if ($homeRoute)
                <a href="{{ route($homeRoute) }}" class="btn btn-primary" style="width:100%;height:44px">
                    <x-icon name="arrow-left" :size="15" /> Back to {{ $homeLabel }}
                </a>
            @else
                <a href="{{ route('login') }}" class="btn btn-primary" style="width:100%;height:44px">Sign in</a>
            @endif
        </div>
    </div>
</x-layouts.guest>
