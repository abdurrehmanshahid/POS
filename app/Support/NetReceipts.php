<?php

namespace App\Support;

/**
 * What a payment is actually worth after corrections.
 *
 *     net receipts = gross payments − reversals
 *
 * Single-sourced here for the same reason {@see RevenueShare} exists: this
 * question is asked by the staff dashboard, the Reports screen, the owner
 * console, the course drawer and every challan row, and the entire point of
 * the exercise is that they agree. This codebase has already been burned twice
 * by the same money figure being computed in two places and drifting — once
 * when Reports read the paid flag while the dashboard summed payments, and
 * once when three screens joined the invoice through different columns.
 *
 * If you are adding a money figure and you are about to write
 * `SUM(payments.amount)`, you want {@see sum()} instead. A figure that misses
 * reversals OVERSTATES net receipts, which is the direction that makes a
 * cashier look like a thief.
 *
 * ── Why a correlated subquery rather than a join ──────────────────────────
 *
 * A LEFT JOIN onto `payment_reversals` would multiply the payment row once per
 * reversal, so a payment corrected twice would be counted twice in
 * `SUM(payments.amount)` and every gross total in the application would inflate
 * silently. Fixing that with DISTINCT is not possible on a sum, and fixing it
 * with a derived table means every caller has to remember to join it.
 *
 * The subquery is correlated on an indexed column (`payment_reversals.payment_id`)
 * against a table that will hold a handful of rows for a long time — 450
 * students and nine staff. Correctness first; measure before optimising.
 */
final class NetReceipts
{
    /**
     * One payment's amount, net of everything reversed against it.
     *
     * Assumes `payments` is in the query and reachable as `payments`.
     *
     * COALESCE covers the ordinary case: almost every payment has no reversals
     * at all, and the subquery returns NULL rather than 0 for them. Without it,
     * `amount - NULL` is NULL, and SUM would skip the row entirely — so an
     * uncorrected ledger would report zero collected. The failure is total
     * rather than subtle, which is at least merciful, but it is worth naming.
     */
    public static function ofPayment(): string
    {
        return '(payments.amount - COALESCE((SELECT SUM(pr.amount) FROM payment_reversals pr'
            .' WHERE pr.payment_id = payments.id), 0))';
    }

    /** Summed across the rows of a grouped or aggregate query. */
    public static function sum(): string
    {
        return 'SUM('.self::ofPayment().')';
    }

    /**
     * Total reversed against one payment, for the same query shape.
     *
     * Used where a screen shows the correction alongside the original rather
     * than only the net — the counter needs to see THAT a correction happened,
     * not just a smaller number it cannot explain to a parent.
     */
    public static function reversedOfPayment(): string
    {
        return 'COALESCE((SELECT SUM(pr.amount) FROM payment_reversals pr'
            .' WHERE pr.payment_id = payments.id), 0)';
    }
}
