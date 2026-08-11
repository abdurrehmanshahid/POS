<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How the institute EXPECTS this invoice to be settled.
 *
 * The voucher has always printed a "Payment Method" line, and it has always
 * been blank on a freshly issued challan — because the only method the system
 * knew was `paid_via`, which is written when money actually arrives. So the one
 * field on the document that tells a parent HOW to pay was empty on every
 * voucher handed over before payment, which is every voucher that matters: a
 * demand for money that does not say how to hand it over.
 *
 * Two columns, not one, and the distinction is the point:
 *
 *   payment_method  what was AGREED at the counter. Set when the challan is
 *                   raised, printed on the voucher, and never touched again.
 *   paid_via        what actually HAPPENED. Set when the money lands.
 *
 * Collapsing them would lose the difference between "we asked for a bank
 * transfer" and "they paid cash", which is exactly the discrepancy anyone
 * reconciling a day's takings is looking for.
 *
 * Nullable, because it is genuinely optional: an officer who does not know yet
 * leaves it blank and the voucher prints a dash, which is honest. And every
 * challan already in the database predates the column — filling them with a
 * guess would be inventing an agreement nobody made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('challans', function (Blueprint $table) {
            // A string rather than an enum: the list lives in
            // `config('institute.payment_methods')`, where the institute can add
            // "Easypaisa" without a migration. `ChallanActions` and the wizard
            // both validate against that config, so the constraint is enforced
            // where the value enters rather than where it is stored.
            $table->string('payment_method', 40)->nullable()->after('plan');
        });
    }

    public function down(): void
    {
        Schema::table('challans', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });
    }
};
