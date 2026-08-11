<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
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
 */
class ReceiptController extends Controller
{
    public function download(Request $request, Payment $payment): Response
    {
        $payment->load([
            'receiver',
            'challan.admission.student',
            'challan.admission.course',
            'challan.admissions.course',
            'challan.payments',
        ]);

        $challan = $payment->challan;

        // The same scope rule as the voucher: an officer may only see their own
        // enrolments, and a receipt names the student, so it cannot be laxer.
        if (! $request->user()->can('challans.view')) {
            abort(403);
        }

        if (! $request->user()->can('scope.all')
            && $challan->admission->enrolled_by !== $request->user()->id) {
            abort(403);
        }

        $pdf = Pdf::loadView('receipts.pdf', [
            'payment' => $payment,
            'challan' => $challan,
            'settings' => Setting::current(),
            'balanceAfter' => $this->balanceAfter($payment),
        ])->setPaper('a5', 'landscape');

        return $pdf->download('receipt-'.$payment->receiptNo().'.pdf');
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
     */
    private function balanceAfter(Payment $payment): int
    {
        $collectedByThen = (int) $payment->challan->payments
            ->where('id', '<=', $payment->id)
            ->sum('amount');

        return max(0, $payment->challan->net_amount - $collectedByThen);
    }
}
