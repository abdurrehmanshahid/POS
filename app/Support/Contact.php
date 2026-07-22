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
}
