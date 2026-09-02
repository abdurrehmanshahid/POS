<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Readiness probe: can this box actually serve a request that touches money.
 *
 * `/up` and `/ready` answer different questions and both are worth asking.
 *
 *   /up     Laravel's own health route. PHP-FPM answered and the framework
 *           booted. It is deliberately EXCEPTED from maintenance mode
 *           (ApplicationBuilder calls PreventRequestsDuringMaintenance::except
 *           on it), so it stays 200 during a deploy — which is correct for a
 *           liveness probe and useless as a readiness one.
 *
 *   /ready  This. The framework booted AND MySQL answers AND the paths Laravel
 *           writes to are writable. It is NOT excepted from maintenance, so it
 *           returns 503 for the duration of every deploy. That is the point:
 *           it is how you find out a failed deploy left maintenance mode on.
 *           Set the external monitor to alert on TWO consecutive failures, or
 *           it pages on every routine release and gets muted within a
 *           fortnight.
 *
 * ── Why the response is empty ──────────────────────────────────────────────
 *
 * This route is reachable by anyone on the internet, unauthenticated, forever.
 * A readiness endpoint that helpfully reports "SQLSTATE[HY000] [2002]
 * Connection refused for user institute_pos at 127.0.0.1" has just told a
 * stranger the database user, the host and the fact that the box is currently
 * degraded. Diagnostic JSON is a reconnaissance gift on an endpoint that exists
 * to be polled.
 *
 * So the contract is: a bare 200 or a bare 503, no body, no headers that say
 * anything. The reason goes to the application log, where the operator — who
 * can already read the log — will look. The monitor only ever needed the
 * status code.
 *
 * Deliberately registered outside the `web` middleware group. That group starts
 * a session, and SESSION_DRIVER is `database`: an external monitor polling
 * every five minutes would write 288 junk session rows a day into the same
 * database this endpoint exists to check.
 */
class ReadinessController extends Controller
{
    public function __invoke(): Response
    {
        $failure = $this->firstFailure();

        if ($failure !== null) {
            // Logged, not returned. See the class docblock.
            logger()->error('Readiness check failed: '.$failure);

            return response('', 503);
        }

        return response('', 200);
    }

    /**
     * The first thing that is wrong, or null if nothing is.
     *
     * Short-circuits deliberately: this runs on every poll, and once the answer
     * is 503 there is nothing further to learn that the log will not already
     * carry from the previous check.
     */
    private function firstFailure(): ?string
    {
        // MySQL. `SELECT 1` rather than a query against a real table, because
        // this must not depend on any particular migration having run, and must
        // not take a lock or touch a row.
        try {
            DB::connection()->select('select 1');
        } catch (Throwable $e) {
            return 'database unreachable: '.$e->getMessage();
        }

        // The paths a normal request writes to. Any one of them read-only —
        // after a chown that missed, a full disk, a botched deploy — produces a
        // 500 on a page that looks entirely unrelated to storage.
        foreach ([
            storage_path('framework/views'),
            storage_path('framework/cache'),
            storage_path('logs'),
            base_path('bootstrap/cache'),
        ] as $path) {
            if (! is_dir($path) || ! is_writable($path)) {
                return "not writable: {$path}";
            }
        }

        // The cache STORE, not just the database it happens to live in.
        //
        // This check exists because of a real outage that nothing noticed. The
        // `cache` table went missing from production while MySQL itself stayed
        // perfectly healthy, so `select 1` above passed on every poll and
        // /ready reported 200 for six hours. Meanwhile every Cache:: call threw
        // — 1,440 identical stack traces a day, 11MB of log, and no alert.
        //
        // What is actually down when this is down is worth spelling out,
        // because "the cache" sounds optional and none of this is:
        //
        //   · the login throttle. Laravel's RateLimiter is a cache client, so
        //     brute-force protection fails OPEN — the security control most
        //     worth having on an internet-facing till.
        //   · `withoutOverlapping` on the hourly backup and the weekly restore
        //     drill, both of which take their locks from this store.
        //   · every Cache::remember in the application.
        //
        // A round trip rather than a read: a missing table only fails on the
        // WRITE for some drivers, and a store that can be read but not written
        // is still a broken lock. The key is namespaced and immediately
        // forgotten so a poll every five minutes leaves nothing behind.
        try {
            $probe = 'readiness:'.bin2hex(random_bytes(4));
            Cache::put($probe, 1, 10);
            $ok = Cache::get($probe) === 1;
            Cache::forget($probe);

            if (! $ok) {
                return 'cache store wrote but did not read back';
            }
        } catch (Throwable $e) {
            return 'cache store unusable: '.$e->getMessage();
        }

        return null;
    }
}
