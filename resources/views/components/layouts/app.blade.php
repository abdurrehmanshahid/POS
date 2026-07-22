@php
    $user = auth()->user();
    $route = request()->route()?->getName();
    [$pageTitle, $pageSubtitle] = \App\Support\Nav::pageMeta($route, $user);
    $sections = \App\Support\Nav::sections();
    $impersonating = app(\App\Services\Impersonation::class);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $pageTitle }} · Big Binary Tech</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script>
        // Pre-paint theme to avoid a flash (spec §8.4: persist, default dark).
        // The Alpine store + toast helper live in app.js so they survive wire:navigate.
        (function () { try { document.documentElement.setAttribute('data-theme', localStorage.getItem('bbt-theme') || 'dark'); } catch (e) {} })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body @class(['is-impersonating' => $impersonating->isImpersonating()])>

{{-- Impersonation banner. Permanently visible, never dismissible: the one thing
     worse than not being able to see someone's screen is forgetting that what
     you are looking at is not your own. --}}
@if ($impersonating->isImpersonating())
    <div class="impersonation-bar" style="display:flex;align-items:center;gap:12px;padding:0 18px;background:var(--due);color:#1b1300;font-size:13px;font-weight:700">
        <x-icon name="eye" :size="16" style="flex:none" />
        <span style="flex:1;min-width:0">
            Viewing as <strong>{{ $user->name }}</strong> ({{ $user->roleLabel() }}), signed in as {{ $impersonating->impersonatorName() }}. Actions are recorded against you.
        </span>
        <form method="POST" action="{{ route('superadmin.impersonate.stop') }}" style="flex:none">
            @csrf
            <button type="submit" style="height:30px;padding:0 13px;border:none;border-radius:8px;background:#1b1300;color:#fff;font-size:12px;font-weight:700;cursor:pointer">
                Stop viewing
            </button>
        </form>
    </div>
@endif

<div class="shell" x-data="{ collapsed: false, mobileOpen: false, avatar: false }">

    {{-- Mobile backdrop --}}
    <div x-show="mobileOpen" x-cloak @click="mobileOpen = false" class="drawer-backdrop" style="z-index:64"></div>

    {{-- Sidebar --}}
    <aside class="sidebar" :class="{ collapsed: collapsed, open: mobileOpen }">
        <div class="sidebar-logo">
            {{-- The ternary must yield a string in BOTH branches. `collapsed && '…'`
                 evaluates to boolean false when expanded, and Alpine treats a
                 falsy :style as "clear the attribute", which wiped the inline
                 height and let the logo render at its natural size, overflowing
                 the 70px header. --}}
            <img src="{{ asset('assets/bbt-logo-white.png') }}" alt="BBT"
                 style="height:26px;display:block;width:auto"
                 :style="collapsed ? 'height:20px;width:auto' : 'height:26px;width:auto'"
                 onerror="this.style.display='none';this.insertAdjacentHTML('afterend','<span style=&quot;color:#fff;font-weight:800;font-size:15px&quot;>Big Binary Tech</span>')">
        </div>
        <nav class="nav">
            @foreach ($sections as $section => $items)
                @php $visible = collect($items)->filter(fn ($i) => $user->can($i['perm'])); @endphp
                @if ($visible->isNotEmpty())
                    <div class="nav-section">{{ $section }}</div>
                    @foreach ($visible as $item)
                        <a href="{{ route($item['route']) }}" wire:navigate
                           class="nav-link {{ $route === $item['route'] ? 'active' : '' }}">
                            <x-icon :name="$item['icon']" />
                            <span class="nav-label">{{ $item['label'] }}</span>
                        </a>
                    @endforeach
                @endif
            @endforeach
        </nav>

        {{-- Account menu --}}
        <div class="acct">
            <div x-show="avatar" x-cloak @click.outside="avatar = false" class="menu"
                 style="left:12px;right:auto;min-width:220px;max-width:calc(100vw - 24px);bottom:calc(100% - 4px)">
                <div style="padding:13px 15px;border-bottom:1px solid var(--surface3)">
                    <div style="font-size:13.5px;font-weight:700;color:var(--ink)">{{ $user->name }}</div>
                    <div style="font-size:11.5px;color:var(--muted)">{{ $user->email }}</div>
                    <x-ui.pill :tone="$user->role?->tone === 'orange' ? 'orange' : 'navy'" style="margin-top:7px">{{ $user->roleLabel() }}</x-ui.pill>
                </div>
                <button @click="$store.theme.toggle()" class="menu-item"
                        style="display:flex;align-items:center;gap:10px;width:100%;padding:11px 15px;border:none;background:transparent;color:var(--ink2);font-size:13px;font-weight:600;cursor:pointer;text-align:left">
                    <span style="width:18px;text-align:center" x-text="$store.theme.v === 'dark' ? '☀' : '☾'"></span>
                    <span x-text="$store.theme.v === 'dark' ? 'Light mode' : 'Dark mode'"></span>
                </button>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit"
                            style="display:flex;align-items:center;gap:10px;width:100%;padding:11px 15px;border:none;border-top:1px solid var(--surface3);background:transparent;color:var(--over);font-size:13px;font-weight:700;cursor:pointer;text-align:left">
                        <x-icon name="logout" :size="16" /> Sign out
                    </button>
                </form>
            </div>
            <button @click="avatar = !avatar" class="acct-btn">
                <x-ui.avatar :name="$user->name" variant="orange" :size="36" />
                <div class="nav-label" style="min-width:0;flex:1">
                    <div style="font-size:13.5px;font-weight:700;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ $user->name }}</div>
                    <div style="font-size:11.5px;color:#9599c4">{{ $user->roleLabel() }}</div>
                </div>
                <x-icon name="chevron-up" :size="16" class="nav-label" style="color:#9599c4" />
            </button>
        </div>
    </aside>

    {{-- Content --}}
    <div class="content">
        <header class="topbar">
            <button class="btn-icon" title="Toggle menu"
                    @click="window.innerWidth <= 820 ? (mobileOpen = !mobileOpen) : (collapsed = !collapsed)">
                <x-icon name="menu" :size="18" />
            </button>
            <div style="flex:1">
                <h1 style="font-size:19px;font-weight:800;color:var(--ink);margin:0;letter-spacing:-.01em">{{ $pageTitle }}</h1>
                <div style="font-size:12.5px;color:var(--muted);margin-top:1px">{{ $pageSubtitle }}</div>
            </div>
            <button class="btn-icon" title="Toggle theme" @click="$store.theme.toggle()" style="font-size:16px">
                <span x-text="$store.theme.v === 'dark' ? '☀' : '☾'"></span>
            </button>
            <livewire:notifications-bell />
        </header>

        <main class="main">
            {{ $slot }}
        </main>
    </div>

    {{-- Toasts --}}
    <div class="toast-host" x-data="bbtToasts()" @bbt-toast.window="push($event.detail)">
        <template x-for="t in items" :key="t.id">
            <div class="toast" :class="'toast-' + t.tone">
                <div class="ico" x-text="t.icon"></div>
                <div style="flex:1">
                    <div style="font-size:13px;font-weight:700;color:var(--ink)" x-text="t.title"></div>
                    <template x-if="t.msg"><div style="font-size:12px;color:var(--muted);margin-top:1px" x-text="t.msg"></div></template>
                </div>
            </div>
        </template>
    </div>

    @if (session('toast'))
        <script>
            document.addEventListener('livewire:init', () => window.dispatchEvent(new CustomEvent('bbt-toast', { detail: @json(session('toast')) })));
        </script>
    @endif
</div>
@livewireScripts
</body>
</html>
