@php
    $su = auth()->guard('superadmin')->user();
    $route = request()->route()?->getName();
    [$pageTitle, $pageSubtitle] = \App\Support\SuperNav::pageMeta($route);
    $sections = \App\Support\SuperNav::sections();
@endphp
<!DOCTYPE html>
<html lang="en" data-theme="{{ \App\Support\Theme::current() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $pageTitle }} · Super Admin</title>
    @include('partials.favicon')
    {{-- Inter is self-hosted and declared in app.css. It used to be three tags
         here: two preconnects and a render-blocking stylesheet from
         fonts.googleapis.com, which measured 1.6s from Lahore and held up the
         first paint of every cold load. Nothing external is in the critical
         path now. --}}
    <script>
        // The attribute is already rendered on <html> above, from the cookie, so
        // this is a fallback for one case only: cookies disabled, where the
        // server cannot know the preference and localStorage is all there is.
        // It must not run when the server already spoke, or a stale
        // localStorage value would override a fresh cookie.
        (function () {
            try {
                var el = document.documentElement;
                if (!el.getAttribute('data-theme')) {
                    el.setAttribute('data-theme', localStorage.getItem('bbt-theme') || 'dark');
                }
            } catch (e) {}
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
{{-- `super` swaps the sidebar accent to iris. The panel deliberately looks like
     the staff portal but never identical: you should never be in any doubt about
     which of the two you are about to click "delete" in. --}}
<body class="super">
<div class="shell" x-data="{ collapsed: false, mobileOpen: false, avatar: false }">

    <div x-show="mobileOpen" x-cloak @click="mobileOpen = false" class="drawer-backdrop" style="z-index:64"></div>

    <aside class="sidebar" :class="{ collapsed: collapsed, open: mobileOpen }">
        {{-- Clickable for the same reason the staff logo is: it is the gesture
             people make without thinking. The destination is the SUPER
             dashboard, never the staff one — the two portals share an origin and
             deliberately look alike, and a header that quietly moved an owner
             between them would undo the whole point of the colour shift below.

             No `.hover`: this anchor is the whole 70px header row, so a pointer
             travelling to the nav below crosses it every time, and a prefetch
             of the page you are already on is a full render thrown away. --}}
        <a href="{{ route('superadmin.dashboard') }}" wire:navigate
           class="sidebar-logo" style="gap:9px"
           title="Go to the super admin dashboard">
            <x-icon name="shield" :size="20" style="color:var(--orange2);flex:none" />
            <span class="nav-label" style="color:#fff;font-weight:800;font-size:var(--fs-base);letter-spacing:-.01em">Super Admin</span>
        </a>

        <nav class="nav">
            @foreach ($sections as $section => $items)
                <div class="nav-section">{{ $section }}</div>
                @foreach ($items as $item)
                    {{-- Prefetch on hover, as in the staff layout. --}}
                    <a href="{{ route($item['route']) }}" wire:navigate.hover
                       class="nav-link {{ $route === $item['route'] ? 'active' : '' }}">
                        <x-icon :name="$item['icon']" />
                        <span class="nav-label">{{ $item['label'] }}</span>
                    </a>
                @endforeach
            @endforeach

            <div class="nav-section">Portal</div>
            <a href="{{ route('login') }}" class="nav-link">
                <x-icon name="arrow-right" />
                <span class="nav-label">Staff portal</span>
            </a>
        </nav>

        <div class="acct">
            <div x-show="avatar" x-cloak @click.outside="avatar = false" class="menu"
                 style="left:12px;right:auto;min-width:220px;max-width:calc(100vw - 24px);bottom:calc(100% - 4px)">
                <div style="padding:13px 15px;border-bottom:1px solid var(--surface3)">
                    <div style="font-size:var(--fs-sm);font-weight:700;color:var(--ink)">{{ $su->name }}</div>
                    <div style="font-size:var(--fs-2xs);color:var(--muted)">{{ $su->email }}</div>
                    <x-ui.pill tone="iris" style="margin-top:7px">Super admin</x-ui.pill>
                </div>
                {{-- In the account menu rather than the sidebar: it is a thing
                     you do to yourself, not a section of the console. --}}
                <a href="{{ route('superadmin.password.change') }}" wire:navigate
                   style="display:flex;align-items:center;gap:10px;width:100%;padding:11px 15px;color:var(--ink2);font-size:var(--fs-sm);font-weight:600;text-decoration:none">
                    <x-icon name="key" :size="16" /> Change password
                </a>
                <button @click="$store.theme.toggle()"
                        style="display:flex;align-items:center;gap:10px;width:100%;padding:11px 15px;border:none;background:transparent;color:var(--ink2);font-size:var(--fs-sm);font-weight:600;cursor:pointer;text-align:left">
                    <span style="width:18px;text-align:center" x-text="$store.theme.v === 'dark' ? '☀' : '☾'"></span>
                    <span x-text="$store.theme.v === 'dark' ? 'Light mode' : 'Dark mode'"></span>
                </button>
                <form method="POST" action="{{ route('superadmin.logout') }}">
                    @csrf
                    <button type="submit"
                            style="display:flex;align-items:center;gap:10px;width:100%;padding:11px 15px;border:none;border-top:1px solid var(--surface3);background:transparent;color:var(--over);font-size:var(--fs-sm);font-weight:700;cursor:pointer;text-align:left">
                        <x-icon name="logout" :size="16" /> Sign out
                    </button>
                </form>
            </div>
            <button @click="avatar = !avatar" class="acct-btn">
                <x-ui.avatar :name="$su->name" variant="navy" :size="36" />
                <div class="nav-label" style="min-width:0;flex:1">
                    <div style="font-size:var(--fs-sm);font-weight:700;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ $su->name }}</div>
                    <div style="font-size:var(--fs-2xs);color:#9599c4">Super admin</div>
                </div>
                <x-icon name="chevron-up" :size="16" class="nav-label" style="color:#9599c4" />
            </button>
        </div>
    </aside>

    <div class="content">
        <header class="topbar">
            <button class="btn-icon" title="Toggle menu"
                    @click="window.innerWidth <= 820 ? (mobileOpen = !mobileOpen) : (collapsed = !collapsed)">
                <x-icon name="menu" :size="18" />
            </button>
            <div style="flex:1;min-width:0">
                <h1 style="font-size:var(--fs-lg);font-weight:800;color:var(--ink);margin:0;letter-spacing:-.01em">{{ $pageTitle }}</h1>
                <div style="font-size:var(--fs-xs);color:var(--muted);margin-top:1px">{{ $pageSubtitle }}</div>
            </div>
            <span class="pill pill-iris" style="flex:none">
                <x-icon name="shield" :size="12" /> Unrestricted access
            </span>
            <button class="btn-icon" title="Toggle theme" @click="$store.theme.toggle()" style="font-size:var(--fs-md)">
                <span x-text="$store.theme.v === 'dark' ? '☀' : '☾'"></span>
            </button>
        </header>

        <main class="main">
            {{ $slot }}
        </main>
    </div>

    <div class="toast-host" x-data="bbtToasts()" @bbt-toast.window="push($event.detail)">
        <template x-for="t in items" :key="t.id">
            <div class="toast" :class="'toast-' + t.tone">
                <div class="ico" x-text="t.icon"></div>
                <div style="flex:1">
                    <div style="font-size:var(--fs-sm);font-weight:700;color:var(--ink)" x-text="t.title"></div>
                    <template x-if="t.msg"><div style="font-size:var(--fs-xs);color:var(--muted);margin-top:1px" x-text="t.msg"></div></template>
                    <template x-if="t.note"><div class="toast-note" x-text="t.note"></div></template>
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
