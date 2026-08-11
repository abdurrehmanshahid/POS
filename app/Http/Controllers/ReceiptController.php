<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Setting;
use App\Support\Download;
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
    /**
     * The receipt as a page, with a Print button.
     *
     * HTML rather than an inline PDF, for the reason set out in
     * {@see ChallanController::view()}: "inline" is a
     * request the browser may decline, and a receipt the student is standing
     * there waiting for is the worst place to discover that.
     */
    public function view(Request $request, Payment $payment): Response
    {
        $payment = $this->authorised($request, $payment);

        return response()->view('receipts.pdf', [
            'payment' => $payment,
            'challan' => $payment->challan,
            'settings' => Setting::current(),
            'balanceAfter' => $this->balanceAfter($payment),
            'forScreen' => true,
        ]);
    }

    /** Save the file, for sending on. */
    public function download(Request $request, Payment $payment): Response
    {
        $payment = $this->authorised($request, $payment);

        $name = 'receipt-'.$payment->receiptNo().'.pdf';

        return Download::named(
            Pdf::loadView('receipts.pdf', [
                'payment' => $payment,
                'challan' => $payment->challan,
                'settings' => Setting::current(),
                'balanceAfter' => $this->balanceAfter($payment),
            ])->setPaper('a5', 'landscape')->download($name),
            $name,
        );
    }

    private function authorised(Request $request, Payment $payment): Payment
    {
        $payment->load([
            'receiver',
            'challan.student',
            'challan.raiser',
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

        // Asked of the invoice, so a receipt for a non-course charge is a
        // permission decision rather than a server error. The officer who
        // collected the money is the one who may reprint the proof of it.
        if (! $challan->isVisibleTo($request->user())) {
            abort(403);
        }

        return $payment;
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
