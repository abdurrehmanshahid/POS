<?php

namespace App\Support;

/**
 * How much of a payment belongs to one course on its invoice.
 *
 * One invoice can bill several courses, so "revenue for this course" is no
 * longer simply the payments against its challan. Each collection is split
 * across the courses on the invoice in proportion to what each was billed.
 *
 * Single-sourced because three screens ask this question, and the whole point
 * of the exercise is that they agree: the Reports screen, the staff dashboard
 * and the owner console previously disagreed about the same money, which is
 * the exact defect class this codebase has already been burned by twice.
 *
 * The SQL assumes `payments`, `challans` and `admissions` are all in the query,
 * with admissions joined through `admissions.challan_id = challans.id` so that
 * every course on the invoice is reached, not only the anchor.
 */
final class RevenueShare
{
    /**
     * A single payment's share of one course.
     *
     * `* 1.0` is load-bearing. Every operand here is an integer column, and
     * SQLite's `/` on two integers is integer division, so without it each
     * course's share was silently truncated and the per-course totals came out
     * lower than the money actually banked: three courses splitting a 25,000
     * collection reported 24,999. Forcing float division and rounding keeps
     * the shares faithful to the rupee.
     *
     * COALESCE covers enrolments that predate grouped invoicing and carry no
     * recorded share; their invoice only ever billed them, so it is theirs in
     * full. NULLIF guards a zero-fee invoice, which is legitimate for a full
     * scholarship and would otherwise divide by zero and take the whole report
     * down with it.
     */
    public static function ofPayment(): string
    {
        return self::share('payments.amount');
    }

    /** Summed across the rows of a grouped query. */
    public static function sumOfPayments(): string
    {
        return 'SUM('.self::ofPayment().')';
    }

    /**
     * The share of an invoice's NET that one course carries.
     *
     * Used for "billed by course", where the discount is negotiated over the
     * invoice as a whole and has to be spread the same way the fee was.
     */
    public static function sumOfBilled(): string
    {
        return 'SUM('.self::share('challans.net_amount').')';
    }

    /**
     * The apportionment itself, applied to whichever column is being split.
     *
     * Written once. Every subtlety above has to hold for both the payment and
     * the billed variants, and stating the formula twice meant fixing each of
     * them twice.
     */
    private static function share(string $amount): string
    {
        return 'ROUND('.$amount.' * COALESCE(admissions.billed_amount, challans.base_amount) * 1.0'
            .' / NULLIF('.self::liveTotal().', 0))';
    }

    /**
     * The denominator: what the invoice bills across the courses STILL ON IT.
     *
     * Not `challans.base_amount`, and the difference is not academic. Every
     * query that apportions filters its rows to live enrolments, so using the
     * full base as the denominator meant the shares stopped summing to the
     * invoice the moment a course was cancelled — and cancelling before any
     * money is collected is explicitly permitted.
     *
     * Two courses at 20,000 on one 40,000 invoice discounted to 36,000: cancel
     * one, collect the 36,000, and the Reports screen showed "Collected
     * 36,000" beside a revenue-by-course table totalling 18,000. Two figures on
     * one screen, 18,000 apart, which is exactly the disagreement this class
     * exists to prevent.
     *
     * Renormalising over the live enrolments is also the right answer in
     * business terms: the student is paying for the courses they are actually
     * taking, so those are the courses that earned it.
     *
     * Falls back to `base_amount` when nothing is live, which keeps a fully
     * cancelled invoice from dividing by zero.
     */
    private static function liveTotal(): string
    {
        return 'COALESCE((SELECT SUM(live.billed_amount) FROM admissions live'
            ." WHERE live.challan_id = challans.id AND live.status <> 'cancelled'), challans.base_amount)";
    }

    /**
     * The same rule in PHP, for the per-course figures rendered in the UI.
     *
     * Lives here rather than on the model so the scalar and the SQL cannot
     * drift. They already had: this returned the invoice in full on a zero-fee
     * invoice while `NULLIF(base_amount, 0)` made the SQL contribute nothing,
     * so a full scholarship showed the whole fee against every course in the
     * student drawer and zero in every report.
     *
     * A zero-fee invoice has nothing to apportion, so the share is zero, and
     * both now say so.
     */
    public static function of(int $amount, ?int $billed, int $base): int
    {
        if ($base <= 0) {
            return 0;
        }

        return (int) round($amount * ($billed ?? $base) / $base);
    }
}
