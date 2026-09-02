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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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
