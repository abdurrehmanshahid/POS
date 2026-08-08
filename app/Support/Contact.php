<?php

namespace App\Support;

/**
 * Pakistani phone + CNIC validation/formatting (spec §9.11).
 */
final class Contact
{
    /**
     * Normalise a PK mobile to `+92 3XX XXXXXXX`, or null if invalid.
     * Strip non-digits, drop a leading 92/0, require 10 digits starting with 3.
     */
    public static function normalizePhone(string $raw): ?string
    {
        $d = preg_replace('/\D+/', '', $raw);
        if (str_starts_with($d, '92')) {
            $d = substr($d, 2);
        } elseif (str_starts_with($d, '0')) {
            $d = substr($d, 1);
        }
        if (strlen($d) !== 10 || $d[0] !== '3') {
            return null;
        }

        return '+92 '.substr($d, 0, 3).' '.substr($d, 3);
    }

    public static function validCnic(string $cnic): bool
    {
        return (bool) preg_match('/^\d{5}-\d{7}-\d$/', $cnic);
    }

    /**
     * A blank optional detail is "not known", which is NULL, never ''.
     *
     * This matters most for the CNIC. The column is UNIQUE, and MySQL and SQLite
     * both exclude NULLs from uniqueness while treating '' as an ordinary value,
     * so storing the empty string lets the FIRST student without a CNIC save and
     * makes the SECOND one collide with them — which reads at the counter as
     * "this person is already registered" about two unrelated strangers.
     *
     * Lives here rather than in either caller because both doors that write a
     * student (the registration wizard and the Students form) have to agree, and
     * they have already disagreed once about exactly this.
     */
    public static function optional(?string $value): ?string
    {
        return ($value = trim((string) $value)) === '' ? null : $value;
    }
}
