{{--
    The page the counter sees for the ~20 seconds of a deploy.

    Standalone HTML on purpose — NOT <x-layouts.guest>, and no @vite.

    `artisan down --render` renders this ONCE, at the moment maintenance starts,
    and writes the result to storage/framework/maintenance.php. That file is then
    served by the framework's very first middleware, before the application
    boots: no database, no session, no authenticated user, and no guarantee that
    the compiled assets referenced by @vite still exist — deploy.sh rebuilds them
    a few steps later, which would leave this page pointing at a manifest entry
    that has just been replaced.

    So everything it needs is inlined. The one external reference is the
    favicon, which is a static path that does not change across releases.

    Without this file, `artisan down` looks for a view named `503`, logs
    "View [503] not found", and falls back to the framework's unbranded default.
    That is what the institute has been seeing on every release.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- The counter will be staring at this. Refresh for them rather than
         making somebody decide when to try again; a deploy is seconds. --}}
    <meta http-equiv="refresh" content="15">
    <title>Updating · Big Binary Tech</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh;
            display: flex; align-items: center; justify-content: center; padding: 24px;
            font: 15px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: #f4f4f8; color: #16162a;
        }
        .card {
            max-width: 430px; width: 100%; padding: 36px 32px; text-align: center;
            background: #fff; border-radius: 18px; box-shadow: 0 10px 40px rgba(20,20,50,.10);
        }
        img { width: 54px; height: 54px; margin: 0 auto 18px; display: block; }
        h1 { font-size: 20px; font-weight: 800; margin: 0 0 10px; }
        p { margin: 0 0 8px; color: #5b5b70; }
        .note { margin-top: 20px; font-size: 13px; color: #7a7a8c; }
        .dot {
            display: inline-block; width: 7px; height: 7px; border-radius: 50%;
            background: #f59120; margin-right: 7px; vertical-align: middle;
            animation: pulse 1.4s ease-in-out infinite;
        }
        @keyframes pulse { 0%,100% { opacity: 1 } 50% { opacity: .25 } }
        @media (prefers-reduced-motion: reduce) { .dot { animation: none } }
        @media (prefers-color-scheme: dark) {
            body { background: #131322; color: #e9e9f2; }
            .card { background: #1c1c30; box-shadow: 0 10px 40px rgba(0,0,0,.45); }
            p { color: #a6a6bc; } .note { color: #8686a0; }
        }
    </style>
</head>
<body>
    <div class="card">
        <img src="/favicon-512.png" alt="Big Binary Tech">
        <h1><span class="dot"></span>Updating the system</h1>
        {{-- Says the two things somebody at a counter actually needs: how long,
             and whether the payment they just took survived. --}}
        <p>This takes about a minute. The page refreshes by itself.</p>
        <p>Everything already saved is safe. Anything you were part-way through
           was <strong>not</strong> saved — you will need to enter it again.</p>
        <p class="note">Big Binary Tech Institute · fee &amp; registration system</p>
    </div>
</body>
</html>
