<?php

use App\Services\PaymentReversals;
use App\Support\NetReceipts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corrections to money already recorded, as their own append-only ledger.
 *
 * Nine people take cash at a counter. Somebody will type 50,000 where they
 * meant 5,000, and until now the only way to fix it was a developer at a SQL
 * prompt — which is to say: an unaudited hand-edit of the money ledger, done
 * under time pressure, by the one person nobody is in a position to check.
 *
 * ── Why a separate table rather than a negative payment ────────────────────
 *
 * Inserting a -5,000 row into `payments` is the obvious shortcut and it is
 * wrong three times over:
 *
 *   1. `payments.amount` is an UNSIGNED INTEGER. A negative value does not
 *      fit; MySQL would reject it or, in a non-strict mode, silently clamp it
 *      to zero.
 *   2. Every per-payment surface assumes a payment is money that arrived. A
 *      receipt would print for it. `byPaymentMethod` would show a negative Cash
 *      row. `summary()['payments']` counts handovers, and a correction is not a
 *      handover.
 *   3. The audit question "was this reversed, by whom, and why" has no answer
 *      in a schema where the reversal is indistinguishable from a payment.
 *
 * So: `payments` stays append-only and stays strictly positive, this table is
 * append-only too, and every money figure in the application reports
 *
 *     gross payments − reversals = net receipts
 *
 * through a single SQL fragment in {@see NetReceipts}, for the same
 * reason apportionment lives in exactly one place: three screens ask this
 * question and the entire point is that they agree.
 *
 * ── Rules encoded here, and the ones deliberately not ──────────────────────
 *
 * Enforced in the schema: a reversal names exactly one payment, carries a
 * positive amount, a reason, and the supervisor who approved it.
 *
 * Enforced in {@see PaymentReversals}, because they need a row
 * lock rather than a constraint: cumulative reversals against one payment can
 * never exceed that payment, and a reversal cannot itself be reversed.
 *
 * NOT decided here: whether the institute permits partial reversal at all, and
 * whether a reversal should trigger a cash refund workflow. Partial is
 * *supported* because full reversal is the special case of it, but which of the
 * two the counter is actually allowed to do is a business rule the institute
 * has not yet given. See docs/DEPLOYMENT.md and decisions.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_reversals', function (Blueprint $table) {
            $table->id();

            // cascadeOnDelete matches `payments.challan_id`. It is very nearly
            // unreachable — nothing in the application deletes a payment — but
            // if a challan is ever purged, leaving reversals pointing at rows
            // that no longer exist would make every net figure unresolvable.
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();

            // Unsigned, like payments.amount, and integer PKR like every other
            // money column here. The direction is carried by the table, not by
            // the sign — which is exactly what keeps `payments` positive.
            $table->unsignedInteger('amount');

            // Required, and required for a reason: a reversal with no
            // explanation is indistinguishable from tampering when somebody
            // reviews the ledger six months later.
            $table->string('reason', 255);

            // The supervisor who authorised it. Nullable ONLY so that removing
            // a member of staff does not cascade-delete a money correction —
            // nullOnDelete keeps the reversal and loses the name, which is the
            // right trade. The application always records one.
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The lookup every money query makes.
            $table->index('payment_id');
            // "What was corrected this month" — the reconciliation question.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reversals');
    }
};
