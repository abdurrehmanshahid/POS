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
        $raw = trim($raw);
        $d = preg_replace('/\D+/', '', $raw) ?? '';

        // Pakistan first, in every way the roll writes it: 03xx, 3xx, 92 3xx,
        // 0092 3xx. One canonical form out, whichever went in, because the
        // import fingerprint depends on two spellings of one number hashing the
        // same.
        $pk = $d;
        if (str_starts_with($pk, '0092')) {
            $pk = substr($pk, 4);
        } elseif (str_starts_with($pk, '92')) {
            $pk = substr($pk, 2);
        } elseif (str_starts_with($pk, '0')) {
            $pk = substr($pk, 1);
        }

        if (strlen($pk) === 10 && $pk[0] === '3') {
            return '+92 '.substr($pk, 0, 3).' '.substr($pk, 3);
        }

        // Any other country, but ONLY when it was written as international.
        // The institute's roll carries genuine students abroad — +90 (Turkey),
        // +971 (UAE), +968 (Oman) — and refusing them lost reachable people
        // over a rule about which country they happened to be in.
        //
        // The leading "+" is required rather than inferred, and that is the
        // whole safety of this branch. Without it, "0316842216" — a PK mobile
        // somebody typed one digit short — would fall through here and be
        // stored as a valid foreign number, turning a typo nobody can dial into
        // a number the system believes is fine. A missing digit must stay
        // unusable, and it does, because nobody writes a local number with a +.
        //
        // 8 to 15 digits is E.164: the shortest national numbers run to about
        // eight digits and the standard caps the whole thing at fifteen.
        if (str_starts_with($raw, '+') && strlen($d) >= 8 && strlen($d) <= 15) {
            return '+'.$d;
        }

        return null;
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
