{{--
    The page behind every "view" button on a fee challan or a receipt.

    It holds one thing: Mozilla's PDF.js viewer, pointed at the route that
    streams the document. That is the entire trick, and it is worth saying what
    it buys, because the obvious implementation — stream the PDF `inline` and
    let the browser deal with it — is what this replaces.

    "inline" is a REQUEST, not an instruction. A browser honours it only if it
    has a PDF viewer and is permitted to use one, and where it is not — an
    office install with `AlwaysOpenPdfExternally` set by policy, an old Android
    WebView, an automation profile — it quietly downloads the file instead. So
    the one button labelled "view" was the one button whose promise the browser
    could refuse, and it refused it silently, which is why it read as a bug in
    this application rather than a limitation of the machine.

    PDF.js draws the pages itself, in JavaScript, onto a canvas. There is no
    viewer to be missing and no policy to forbid it, so every counter PC at the
    institute shows the identical document with the identical toolbar. Its
    Print and Save buttons act on the very bytes on screen.

    An iframe rather than a redirect to `web/viewer.html`, so that the address
    stays on a route of ours: the permission check runs here, the URL is one an
    officer can paste to a colleague, and the vendored path stays an
    implementation detail we can move.

    Both URLs arrive as same-origin paths — see App\Support\DocumentResponse,
    which explains why that is load-bearing rather than tidy.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="same-origin">
    <title>{{ $title }}</title>
    @include('partials.favicon')
    <style>
        /* The viewer is the page. No chrome of our own above it: PDF.js
           already offers save, print, zoom and page navigation, and a second
           Download button next to its Save button is a question nobody at a
           counter should have to answer. */
        html, body { margin: 0; height: 100%; overflow: hidden; background: #1c1c1f; }
        iframe { display: block; width: 100%; height: 100%; border: 0; }

        /* The fallback below is invisible until something goes wrong, and it
           sits UNDER the iframe rather than beside it: an iframe that loads
           covers it completely, and one that fails to render leaves it showing.
           No script decides this, which is the point — the failures worth
           catching here are the ones where script never ran. */
        .fallback {
            position: absolute; inset: 0; z-index: -1;
            display: flex; align-items: center; justify-content: center; padding: 24px;
            font: 15px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif; color: #e7e7ea;
        }
        .fallback div { max-width: 34rem; text-align: center; }
        .fallback h1 { font-size: 17px; margin: 0 0 10px; }
        .fallback p { margin: 0 0 18px; color: #b4b4bd; }
        .fallback a {
            display: inline-block; padding: 10px 18px; border-radius: 8px;
            background: #2A2668; color: #fff; text-decoration: none; font-weight: 700;
        }
        .fallback a.ghost { background: transparent; border: 1px solid #55555f; color: #e7e7ea; margin-left: 8px; }
    </style>
</head>
<body>
    {{-- Never a dead end. The page this replaced was plain HTML with a working
         download link, so it needed no JavaScript to show the document and none
         to save it; an iframe needs both. If the viewer 404s, if a host serves
         `.mjs` as text/plain and the module script is refused, or if scripting
         is off on a locked-down office profile, what is left without this is a
         dark rectangle and no way to reach the voucher at all — which would be
         a worse version of the very problem being fixed. --}}
    <div class="fallback">
        <div>
            <h1>{{ $title }}</h1>
            <p>The document viewer could not start on this browser. The file itself is fine — open or save it directly.</p>
            {{-- Two genuinely different destinations. Both pointing at $stream
                 with a `download` attribute would have offered a choice where
                 both answers do the same thing — on the browsers this fallback
                 exists for, one with no PDF viewer, "open" downloads anyway.
                 The second goes to the route that already sends `attachment`. --}}
            <a href="{{ $stream }}">Open the document</a>
            <a class="ghost" href="{{ $download }}">Save it</a>
        </div>
    </div>

    <iframe src="{{ $viewer }}?file={{ rawurlencode($stream) }}" title="{{ $title }}" allow="fullscreen"></iframe>
</body>
</html>
