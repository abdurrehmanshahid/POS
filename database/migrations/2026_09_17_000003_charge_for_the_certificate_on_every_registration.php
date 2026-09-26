<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The certificate charge, as its own line on the invoice.
 *
 * The institute charges a fixed amount per course enrolled on, for the
 * certificate issued at the end. It was being collected by adding it into the
 * course fee by hand, which made `courses.fee` mean two different things and
 * put a charge nobody could see on a document a parent pays from.
 *
 * ── Why a column rather than a config lookup at render time ───────────────
 *
 * Same reason as `admission_modules.billed_amount` and `admissions.
 * billed_amount`: the rate is a price and prices change. Reading
 * `config('institute.certificate_fee')` back when printing would re-price
 * every voucher the institute has ever issued the moment the rate moved, and
 * a reprint that disagrees with the original is worse than no reprint.
 *
 * Defaults to 0, so every challan raised before today keeps totalling exactly
 * what it totalled yesterday. Nothing is backfilled — those students were not
 * charged for a certificate and inventing the charge retroactively would put
 * money on the books that nobody owes.
 *
 * ── Where it sits in the arithmetic ───────────────────────────────────────
 *
 *     net_amount = base_amount − discount_amount + certificate_amount
 *
 * OUTSIDE the discount, deliberately: the discount is negotiated on tuition,
 * and a scholarship is not a reason the institute stops paying its printer.
 * Keeping it out of `base_amount` also keeps that column meaning exactly what
 * it has always meant — the sum of what the courses cost — which is the
 * denominator `RevenueShare` apportions per-course revenue over.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('challans', function (Blueprint $table) {
            $table->unsignedInteger('certificate_amount')->default(0)->after('discount_approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('challans', function (Blueprint $table) {
            $table->dropColumn('certificate_amount');
        });
    }
};
