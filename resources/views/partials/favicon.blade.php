{{--
    The tab icon, in one file because there are four <head>s.

    Every tab in the app showed the browser's generic globe. No layout declared
    an icon at all, so the browser fell back to its unasked-for request for
    /favicon.ico — and that file was in the repository at ZERO bytes. It
    answered 200, so nothing looked broken from the server's side; the browser
    simply had an empty image and drew its placeholder.

    The mark is the "B" from the institute's logo, cropped from bbt-logo.png at
    its ink bounds and set on white. White rather than transparent on purpose:
    the mark is navy, and a transparent navy glyph nearly disappears against
    Chrome's dark tab strip — which is exactly where the institute works.

    The .ico stays for the automatic /favicon.ico request that no markup
    controls; the PNGs are what any current browser will actually pick.
--}}
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32.png') }}">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
