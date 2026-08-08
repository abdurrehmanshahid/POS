<?php

/*
|--------------------------------------------------------------------------
| Serverless entrypoint (Vercel / vercel-php)
|--------------------------------------------------------------------------
|
| Vercel serves every request through a PHP function whose filesystem is
| READ-ONLY apart from /tmp, and whose /tmp is per-instance and disappears
| when the instance is recycled. Laravel assumes a writable `storage/`, so
| everything it writes has to be relocated before the framework boots.
|
| What is relocated and why:
|
|   framework/views    Blade compiles templates on first render. At build time
|                      we cannot know the runtime storage path, so views are
|                      NOT pre-cached; they compile into /tmp on the first
|                      request an instance serves. That is the cold-start
|                      cost of this platform.
|   framework/cache    the file cache store. Unused here (CACHE_STORE is the
|                      database) but created so a misconfiguration degrades
|                      instead of fataling.
|   framework/sessions ditto for the file session driver. SESSION_DRIVER must
|                      be `database` in production: a file session written to
|                      /tmp is invisible to the next instance, so a signed-in
|                      officer would be logged out at random.
|   logs               only reached if LOG_CHANNEL is not `stderr`. On Vercel
|                      it should be, so logs land in the platform's log drain
|                      rather than a directory that evaporates.
|   fonts              dompdf writes a font cache on first render. Without
|                      this the fee voucher throws on a read-only filesystem.
|
| `bootstrap/cache` is deliberately NOT relocated. The config and route caches
| are built during deployment and only ever read at runtime, so they are fine
| on the read-only filesystem and save a real amount of cold-start time.
|
| ── config:cache, and why vercel.json sets these paths at BUILD time ──────
|
| `php artisan config:cache` freezes the resolved config array during the
| build, and Laravel then never calls env() again. Every value derived from
| storage_path() is therefore baked to the BUILD machine's path, and
| `useStoragePath()` below cannot move it, because by then it is a literal in
| bootstrap/cache/config.php rather than a call.
|
| That silently defeats this whole file: Blade tries to compile views into the
| read-only bundle and every page 500s, and dompdf writes its font cache to
| the same read-only path so the fee voucher throws. Verified by inspecting
| the generated bootstrap/cache/config.php.
|
| So vercel.json declares VIEW_COMPILED_PATH and DOMPDF_FONT_DIR as build
| environment variables, and the cached config is frozen with the RUNTIME
| paths already in it. The assignment below stays as the belt to that braces:
| it is what makes this work when config is not cached, such as a local run
| through this entrypoint.
|
*/

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$storage = '/tmp/storage';

foreach ([
    '/framework/views',
    '/framework/cache/data',
    '/framework/sessions',
    '/logs',
    '/app/public',
    '/fonts',
] as $directory) {
    if (! is_dir($path = $storage.$directory)) {
        // Recursive, and the @ is deliberate: two instances can race here on a
        // cold start and the loser must not fatal on "directory exists".
        @mkdir($path, 0755, true);
    }
}

// Set on all three superglobals because Laravel's env() reads $_ENV / $_SERVER,
// while putenv covers anything reaching for getenv() directly.
//
// Only effective when the config is NOT cached; see the note above. On Vercel
// the build sets the same two values so they survive config:cache.
foreach ([
    'DOMPDF_FONT_DIR' => $storage.'/fonts',
    'VIEW_COMPILED_PATH' => $storage.'/framework/views',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}

require __DIR__.'/../vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

// Before handleRequest(), which is what triggers config loading and therefore
// the first resolution of storage_path().
$app->useStoragePath($storage);

$app->handleRequest(Request::capture());
