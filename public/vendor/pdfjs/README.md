# PDF.js — vendored, not written here

Mozilla's PDF viewer, v6.2.108, Apache-2.0 (see `LICENSE`). Nothing in this
directory is ours; do not edit it. It is committed rather than installed
because the npm package `pdfjs-dist` stopped shipping the viewer application at
v6 — npm now carries only the rendering library — and because a counter PC at
the institute must be able to open a fee voucher with no internet connection and
no build step.

## Why it is here at all

Every screen that shows a fee challan or a receipt shows the *same PDF the
student is handed*. Before this, the "view" button streamed a PDF `inline`,
which is a request the browser is free to decline: where there is no PDF viewer
— a policy-locked office install, an old Android WebView, an automation profile
— the file silently downloads instead, so the one button labelled "view" was the
one button whose promise could not be kept.

PDF.js renders to a canvas in JavaScript. It needs no viewer, no plugin and no
permission, so "viewable" stops being a property of the visitor's machine.

The alternative was to keep a hand-written HTML twin of the voucher for screen
and print, with dompdf producing a separate PDF for download. That is two
typesetters for one financial document, and they drift: dompdf supports a subset
of CSS, so the first person to reach for flexbox to tidy the screen changes what
the parent's copy looks like without ever seeing it happen. There is now one
renderer and one artifact.

## What was removed from the release, and why

Taken from `pdfjs-6.2.108-dist.zip`, 21 MB as published, 6.6 MB as committed.

| Removed | Size | Why it is safe |
| --- | --- | --- |
| `**/*.map` | 8.6 MB | Source maps for a library we never debug or modify. |
| `web/cmaps/` | 1.6 MB | CJK character maps. The vouchers are Latin script. |
| `web/locale/*` except `en-US` | 2.9 MB | The counter runs in English. `locale.json` was rewritten to list only what is still present, so a non-English browser falls back cleanly instead of requesting a file that is gone. |
| `web/debugger.*`, sample PDF | small | Mozilla's own development tools. |

`web/wasm/` and `web/standard_fonts/` are **kept** deliberately, though neither
is needed by a voucher as dompdf typesets it today. They are what PDF.js falls
back on for colour-managed images and for fonts a PDF references without
embedding. Dropping them saves 2.3 MB and buys a blank logo on a document
somebody is about to hand over, on some future day when the template changes.
That is the wrong trade for a document of record.

## What was added

`web/institute.css` is ours — the only file here that is — and `web/viewer.html`
carries one `<link>` to it. It hides the annotation editor (Draw, Text,
Highlight, Add signature, Manage pages) because these are documents of record,
not drafts. Its own header explains the reasoning.

## Upgrading

Download `pdfjs-<version>-dist.zip` from
<https://github.com/mozilla/pdf.js/releases> and unzip over this directory,
then:

1. Redo the four removals in the table above.
2. Rewrite `web/locale/locale.json` to list only `en-US`.
3. Put back the `<link rel="stylesheet" href="institute.css">` in
   `web/viewer.html`, after the one for `viewer.css`.
4. Check the ids in `institute.css` still exist in the new `viewer.html` —
   they are the one thing here that a release can silently invalidate, and the
   symptom is a Draw button reappearing on a fee voucher.
5. Update the version at the top of this file.

`tests/Feature/ScreensTest::test_the_vendored_pdf_viewer_is_present` fails if
step 1 removes too much. Nothing in the application imports these files by name
except `resources/views/documents/viewer.blade.php`, which points at
`web/viewer.html`.
