<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Setting;
use App\Support\DocumentResponse;
use App\Support\Download;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Proof that a particular collection happened (GAP-05).
 *
 * The challan voucher and this are different documents and it is worth being
 * precise about how, because the institute had only the first and it does not do
 * this job. A voucher is a DEMAND, produced before any money moves, and it says
 * what is owed. A receipt is EVIDENCE, produced after, and it says what was
 * handed over. A student paying an advance used to leave the counter with
 * nothing that recorded it.
 *
 * Built over the `payments` rows that already exist. No new table, no new
 * column, and nothing is written when a receipt is printed — a receipt that
 * mutated the ledger it reports on would be a worse problem than not having one.
 *
 * Three routes over one renderer, exactly as {@see ChallanController} sets out.
 */
class ReceiptController extends Controller
{
    public function view(Request $request, Payment $payment): Response
    {
        // No eager load, for the reason set out in ChallanController::view().
        $this->assertVisible($request, $payment);

        return DocumentResponse::viewer(
            'Receipt '.$payment->receiptNo().' · '.$payment->challan->student?->name,
            route('payments.receipt.stream', $payment),
            route('payments.receipt', $payment),
        );
    }

    public function stream(Request $request, Payment $payment): Response
    {
        return Download::inline($this->render($request, $payment), $this->filename($payment));
    }

    public function download(Request $request, Payment $payment): Response
    {
        return Download::named($this->render($request, $payment), $this->filename($payment));
    }

    private function filename(Payment $payment): string
    {
        return 'receipt-'.$payment->receiptNo().'.pdf';
    }

    private function render(Request $request, Payment $payment): Response
    {
        $payment->load([
            'receiver',
            'challan.student',
            'challan.raiser',
            'challan.admission.student',
            'challan.admission.course',
            'challan.admissions.course',
            'challan.payments.reversals',
            'reversals.approver',
        ]);

        $this->assertVisible($request, $payment);

        return DocumentResponse::pdf('receipts.pdf', [
            'payment' => $payment,
            'challan' => $payment->challan,
            'settings' => Setting::current(),
            'balanceAfter' => $this->balanceAfter($payment),
            // How much of THIS collection has since been given back. Passed
            // separately rather than folded into the amount, because a receipt
            // whose figure silently shrank would be the worst of both worlds:
            // it would contradict the copy the student is holding AND give no
            // hint why. The template stamps it instead.
            'reversed' => $payment->reversedAmount(),
        ], 'a5');
    }

    /**
     * The same row-scope rule the voucher uses, asked the same way.
     *
     * Only the row scope. The `challans.view` CAPABILITY is the route group's
     * job and every one of these six routes carries it — re-asking it here was
     * a second answer to a question already settled, and one the challan's
     * controller did not ask, so the two had begun to disagree about what this
     * method is for.
     *
     * Asked of the invoice, so a receipt for a non-course charge is a
     * permission decision rather than a server error. The officer who collected
     * the money is the one who may reprint the proof of it.
     */
    private function assertVisible(Request $request, Payment $payment): void
    {
        if (! $payment->challan->isVisibleTo($request->user())) {
            abort(403);
        }
    }

    /**
     * What was left owing at the moment this payment was taken.
     *
     * Reconstructed from the payments up to and including this one, NEVER from
     * the challan's balance today. This is the single most important line in the
     * class: a student who pays Rs 10,000 of Rs 25,000 is handed a receipt
     * saying Rs 15,000 remains, and if they pay the rest next week and anyone
     * reprints the first receipt, it must still say Rs 15,000. Recomputing from
     * today's ledger would reprint it as Rs 0 and contradict the copy the
     * student is holding — two documents, one payment, different facts.
     *
     * Ordered by `id` rather than `received_at`, because ids are unique and
     * monotonic while two collections can share a timestamp to the second. On
     * imported history the received_at values are backdated, and id order is
     * still the order the rows were written.
     *
     * ── Reversals, and why they are filtered by date ──────────────────────
     *
     * Net of reversals, but only of reversals that already EXISTED when this
     * payment was taken. Both halves of that matter and they pull in opposite
     * directions:
     *
     *   Counting all reversals would rewrite history. Reverse payment #1 today
     *   and last month's receipt #1 reprints with a different balance from the
     *   copy in the student's file — the exact contradiction the paragraph
     *   above exists to prevent.
     *
     *   Counting none would understate the balance on every LATER receipt.
     *   Reverse #1, then take #2, and receipt #2 would credit the student for
     *   money the institute handed back, telling them they owe less than they
     *   do. That is the direction that loses the institute money and gets
     *   discovered by an argument at the counter.
     *
     * Filtering on `created_at <= this payment's created_at` gives the ledger
     * as it stood at the moment of this collection, which is what the receipt
     * is a record of. A reversal of THIS payment is stamped on the document
     * separately (see `reversed` in render()) rather than folded into the sum.
     */
    private function balanceAfter(Payment $payment): int
    {
        $asOf = $payment->created_at;

        $collectedByThen = (int) $payment->challan->payments
            ->where('id', '<=', $payment->id)
            ->sum(fn (Payment $earlier) => $earlier->amount - (int) $earlier->reversals
                ->filter(fn ($reversal) => $asOf !== null && $reversal->created_at <= $asOf)
                ->sum('amount'));

        return max(0, $payment->challan->net_amount - $collectedByThen);
    }
}
