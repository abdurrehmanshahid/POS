<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\Payment;
use App\Models\PaymentReversal;
use App\Models\User;
use App\Support\Format;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reverse money already recorded, without ever editing money already recorded.
 *
 * This is the counterpart to {@see ChallanActions::recordPayment()} and it is
 * deliberately built the same way: one transaction, a locked row, guards read
 * INSIDE the lock, and an immutable audit entry. Two supervisors reversing the
 * same payment at the same moment is exactly as plausible as two officers
 * collecting on the same challan, and the failure is worse — the second one
 * would take the ledger negative.
 *
 * ── The rules this enforces, and where they came from ─────────────────────
 *
 *  1. **Cumulative reversals never exceed the payment.** Otherwise the
 *     institute reports having given back more than it took, and
 *     `NetReceipts` goes negative, and every "collected" figure on every screen
 *     silently becomes wrong in the direction that is hardest to notice.
 *
 *  2. **A reversal cannot be reversed.** There is no un-reverse. If a
 *     supervisor reverses in error, the correction is a fresh payment through
 *     the ordinary counter flow — which leaves both facts on the record, which
 *     is the whole reason this ledger is append-only.
 *
 *  3. **Reversing reopens the challan.** `challans.status` is a denormalised
 *     flag meaning "balance has reached zero", and after a reversal it has not.
 *     Leaving it on `paid` would hide a genuine debt from the overdue query,
 *     the ageing report and the status pill — the student would owe money that
 *     no screen in the system asked for.
 *
 *  4. **The instalment schedule is re-derived.** {@see Installments::reconcile()}
 *     is documented as idempotent and as reading the ledger to write the
 *     conclusion, so it is the right call here for the same reason
 *     recordPayment makes it: an instalment marked paid by money that has since
 *     been given back is not paid.
 *
 * ── What is NOT decided here ──────────────────────────────────────────────
 *
 * Partial reversal is SUPPORTED, because full reversal is the special case of
 * it and building only the special case would mean rebuilding this the first
 * time somebody types 50,000 instead of 5,000 — which is the exact scenario
 * that justified the feature. Whether the institute PERMITS a partial reversal,
 * and whether reversing implies handing cash back across the counter today or
 * crediting it against the next instalment, are business rules nobody has given
 * yet. They are listed as blockers rather than guessed at.
 */
class PaymentReversals
{
    public function __construct(private Installments $installments) {}

    /**
     * Record a reversal against one payment.
     *
     * @param  int  $amount  positive PKR; may be less than the payment (partial)
     *
     * @throws RuntimeException on any rule violation, with a message safe to show a supervisor
     */
    public function reverse(Payment $payment, User $actor, int $amount, string $reason): PaymentReversal
    {
        // Checked before the transaction because it is a property of the
        // caller, not of the row, and a 403 should not open a transaction.
        if (! $actor->hasPermission('payments.reverse')) {
            throw new RuntimeException('You do not have permission to reverse a payment.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            // Required, and not merely for tidiness: a reversal with no stated
            // cause is indistinguishable from tampering to whoever reviews the
            // ledger in six months, and that person may be an auditor.
            throw new RuntimeException('A reason is required to reverse a payment.');
        }

        if ($amount <= 0) {
            throw new RuntimeException('A reversal must be greater than zero.');
        }

        return DB::transaction(function () use ($payment, $actor, $amount, $reason) {
            // Locked and re-read inside the transaction. The already-reversed
            // total is the value two concurrent supervisors would both read as
            // stale, and both would then pass the cap check below.
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->getKey());

            $already = (int) PaymentReversal::query()->where('payment_id', $locked->id)->sum('amount');
            $reversible = $locked->amount - $already;

            if ($reversible <= 0) {
                throw new RuntimeException('This payment has already been reversed in full.');
            }

            if ($amount > $reversible) {
                // Refused rather than clamped, mirroring recordPayment's
                // over-payment guard. Somebody asking to reverse more than
                // remains has misread something, and quietly reversing a
                // different number than they typed is how a correction becomes
                // a second error nobody notices.
                throw new RuntimeException(
                    'That is more than the '.Format::money($reversible).' still reversible on this payment.'
                );
            }

            $reversal = PaymentReversal::create([
                'payment_id' => $locked->id,
                'amount' => $amount,
                'reason' => $reason,
                'approved_by' => $actor->id,
            ]);

            $challan = $locked->challan()->lockForUpdate()->first();

            if ($challan) {
                // The flag means "balance has reached zero", and after this it
                // has not. Reopening it puts the debt back into the overdue
                // query, the ageing buckets and the status pill, all of which
                // read the flag rather than recomputing.
                //
                // `balance()` is queried fresh through refresh(): the loaded
                // payments relation, if there is one, knows nothing about the
                // reversal just written.
                $challan->refresh();

                if ($challan->balance() > 0 && $challan->status === 'paid') {
                    $challan->update(['status' => 'unpaid', 'paid_at' => null]);
                }

                // Idempotent, and reads the ledger to write the conclusion, so
                // an instalment settled by money since given back stops being
                // settled.
                $this->installments->reconcile($challan);

                Audit::record('Payment reversed', $actor, [
                    'challan_id' => $challan->id,
                    'subject' => $challan,
                    'subject_label' => $challan->challan_no,
                    'field' => 'paid_amount',
                    'old_value' => (string) ($challan->paidAmount() + $amount),
                    'new_value' => (string) $challan->paidAmount(),
                    'context' => [
                        'payment_id' => $locked->id,
                        'receipt_no' => $locked->receiptNo(),
                        'reversed' => $amount,
                        'payment_gross' => $locked->amount,
                        'reason' => $reason,
                    ],
                ]);

                // The counter has to be able to SEE that a correction happened.
                // A number that quietly got smaller is the thing a cashier
                // cannot explain to a parent standing in front of them.
                AppNotification::create([
                    'type' => 'payment',
                    'title' => 'Payment reversed',
                    'sub' => $challan->student->name.', '.Format::money($amount)
                        .' reversed on receipt '.$locked->receiptNo().' — '.$reason,
                    'student_id' => $challan->student_id,
                    'challan_id' => $challan->id,
                    'is_revenue' => true,
                ]);
            }

            return $reversal->refresh();
        });
    }
}
