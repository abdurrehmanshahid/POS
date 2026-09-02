<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Nothing in the critical rendering path comes from somebody else's server.
 *
 * Inter used to be three tags in every layout's <head>: two preconnects and a
 * render-blocking stylesheet from fonts.googleapis.com. Measured from Lahore on
 * 2026-09-02 that stylesheet took **1.6 seconds** to answer, against a box whose
 * own responses take 25ms. The font was the slowest thing on the page by a
 * factor of sixty, and the browser cannot paint until a stylesheet in <head>
 * resolves.
 *
 * It is now self-hosted and fingerprinted by Vite. These tests exist because the
 * regression is a one-line paste that looks completely reasonable in review —
 * a `<link>` to a font CDN is what most Laravel starter markup ships with, and
 * nothing about it looks like a performance bug from Europe.
 */
class FrontendAssetsTest extends TestCase
{
    use RefreshDatabase;

    /** Every layout, including the two behind auth that a guest request cannot reach. */
    private const LAYOUTS = [
        'resources/views/components/layouts/app.blade.php',
        'resources/views/components/layouts/super.blade.php',
        'resources/views/components/layouts/guest.blade.php',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['institute.today' => '2026-07-15']);
        Carbon::setTestNow('2026-07-15 10:00:00');
        $this->seed(DatabaseSeeder::class);
    }

    /**
     * No layout may reach out to a font CDN.
     *
     * Asserted against the source rather than a rendered response, because two
     * of the three layouts need a session and the point is to catch the paste
     * in review, not only on a page somebody remembered to request.
     */
    public function test_no_layout_loads_fonts_from_a_third_party(): void
    {
        foreach (self::LAYOUTS as $layout) {
            $markup = file_get_contents(base_path($layout));

            // Only the <link>/<script> tags matter; the comments in these files
            // name the old host deliberately, to say why it is gone.
            preg_match_all('/<(?:link|script)\b[^>]*>/i', $markup, $tags);

            foreach ($tags[0] as $tag) {
                $this->assertStringNotContainsString('fonts.googleapis.com', $tag,
                    "{$layout} loads a font stylesheet from Google. It blocks first paint and measured 1.6s from Pakistan — self-host it instead.");
                $this->assertStringNotContainsString('fonts.gstatic.com', $tag,
                    "{$layout} still preconnects to a font CDN it no longer uses.");
            }
        }
    }

    /**
     * A guest page renders with no external origin in its markup at all.
     *
     * The complement to the source check above: proves the built asset tags
     * Vite injects are same-origin too, not just the ones written by hand.
     */
    public function test_a_rendered_page_references_no_external_origin(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        preg_match_all('#(?:href|src)="(https?://[^"]+)"#i', $html, $matches);

        $external = array_values(array_filter(
            $matches[1],
            fn (string $url) => ! str_starts_with($url, config('app.url'))
        ));

        $this->assertSame([], $external,
            'The sign-in page pulls from an external origin. Every one is a third party that can be slow, blocked, or down while the institute is trying to take a fee.');
    }

    /**
     * The font files are actually present and are actually fonts.
     *
     * A missing file would not fail any of the above: the CSS would still
     * declare `Inter`, the browser would fall back to system-ui, and the only
     * symptom is that the app quietly stops looking like itself.
     */
    public function test_the_self_hosted_font_files_exist(): void
    {
        foreach (['inter-latin.woff2', 'inter-latin-ext.woff2'] as $file) {
            $path = base_path('resources/fonts/'.$file);

            $this->assertFileExists($path);

            // wOF2 magic number. Guards against a truncated or HTML-error-page
            // download being committed as a font.
            $this->assertSame('774f4632', bin2hex(file_get_contents($path, false, null, 0, 4)),
                "{$file} is not a woff2 file.");
        }
    }

    /**
     * Both subsets are declared with a `unicode-range`.
     *
     * The range is what makes two files cheaper than one, not more expensive:
     * an all-ASCII screen fetches the 47KB latin file and never touches the
     * 83KB latin-ext. Drop the ranges and every visitor downloads both.
     */
    public function test_each_font_subset_is_scoped_by_unicode_range(): void
    {
        // Comments stripped first: the block above these rules explains the
        // `swap` and the ranges in prose, and counting those mentions would
        // make this test pass on documentation rather than on declarations.
        $css = preg_replace('#/\*.*?\*/#s', '', file_get_contents(base_path('resources/css/app.css')));

        preg_match_all('/@font-face\s*\{(.*?)\}/s', $css, $faces);

        $this->assertCount(2, $faces[1], 'Expected exactly the latin and latin-ext subsets.');

        foreach ($faces[1] as $face) {
            $this->assertStringContainsString('unicode-range:', $face,
                'Without a unicode-range every visitor downloads both subsets.');
            $this->assertStringContainsString('font-display: swap', $face,
                'Text must paint in the fallback immediately; nobody waits on a font to read a fee.');
        }
    }

    /**
     * nginx must be told what a `.mjs` file is.
     *
     * nginx 1.24's mime.types has no entry for it, so PDF.js's ES modules went
     * out as application/octet-stream. The vhost sets `X-Content-Type-Options:
     * nosniff` — correctly — and a browser flatly refuses to execute a module
     * script served as octet-stream. It refuses SILENTLY: no 404, no 500,
     * nothing in the nginx log.
     *
     * What that looked like was a fee challan that opened to a blank page.
     * viewer.html is text/html and loaded fine, so PDF.js's toolbar drew — page
     * box, zoom, print, save — and nothing ever rendered inside it. The PDF was
     * never at fault; the same bytes downloaded and opened correctly throughout.
     *
     * Asserted against provision.sh because P2-20 rebuilds this box from bare,
     * twice. A reinstall that loses this line brings the blank viewer back with
     * no error anywhere to explain it, which is exactly the class of bug that
     * survives a rehearsal.
     */
    public function test_the_provisioner_teaches_nginx_the_mjs_mime_type(): void
    {
        $provision = file_get_contents(base_path('deploy/provision.sh'));

        $this->assertMatchesRegularExpression(
            '/(application|text)\/javascript\s+mjs;/',
            $provision,
            'nginx has no built-in type for .mjs. Without one, PDF.js is served as octet-stream and nosniff stops it running — the fee challan viewer renders blank.'
        );
    }

    /**
     * Compression must cover more than HTML.
     *
     * `gzip on` is in Ubuntu's stock nginx.conf and reads as "compression is
     * handled". It is not: the default `gzip_types` is `text/html` alone, so
     * every stylesheet and script went out whole. First view of a challan
     * pushed 3.1MB uncompressed to a city on the far end of ~150ms of round
     * trip.
     */
    public function test_the_provisioner_compresses_stylesheets_and_scripts(): void
    {
        $provision = file_get_contents(base_path('deploy/provision.sh'));

        $this->assertStringContainsString('gzip_types', $provision,
            'gzip_types is unset, so nginx compresses text/html and nothing else.');

        foreach (['text/css', 'application/javascript'] as $type) {
            $this->assertStringContainsString($type, $provision,
                "gzip_types does not list {$type}; it is among the largest things this app sends.");
        }
    }

    /**
     * The sidebar prefetches on hover.
     *
     * The box is in Frankfurt and the institute is in Lahore: 142ms of round
     * trip on every navigation that no amount of server tuning can remove.
     * `.hover` spends it while the hand is still moving toward the click.
     */
    public function test_the_sidebar_navigation_prefetches_on_hover(): void
    {
        foreach ([
            'resources/views/components/layouts/app.blade.php',
            'resources/views/components/layouts/super.blade.php',
        ] as $layout) {
            $this->assertStringContainsString('wire:navigate.hover', file_get_contents(base_path($layout)),
                "{$layout}'s sidebar should prefetch on hover — it is the navigation people use dozens of times an hour.");
        }
    }
}
