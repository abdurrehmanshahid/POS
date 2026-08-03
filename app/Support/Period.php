<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * A reporting window.
 *
 * The prototype's period selector was decorative: three options that changed
 * nothing. This resolves a preset key (or an explicit from/to pair) into a real
 * pair of dates that every figure on the Reports screen is filtered by, so the
 * control means what it says.
 *
 * All windows are inclusive of both ends and anchored to {@see Clock::today()},
 * which is the pinned demo date when INSTITUTE_TODAY is set and the real today
 * otherwise. Anchoring to Clock rather than now() is what keeps the demo's
 * figures reproducible.
 */
final class Period
{
    /** Preset key => label, in the order they render. */
    public const PRESETS = [
        'today' => 'Today',
        'week' => 'This week',
        'month' => 'This month',
        'quarter' => 'This quarter',
        'year' => 'This year',
        'custom' => 'Custom',
    ];

    public function __construct(
        public readonly string $key,
        public readonly Carbon $from,
        public readonly Carbon $to,
    ) {}

    /**
     * Resolve a preset, falling back to "this month" for anything unknown.
     *
     * `custom` needs both ends; if either is missing or the range is inverted
     * we fall back rather than throwing, because a half-typed date in a live
     * filter box should not blow up the page.
     */
    public static function resolve(string $key, ?string $from = null, ?string $to = null): self
    {
        $today = Clock::today();

        if ($key === 'custom') {
            $start = self::parse($from);
            $end = self::parse($to);

            if ($start && $end && $start->lessThanOrEqualTo($end)) {
                return new self('custom', $start->startOfDay(), $end->endOfDay());
            }

            $key = 'month';
        }

        [$start, $end] = match ($key) {
            'today' => [$today->copy(), $today->copy()],
            // Monday-anchored: the institute's week, not the US Sunday week.
            'week' => [$today->copy()->startOfWeek(Carbon::MONDAY), $today->copy()->endOfWeek(Carbon::SUNDAY)],
            'quarter' => [$today->copy()->startOfQuarter(), $today->copy()->endOfQuarter()],
            'year' => [$today->copy()->startOfYear(), $today->copy()->endOfYear()],
            default => [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()],
        };

        return new self(
            array_key_exists($key, self::PRESETS) ? $key : 'month',
            $start->startOfDay(),
            $end->endOfDay(),
        );
    }

    private static function parse(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    public function label(): string
    {
        return self::PRESETS[$this->key] ?? 'This month';
    }

    /** Human-readable resolved range, shown so the filter is never ambiguous. */
    public function rangeLabel(): string
    {
        if ($this->from->isSameDay($this->to)) {
            return Format::date($this->from);
        }

        return Format::date($this->from).' to '.Format::date($this->to);
    }

    /**
     * Whole days covered, used to decide chart granularity.
     *
     * Compared start-of-day to start-of-day. `to` is held at 23:59:59 so the
     * window includes its last day, and Carbon 3 returns a float from
     * diffInDays(), so the old `diffInDays($this->to) + 1` handed 365.9999 to an
     * `int` return type. The value happened to truncate correctly, but every
     * Reports page load emitted "Implicit conversion from float ... loses
     * precision", which a later PHP promotes from a deprecation to an error.
     */
    public function days(): int
    {
        return (int) $this->from->copy()->startOfDay()
            ->diffInDays($this->to->copy()->startOfDay()) + 1;
    }

    /**
     * Group daily buckets by week or month once a day-by-day chart would be
     * unreadable. A year of daily bars is 365 slivers nobody can read.
     */
    public function granularity(): string
    {
        return match (true) {
            $this->days() <= 31 => 'day',
            $this->days() <= 120 => 'week',
            default => 'month',
        };
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
        ];
    }
}
