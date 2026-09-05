<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The institute "today", and the institute's wall clock.
 *
 * STORAGE STAYS UTC AND MUST. `config('app.timezone')` is UTC and the MySQL
 * connection is pinned to `+00:00`. Changing either would re-interpret every
 * timestamp already banked, which on a financial database means moving money
 * between days — so nothing here writes, converts on the way in, or changes
 * what any query selects. This is the missing half on the way OUT: the
 * institute is in Lahore, and a report generated at 15:44 was printing "10:44"
 * because the raw UTC instant went straight to the formatter.
 *
 * The conversion is deliberately confined to DISPLAY. Day bucketing still runs
 * on {@see today()}, unchanged, because the two only disagree between 00:00 and
 * 05:00 PKT and the counter has never taken money in that window — 0 of 487
 * payments, checked against production before this was written. Moving the
 * bucket boundaries would have changed reported figures to fix nothing.
 *
 * Pakistan has observed no daylight saving since 2009, so this is a constant
 * +05:00 shift; the zone name is still used so the offset is read rather than
 * written down.
 */
final class Clock
{
    /** The institute's zone. Asia/Karachi unless INSTITUTE_TIMEZONE says otherwise. */
    public static function tz(): string
    {
        return (string) config('institute.timezone', 'Asia/Karachi');
    }

    /**
     * "PKT". Read from the zone rather than hardcoded, so it follows the config
     * and cannot drift into claiming a zone the timestamps are not in.
     */
    public static function abbreviation(): string
    {
        return Carbon::now(self::tz())->format('T');
    }

    /** Right now, on the institute's clock. For anything a person reads. */
    public static function now(): Carbon
    {
        return Carbon::now(self::tz());
    }

    /**
     * A stored instant, re-expressed as the wall-clock time the counter saw.
     *
     * Returns null for null so a nullable column can be handed over directly.
     */
    public static function local(CarbonInterface|string|null $at): ?Carbon
    {
        if ($at === null || $at === '') {
            return null;
        }

        $at = $at instanceof CarbonInterface ? $at : Carbon::parse($at);

        return $at->copy()->setTimezone(self::tz());
    }

    /**
     * The institute "today". Production uses the real today; the prototype
     * hardcodes 2026-07-15. `config('institute.today')` may pin it so the
     * running demo reproduces the prototype's figures byte-for-byte.
     *
     * A FLOATING date — 00:00 tagged UTC, the same shape a `date` cast produces
     * — because it is compared against `due_date` throughout the overdue math,
     * and comparing a zoned instant against a floating date is how off-by-one-
     * day bugs get in. Left exactly as it was.
     */
    public static function today(): Carbon
    {
        $pinned = config('institute.today');

        return $pinned ? Carbon::parse($pinned)->startOfDay() : Carbon::today();
    }
}
