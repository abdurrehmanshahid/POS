<?php

namespace App\Support;

use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * How a fee challan or a payment receipt is put on the wire.
 *
 * Beside {@see Download}, which decides how the file is NAMED, because they are
 * two halves of one question and used to be answered in two controllers that
 * had already begun to disagree. A plain class of statics rather than a trait:
 * nothing here touches `$this`, so horizontal inheritance would buy only the
 * inability to call or test any of it without a controller to host it.
 */
final class DocumentResponse
{
    /**
     * The PDF.js viewer, pointed at a route that streams the document.
     *
     * Every URL is reduced to a path, and that is load-bearing rather than
     * tidy. PDF.js fetches the stream with `credentials: "same-origin"`, so the
     * session cookie rides along only while the fetch really is same-origin —
     * and the origin the browser compares against is the IFRAME's, not the top
     * page's. Root-relative URLs cannot disagree with the address bar, so the
     * question does not arise.
     *
     * Framework-level correctness is handled where it belongs: `trustProxies`
     * in bootstrap/app.php is what makes `$request->root()` honest behind a
     * TLS-terminating proxy, for these URLs and every other one the app
     * generates. This is belt to that braces, not a substitute for it.
     */
    public static function viewer(string $title, string $streamUrl, string $downloadUrl): Response
    {
        return response()->view('documents.viewer', [
            'title' => $title,
            'viewer' => self::path(asset('vendor/pdfjs/web/viewer.html')),
            'stream' => self::path($streamUrl),
            'download' => self::path($downloadUrl),
        ]);
    }

    /**
     * Typeset a document. The ONLY renderer either controller has.
     *
     * Raw bytes rather than dompdf's own `->stream()`/`->download()` helpers,
     * because those build a Content-Disposition of their own that `Download`
     * would only have to overwrite. One place decides how a document is named.
     */
    public static function pdf(string $view, array $data, string $paper): Response
    {
        $bytes = Pdf::loadView($view, $data)->setPaper($paper, 'landscape')->output();

        return response($bytes, 200, ['Content-Type' => 'application/pdf']);
    }

    /**
     * Strip an absolute URL back to a same-origin path, base URL intact.
     *
     * NOT `route(..., absolute: false)`, which looks like it does this and does
     * something subtly different: it removes the request's base URL as well. On
     * a sub-directory install — `example.com/pos/`, the layout every shared host
     * in this market sells — that turns `/pos/challans/5/stream` into
     * `/challans/5/stream`, which resolves against the domain root and 404s.
     */
    private static function path(string $url): string
    {
        return parse_url($url, PHP_URL_PATH) ?: '/';
    }
}
