<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The institute "today". Production uses now() (spec §7.8 / §17.5); the prototype
 * hardcodes 2026-07-15. `config('institute.today')` may pin it so the running
 * demo reproduces the prototype's figures byte-for-byte; unset → real today.
 * Centralised so overdue math has one source and tests can freeze it.
 */
final class Clock
{
    public static function today(): Carbon
    {
        $pinned = config('institute.today');

        return $pinned ? Carbon::parse($pinned)->startOfDay() : Carbon::today();
    }
}
