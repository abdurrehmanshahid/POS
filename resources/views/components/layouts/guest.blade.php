<!DOCTYPE html>
<html lang="en" data-theme="{{ \App\Support\Theme::current() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Every guest screen used to render "Sign in · Big Binary Tech", because
         the layout fell back to that and no component ever passed a title. So
         the two-factor setup page announced itself as a sign-in form, and the
         super admin console carried the staff portal's name. Derived from the
         route here rather than passed in, so a new guest screen cannot forget. --}}
    @php
        $guestRoute = request()->route()?->getName();
        $guestTitle = $title ?? match ($guestRoute) {
            'login' => 'Sign in',
            'password.request' => 'Forgot password',
            'password.reset' => 'Reset password',
            'password.set' => 'Set your password',
            'two-factor.challenge' => 'Two-factor code',
            'two-factor.setup', 'superadmin.two-factor.setup' => 'Set up two-factor',
            'superadmin.login' => 'Sign in',
            default => 'Sign in',
        };
        $guestSuffix = str_starts_with((string) $guestRoute, 'superadmin.')
            ? 'Super Admin'
            : 'Big Binary Tech';
    @endphp
    <title>{{ $guestTitle }} · {{ $guestSuffix }}</title>
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
<body>
    {{ $slot }}
    @livewireScripts
</body>
</html>
