<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Display formatters that mirror the prototype exactly (spec §0).
 * Money: whole rupees, en-US grouping, `Rs ` prefix. Dates: `dd Mon yyyy`.
 */
final class Format
{
    /** Rs 1,234,567, whole rupees, no decimals. */
    public static function money(int|float|null $n): string
    {
        return 'Rs '.number_format((int) round((float) ($n ?? 0)));
    }

    /**
     * 15 Jul 2026, on the institute's clock.
     *
     * Timestamps are stored in UTC (see {@see Clock}) and the institute is in
     * Lahore, five hours ahead, so an instant is converted before it is
     * formatted. Without that, anything recorded after 19:00 UTC — which is
     * after midnight in Karachi — printed the previous day's date.
     *
     * Safe for `date` casts too, which arrive as 00:00 UTC and land on 05:00
     * the same morning: the offset is positive, so a floating date can never
     * be carried backwards over a midnight.
     */
    public static function date(CarbonInterface|string|null $d): string
    {
        return Clock::local($d)?->format('d M Y') ?? '';
    }

    /**
     * 15 Jul 2026, for a value that is ALREADY a calendar date.
     *
     * The distinction from {@see date()} matters in exactly one situation and
     * it is a trap worth naming. A reporting window holds its end as the
     * sentinel 23:59:59, which is not an instant anybody experienced — it is
     * "the last moment of the 30th". Converting it forward five hours rolls it
     * into the 1st, and "01 Jul to 30 Sep" started printing as "01 Jul to
     * 01 Oct": a quarter apparently one day longer than a quarter.
     *
     * Use this for a window edge or any other date the institute has already
     * stated in its own terms. Use {@see date()} for a moment that happened —
     * a payment, a login, a row being created.
     */
    public static function calendarDate(CarbonInterface|string|null $d): string
    {
        if ($d === null || $d === '') {
            return '';
        }

        return ($d instanceof CarbonInterface ? $d : Carbon::parse($d))->format('d M Y');
    }

    /**
     * 15 Jul 2026 15:44 — 24-hour, on the institute's clock.
     *
     * The format is a parameter because the activity log runs a two-digit year
     * to keep its column narrow, and one converted-then-formatted path is
     * better than two places each remembering to convert.
     */
    public static function dateTime(CarbonInterface|string|null $d, string $format = 'd M Y H:i'): string
    {
        return Clock::local($d)?->format($format) ?? '';
    }

    /**
     * "PKT", for documents that leave the building.
     *
     * On screen the zone is not worth the pixels — everyone reading it is in
     * the same room as the clock on the wall. An exported report is read
     * somewhere else, possibly months later, so it says which zone it means.
     */
    public static function zone(): string
    {
        return Clock::abbreviation();
    }

    /** Two-letter initials for avatars. */
    public static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $parts = array_values(array_filter($parts));
        if (count($parts) === 0) {
            return '?';
        }
        if (count($parts) === 1) {
            return strtoupper(substr($parts[0], 0, 2));
        }

        return strtoupper(substr($parts[0], 0, 1).substr(end($parts), 0, 1));
    }
}
