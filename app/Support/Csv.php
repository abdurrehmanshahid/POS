<?php

namespace App\Support;

/**
 * RFC 4180 records, written the way Excel actually reads them.
 *
 * Extracted from `StudentExportController`, which grew its own copy of this
 * first. A second copy is how two exports from the same system end up quoting
 * differently — one of them handling a comma in a course title and the other
 * shifting every column after it by one.
 *
 * Two decisions are carried here rather than left to `fputcsv()`:
 *
 *   CRLF, not LF. RFC 4180 says CRLF, and Excel on Windows is the reader that
 *   matters for this file. `fputcsv()` emits LF.
 *
 *   Quote only what needs it. A file where every field is quoted is legal but
 *   unreadable in a diff or a terminal, and this one gets eyeballed.
 *
 * NOT handled here: formula injection. A field beginning `=`, `+`, `-` or `@`
 * is evaluated by Excel on open, and student names reach these files. The fix
 * costs a visible prefix on every `+92…` phone number, so it is a decision
 * about the file's appearance rather than a free win, and it is recorded in
 * docs/backlog.md rather than taken unilaterally here.
 */
class Csv
{
    /**
     * Excel reads a CSV as the system codepage unless a BOM tells it otherwise,
     * which turns every Urdu name and every `—` into mojibake on a machine in
     * Karachi set to Windows-1252.
     */
    public const BOM = "\xEF\xBB\xBF";

    /** One record, CRLF-terminated. */
    public static function row(array $fields): string
    {
        return implode(',', array_map(self::field(...), $fields))."\r\n";
    }

    /** One field, quoted only where the content forces it. */
    public static function field(mixed $value): string
    {
        $field = (string) $value;

        if (preg_match('/[",\r\n]/', $field)) {
            return '"'.str_replace('"', '""', $field).'"';
        }

        return $field;
    }
}
