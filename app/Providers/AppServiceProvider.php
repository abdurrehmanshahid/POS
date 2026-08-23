<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    /**
     * Three things that must be true before any request is served: what a proxy
     * may be believed about, where the one link that leaves the building
     * points, and the thin permission layer (spec §3), where can($key) resolves
     * to "the current user's role grants $key" on both UI and server.
     */
    public function boot(): void
    {
        $this->believeTheProxyAboutThreeThings();
        $this->pinTheLinkThatLeavesTheBuilding();

        Gate::before(function (User $user, string $ability): ?bool {
            if (Permissions::isKnown($ability)) {
                return $user->hasPermission($ability) ?: false;
            }

            return null; // defer to any explicitly-defined gate/policy
        });
    }

    /**
     * What the application will and will not take a proxy's word for.
     *
     * Here rather than in `bootstrap/app.php` for a mundane reason: the
     * `withMiddleware` closure runs before the config repository is bound, so
     * `config()` there is a fatal error. `TrustProxies` sits in the global
     * middleware stack unconditionally, and these are static setters it reads
     * when it runs, so configuring it from a provider is equivalent and works.
     *
     * WHY BELIEVE ANYTHING. Every URL Laravel generates — `asset()`, `route()`,
     * `@vite` — is built from `$request->root()`, which reads the scheme off the
     * connection PHP actually received. Behind anything that terminates TLS and
     * forwards plain HTTP, that connection is `http://`, so those URLs come out
     * `http://` on a page the browser loaded over `https://` and are blocked as
     * mixed content: stylesheet, compiled JS, the institute's logo and the
     * fee-voucher viewer, all at once, with the only evidence in a console
     * nobody at a counter will open.
     *
     * Believing nothing is not the safe alternative it looks like. Without
     * `X-Forwarded-For`, `$request->ip()` is the PROXY's address for every
     * visitor alike, and real decisions key on it: `LoginThrottle` brakes
     * password spraying at 20 failures per IP, and every audit row records one.
     * Collapsed onto one shared address that brake becomes a lever — twenty bad
     * guesses lock out the entire institute — and the audit trail names the
     * proxy on every line.
     *
     * WHICH HEADERS, AND WHY NOT HOST. Laravel's default set also includes
     * `X-Forwarded-Host` and `-Prefix`. Host is the dangerous one — it re-points
     * generated URLs, which is the attack described at
     * {@see self::pinTheLinkThatLeavesTheBuilding()}. Nothing here needs a proxy
     * to tell it its own hostname, and nothing mounts this app under a path
     * prefix it does not already know, so neither header is read.
     */
    private function believeTheProxyAboutThreeThings(): void
    {
        // Defaulted at the call site as well as in the config file, because
        // `at()` is typed `array|string` and a null kills BOTH the web request
        // and the `config:clear` that would fix it. A config cache built before
        // this key existed — i.e. every existing deployment, at the moment it
        // pulls this commit — is exactly that null.
        TrustProxies::at(config('app.trusted_proxies') ?? '*');

        TrustProxies::withHeaders(
            Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO
        );
    }

    /**
     * Build the one link that leaves the building from the address we were
     * configured with, not from the one the caller claims we have.
     *
     * THE ATTACK. `Password::sendResetLink()` builds its URL from the request
     * host, and `Host:` is written by the client. Unpinned, an attacker submits
     * a member of staff's address on the public forgot-password form while
     * sending `Host: attacker.example`; that person then receives a genuine
     * email from this institute carrying a valid, unexpired reset token
     * pointing at the attacker's server. A working credential, delivered by us,
     * over our own domain's reputation.
     *
     * WHY SO NARROW. Two broader controls were tried first and both were worse:
     *
     *   `trustHosts()` rejects a mismatched Host with a 400. That also rejects
     *   any nginx left on its own documented default of
     *   `proxy_set_header Host $proxy_host`, and any second address staff
     *   legitimately reach the box on — the static IP before DNS propagates,
     *   say, which is exactly when you are least able to debug a bare 400.
     *   And `.env.example` ships
     *   `APP_URL=http://localhost`, so APP_ENV=production with APP_URL
     *   forgotten takes the entire site down with a bare 400 and nothing in the
     *   log naming why.
     *
     *   Pinning the whole URL generator avoids the outage but reaches far too
     *   wide: `route()` also feeds `redirect()`, so every post-login redirect
     *   would relocate the browser to APP_URL. Staff reaching the app on a
     *   second address — a LAN IP, an internal name for the counter machines —
     *   land on a host their session cookie does not match and loop on the
     *   login screen. Silent, and harder to diagnose than the 400 it replaced.
     *
     * The reset link is the ONLY absolute URL this application emits into the
     * world; `Password::sendResetLink()` in the forgot-password screen is the
     * single sender in the codebase. So pinning exactly that is the whole fix.
     * Links rendered inside a response stay relative to the host the browser is
     * actually on, which is what they should always have been, and there is no
     * environment to special-case — a reset link generated on a developer's
     * machine should point at APP_URL too.
     */
    private function pinTheLinkThatLeavesTheBuilding(): void
    {
        ResetPassword::createUrlUsing(
            // `absolute: false` yields the path with the request's base URL
            // stripped, and APP_URL carries any sub-directory prefix itself, so
            // the two compose correctly for `example.com/pos` as well as for a
            // bare domain.
            fn ($notifiable, string $token) => rtrim((string) config('app.url'), '/').route('password.reset', [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ], absolute: false)
        );
    }
}
