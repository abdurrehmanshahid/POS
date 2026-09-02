<?php

namespace Tests\Feature;

use App\Support\Theme;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The colour scheme survives a `wire:navigate`.
 *
 * ---------------------------------------------------------------------------
 * The bug these tests exist for.
 * ---------------------------------------------------------------------------
 *
 * The theme lived only in `localStorage`, applied by an inline script in each
 * layout's <head>. Livewire's `wire:navigate` fetches the next page and swaps
 * the document in, so <html> was replaced by the server's `<html lang="en">` —
 * no `data-theme` — and the attribute the script had set was gone.
 *
 * The CSS puts the light palette on bare `:root` and overrides it under
 * `:root[data-theme="dark"]`, so the missing attribute did not mean "no theme",
 * it meant **light**. Anyone working in dark mode was thrown back to light on
 * every single navigation, while `localStorage` and the Alpine store both still
 * said `dark`. That mismatch is why it read as a rendering glitch.
 *
 * The fix moved the decision to the server: the preference is mirrored into a
 * cookie and rendered into the markup, so every document Livewire fetches
 * already carries the right attribute. These tests hold that line.
 *
 * They assert on the RENDERED ATTRIBUTE, not on the helper alone. A test that
 * only exercised `Theme::current()` would still pass if somebody removed
 * `data-theme` from a layout, which is exactly the regression that would bring
 * the bug back.
 */
class ThemeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['institute.today' => '2026-07-15']);
        Carbon::setTestNow('2026-07-15 10:00:00');
        $this->seed(DatabaseSeeder::class);
    }

    /*
     * Every request below uses `withUnencryptedCookie`, never `withCookie`.
     *
     * `withCookie` encrypts, the way the framework's own cookies are encrypted.
     * This one is deliberately exempt (bootstrap/app.php) because client-side
     * JavaScript writes it, so an encrypted test cookie is not what a browser
     * sends — it arrives as an unreadable blob, `Theme` discards it, and the
     * test fails against code that is actually correct.
     */

    public function test_the_default_is_dark_when_nothing_has_been_chosen(): void
    {
        $this->assertSame('dark', Theme::current(request()));

        $this->get('/login')->assertOk()->assertSee('data-theme="dark"', false);
    }

    /**
     * Both real values round-trip from the cookie into the markup.
     *
     * @param  'dark'|'light'  $choice
     */
    #[DataProvider('choices')]
    public function test_a_chosen_theme_is_rendered_into_the_page(string $choice): void
    {
        $this->withUnencryptedCookie(Theme::COOKIE, $choice)
            ->get('/login')
            ->assertOk()
            ->assertSee('data-theme="'.$choice.'"', false);
    }

    /** @return array<string, list<string>> */
    public static function choices(): array
    {
        return ['dark' => ['dark'], 'light' => ['light']];
    }

    /**
     * Every layout renders it, not just the one somebody remembered.
     *
     * There are three — `guest`, `app` and `super` — and the bug was equally
     * present in all of them. `/login` is the guest layout and `/superadmin` is
     * the super layout; both are reachable without a session, which is what
     * lets this assert on the real rendered markup rather than a partial.
     */
    public function test_every_reachable_layout_renders_the_attribute(): void
    {
        foreach (['/login', '/superadmin'] as $path) {
            $this->withUnencryptedCookie(Theme::COOKIE, 'light')
                ->get($path)
                ->assertOk()
                ->assertSee('data-theme="light"', false);
        }
    }

    /**
     * A cookie the server did not write cannot inject an attribute.
     *
     * The theme cookie is deliberately NOT encrypted — client-side JavaScript
     * writes it, and Laravel would drop a value it could not decrypt. That makes
     * it attacker-controlled input interpolated into an HTML attribute on every
     * page, so `Theme` whitelists the two values it will render rather than
     * trusting Blade's escaping to be enough.
     *
     * @param  string  $hostile  a value that must never reach the attribute
     */
    #[DataProvider('hostileCookies')]
    public function test_a_cookie_that_is_not_one_of_the_two_values_is_discarded(string $hostile): void
    {
        $response = $this->withUnencryptedCookie(Theme::COOKIE, $hostile)->get('/login')->assertOk();

        $response->assertSee('data-theme="dark"', false);
        $response->assertDontSee('data-theme="'.$hostile.'"', false);
    }

    /** @return array<string, list<string>> */
    public static function hostileCookies(): array
    {
        return [
            'markup breakout' => ['"><script>alert(1)</script>'],
            'attribute injection' => ['dark" onload="alert(1)'],
            'unknown scheme' => ['sepia'],
            'empty' => [''],
            'casing' => ['DARK'],
        ];
    }

    /**
     * The cookie must stay OUT of the encrypted set.
     *
     * If it is ever encrypted, the browser-written value stops being readable,
     * `Theme::current()` silently returns the default on every request, and the
     * toggle appears to work until the next navigation undoes it — the original
     * bug, back, with no error anywhere to explain it.
     */
    public function test_the_theme_cookie_is_not_encrypted(): void
    {
        // A raw, unencrypted cookie is exactly what the browser sends.
        $this->withUnencryptedCookie(Theme::COOKIE, 'light')
            ->get('/login')
            ->assertOk()
            ->assertSee('data-theme="light"', false);
    }

    /**
     * The three defaults have to agree.
     *
     * The server default, the inline fallback script and the Alpine store all
     * say "dark" independently. If one drifts, the first paint disagrees with
     * the first toggle and the button looks like it does nothing on the first
     * click — the classic symptom of two sources of truth.
     */
    public function test_the_server_and_the_client_agree_on_the_default(): void
    {
        $this->assertSame('dark', Theme::DEFAULT);

        foreach ([
            'resources/views/components/layouts/app.blade.php',
            'resources/views/components/layouts/super.blade.php',
            'resources/views/components/layouts/guest.blade.php',
        ] as $layout) {
            $markup = file_get_contents(base_path($layout));

            $this->assertStringContainsString('data-theme="{{ \App\Support\Theme::current() }}"', $markup,
                "{$layout} must render the theme server-side, or wire:navigate strips it.");
            $this->assertStringContainsString("localStorage.getItem('bbt-theme') || 'dark'", $markup,
                "{$layout}'s no-cookie fallback must default to dark, like the server.");
        }

        $js = file_get_contents(base_path('resources/js/app.js'));
        $this->assertStringContainsString('livewire:navigated', $js,
            'app.js must re-apply the theme after a navigation for the cookies-disabled case.');
    }
}
