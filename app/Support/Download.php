<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\Response;

/**
 * Name a file so the browser actually saves it under that name.
 *
 * Laravel's `->download($name)` builds the header through
 * `HeaderUtils::makeDisposition()`, which for a plain ASCII name emits the
 * shortest legal form:
 *
 *     Content-Disposition: attachment; filename=challan-BBT-CH-2026-1115.pdf
 *
 * That is valid — RFC 6266 permits an unquoted `token`, and every character in
 * a challan number qualifies. Valid is not the same as universally honoured:
 * the unquoted form has to be re-parsed correctly by the browser, any download
 * manager sitting behind it, and any proxy in between, and where one of them
 * declines the file lands under a generated name with no extension. A voucher
 * saved as a bare UUID is one nobody can find again, attach to an email, or
 * recognise a week later.
 *
 * So both forms are stated outright and neither is left to be inferred:
 *
 *     filename="challan-BBT-CH-2026-1115.pdf"                 quoted, RFC 6266
 *     filename*=UTF-8''challan-BBT-CH-2026-1115.pdf           RFC 5987
 *
 * Redundant by design. `filename*` wins wherever it is understood and the
 * quoted form catches everything older, so there is no parser left that has to
 * guess. It costs one header.
 */
class Download
{
    /** Stamp an attachment disposition that names the file unambiguously. */
    public static function named(Response $response, string $filename): Response
    {
        return self::disposition($response, 'attachment', $filename);
    }

    /**
     * The same document, displayed rather than saved — but named just as firmly.
     *
     * The name matters MORE here, not less, which is not the obvious way round.
     * This is what the PDF.js viewer fetches, and its own download button reads
     * the filename straight out of this header. Leave it off and the student
     * copy saves as `pdf` or as the raw route path; state it and viewing then
     * saving produces exactly the same file as the download button on the
     * Challans list, under exactly the same name.
     */
    public static function inline(Response $response, string $filename): Response
    {
        return self::disposition($response, 'inline', $filename);
    }

    private static function disposition(Response $response, string $type, string $filename): Response
    {
        // Defensive rather than decorative: a challan number is built from a
        // sequence, but `description` reaches this on nothing today and might
        // tomorrow. A quote or a newline in a filename is header injection, and
        // the fix is to have never been able to write one.
        $safe = str_replace(['"', '\\', "\r", "\n"], '', $filename);

        // The two parameters are allowed to differ, and here they must.
        // RFC 6266 defines the quoted form over ISO-8859-1, so the first
        // non-ASCII name to reach this — a student's name, an Urdu charge
        // description — would put raw UTF-8 bytes in a field that cannot carry
        // them, and a parser preferring the quoted form saves mojibake. That is
        // the outcome this class exists to prevent, so the quoted form is
        // reduced to ASCII and `filename*`, which is defined over UTF-8, keeps
        // the real name. Every parser then gets something it can read.
        $ascii = preg_replace('/[^\x20-\x7e]/', '_', $safe);

        $response->headers->set('Content-Disposition', sprintf(
            '%s; filename="%s"; filename*=UTF-8\'\'%s',
            $type,
            $ascii,
            rawurlencode($safe),
        ));

        return $response;
    }
}
