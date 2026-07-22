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

    /** 15 Jul 2026 */
    public static function date(CarbonInterface|string|null $d): string
    {
        if ($d === null || $d === '') {
            return '';
        }
        $d = $d instanceof CarbonInterface ? $d : Carbon::parse($d);

        return $d->format('d M Y');
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
