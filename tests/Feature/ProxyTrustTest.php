<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * What the application will and will not take a caller's word for.
 *
 * This is configuration rather than code, which is exactly why it is worth
 * tests: nothing crashes when it is wrong. A missed `X-Forwarded-Proto` shows
 * up as a blank fee voucher and a broken stylesheet on the live site only; a
 * trusted `X-Forwarded-Host` shows up as nothing at all until somebody uses it.
 *
 * Asserted through real requests rather than by reading the static properties
 * back. Reading the properties proves we wrote what we meant to write; only a
 * request proves the middleware is in the stack and consults them.
 *
 * THE RESIDUAL RISK, stated plainly. With `TRUSTED_PROXIES=*` the app believes
 * whoever is speaking. That is safe on a platform that is the only way in, and
 * NOT safe wherever a client can reach the app directly, or where the proxy
 * appends to `X-Forwarded-For` rather than overwriting it (nginx's usual
 * `$proxy_add_x_forwarded_for` recipe does exactly that, preserving a forged
 * entry at the head of the chain). There, an ordinary client can set its own
 * apparent address, which costs the per-IP spray brake in `LoginThrottle` and
 * the truthfulness of every audit row.
 *
 * The bound is the ACCOUNT counter: rotating a forged address does not reset
 * it, which {@see AuthSecurityTest::test_lockout_follows_the_account_across_different_ips}
 * proves. So stuffing a named officer still stops at five attempts. The fix for
 * a deployment where the rest matters is configuration, not code — and
 * test_a_pinned_proxy_list_disbelieves_an_untrusted_hop below is the proof that
 * the knob does something.
 */
class ProxyTrustTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->group(function () {
            Route::get('/_probe', fn (Request $r) => [
                'secure' => $r->isSecure(),
                'root' => $r->root(),
                'ip' => $r->ip(),
                'host' => $r->getHost(),
                'base' => $r->getBaseUrl(),
                'in_app_link' => route('login'),
                // What actually gets emailed. Built through the notification so
                // the test exercises `ResetPassword::createUrlUsing()` rather
                // than a hand-rolled imitation of it.
                'reset_email_link' => (new ResetPassword('tok'))->toMail(
                    new class
                    {
                        public function getEmailForPasswordReset(): string
                        {
                            return 'staff@bbt.edu.pk';
                        }
                    }
                )->actionUrl,
            ]);
        });
    }

    /**
     * `TrustProxies` keeps its configuration in statics, which outlive a test.
     * Put the real default back so a pinned list set by one test cannot
     * silently change what a later one is measuring.
     */
    protected function tearDown(): void
    {
        config(['app.trusted_proxies' => '*']);
        (new AppServiceProvider($this->app))->boot();

        parent::tearDown();
    }

    /**
     * The scheme is taken from the proxy — the reason any of this exists.
     *
     * Behind a TLS-terminating proxy the connection PHP receives is plain HTTP,
     * so without this every URL the app generates comes out `http://` on a page
     * the browser loaded over `https://`, and the browser blocks the lot as
     * mixed content: stylesheet, compiled JS, the institute's logo, and the
     * PDF.js viewer every fee voucher is displayed in.
     */
    public function test_the_proxy_is_believed_about_https(): void
    {
        $body = $this->get('/_probe', [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Port' => '443',
        ])->assertOk()->json();

        $this->assertTrue($body['secure'], 'The proxy said https and was not believed.');
        $this->assertStringStartsWith('https://', $body['root'],
            'Generated URLs would be http:// on an https page — mixed content, and the viewer never loads.');
    }

    /**
     * The client's address is taken from the proxy, so the per-IP brake brakes
     * the right thing.
     *
     * Without it `$request->ip()` is the PROXY's address for every visitor
     * alike. `LoginThrottle` stops password spraying at 20 failures per IP, and
     * collapsed onto one shared address that brake becomes a lever: twenty bad
     * guesses lock every member of staff out at once.
     */
    public function test_the_proxy_is_believed_about_who_is_calling(): void
    {
        $this->get('/_probe', ['X-Forwarded-For' => '203.0.113.9'])
            ->assertOk()
            ->assertJson(['ip' => '203.0.113.9']);
    }

    /**
     * And a hop from an address NOT on a pinned list is disbelieved — the whole
     * point of the setting, and the configuration docs/DEPLOYMENT.md tells every
     * self-hosted institute to adopt.
     *
     * Without this the knob could be entirely inert and the suite would not
     * notice: every other test here runs under the permissive `*` default.
     */
    public function test_a_pinned_proxy_list_disbelieves_an_untrusted_hop(): void
    {
        config(['app.trusted_proxies' => '198.51.100.7']);
        (new AppServiceProvider($this->app))->boot();

        // The test client calls from 127.0.0.1, which is not the pinned proxy,
        // so its claim about who it is forwarding for carries no weight.
        $body = $this->get('/_probe', ['X-Forwarded-For' => '203.0.113.9'])
            ->assertOk()->json();

        $this->assertNotSame('203.0.113.9', $body['ip'],
            'A forged X-Forwarded-For from an untrusted address was believed.');
        $this->assertSame('127.0.0.1', $body['ip']);
    }

    /**
     * `X-Forwarded-Host` is not read. Laravel trusts it by default, so this had
     * to be switched off deliberately.
     */
    public function test_a_forwarded_hostname_is_ignored(): void
    {
        $body = $this->get('/_probe', [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'attacker.example.com',
        ])->assertOk()->json();

        $this->assertNotSame('attacker.example.com', $body['host']);
        $this->assertStringNotContainsString('attacker.example.com', $body['root']);
    }

    /**
     * `X-Forwarded-Prefix` is not read either.
     *
     * Asserted as behaviour rather than as a bitmask, because the mask we want
     * — FOR|PORT|PROTO — is the integer 26, and so is
     * `Request::HEADER_X_FORWARDED_AWS_ELB`. A numeric assertion cannot tell the
     * two apart and would pass on a change that swapped one for the other. A
     * trusted prefix would silently re-root every generated path.
     */
    public function test_a_forwarded_path_prefix_is_ignored(): void
    {
        $body = $this->get('/_probe', ['X-Forwarded-Prefix' => '/somewhere-else'])
            ->assertOk()->json();

        $this->assertSame('', $body['base'],
            'A forwarded prefix moved the base URL; every generated path would be re-rooted.');
        $this->assertStringNotContainsString('somewhere-else', $body['in_app_link']);
    }

    /**
     * THE ONE THAT MATTERS. A poisoned `Host:` cannot steer the reset link.
     *
     * An attacker submits a member of staff's address on the public
     * forgot-password form while claiming a hostname of their own; that person
     * must not then receive an email from us carrying a valid token that points
     * at the attacker.
     *
     * Injected with an ABSOLUTE url, which is the only way to set Host through
     * the test client — `$this->get('/path', ['Host' => ...])` is overwritten by
     * Symfony from the request URI and proves nothing.
     */
    public function test_a_poisoned_host_cannot_steer_the_emailed_reset_link(): void
    {
        config(['app.url' => 'https://pos.bbt.edu.pk']);

        $body = $this->get('http://attacker.example.com/_probe')->assertOk()->json();

        $this->assertStringStartsWith('https://pos.bbt.edu.pk/', $body['reset_email_link'],
            'The emailed reset link was built from the caller-supplied host.');
        $this->assertStringNotContainsString('attacker.example.com', $body['reset_email_link']);
        $this->assertStringContainsString('tok', $body['reset_email_link']);
    }

    /**
     * And the pinning stops there — links INSIDE a response still follow the
     * host the browser is actually on.
     *
     * This guards a regression that a broader fix caused and this one avoids.
     * Pinning the whole URL generator also pins `redirect()`, so staff reaching
     * the app on a second address — a LAN IP, an internal name for the counter
     * machines — were bounced to APP_URL, landed on a host their session cookie
     * did not match, and looped on the login screen.
     */
    public function test_in_app_links_still_follow_the_host_the_browser_is_on(): void
    {
        config(['app.url' => 'https://pos.bbt.edu.pk']);

        $body = $this->get('http://counter-pc.institute.local/_probe')->assertOk()->json();

        $this->assertStringStartsWith('http://counter-pc.institute.local/', $body['in_app_link'],
            'In-app links were pinned to APP_URL; anyone on a second hostname loses their session.');
    }
}
