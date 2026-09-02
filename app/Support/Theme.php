<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Which colour scheme to render, decided on the SERVER.
 *
 * ---------------------------------------------------------------------------
 * Why the server has an opinion about a client-side preference at all.
 * ---------------------------------------------------------------------------
 *
 * The theme used to live only in `localStorage`, applied by an inline script in
 * each layout's <head>. That works on a full page load and fails on every
 * `wire:navigate`: Livewire fetches the next page and swaps the document in, so
 * the <html> element is replaced by the server's version — `<html lang="en">`,
 * with no `data-theme` — and the attribute the inline script had set is wiped.
 *
 * The CSS defines the light palette on bare `:root` and overrides it under
 * `:root[data-theme="dark"]`, so losing the attribute does not mean "no theme",
 * it means **light**. A user in dark mode was thrown back to light on every
 * single navigation, while `localStorage` and the Alpine store both still
 * correctly said `dark` — which is why it looked like a rendering glitch rather
 * than a state bug.
 *
 * Re-applying the attribute from JavaScript after `livewire:navigated` would
 * fix the symptom, but only after the swapped-in DOM has already been painted
 * with the wrong palette — a white flash on every click, in a room where people
 * chose dark mode for a reason.
 *
 * So the preference is mirrored into a cookie and rendered into the markup. Then
 * every document Livewire fetches already carries the right attribute, there is
 * no race and no flash, and the JavaScript becomes a safety net rather than the
 * mechanism.
 *
 * `localStorage` is still written by the toggle. It is not read for rendering
 * any more, but it is what a browser with cookies disabled still has, and the
 * inline pre-paint script falls back to it.
 */
class Theme
{
    public const COOKIE = 'bbt-theme';

    /**
     * The only two values that may reach the `data-theme` attribute.
     *
     * Whitelisted rather than escaped. The cookie is attacker-controlled — it is
     * written by client-side JavaScript and never signed — and this value is
     * interpolated into an HTML attribute on every page. Anything not on this
     * list is not sanitised, it is discarded for the default.
     */
    private const ALLOWED = ['dark', 'light'];

    /**
     * Dark, unless the visitor has said otherwise.
     *
     * Matches the pre-existing default in the inline script and in the Alpine
     * store; the three must agree, or the first paint disagrees with the first
     * toggle and the button appears to do nothing.
     */
    public const DEFAULT = 'dark';

    public static function current(?Request $request = null): string
    {
        $request ??= request();

        $value = $request?->cookie(self::COOKIE);

        return is_string($value) && in_array($value, self::ALLOWED, true)
            ? $value
            : self::DEFAULT;
    }
}
