<?php

namespace App\Support\Concerns;

use App\Models\Payment;
use App\Services\Operations;
use App\Services\PaymentReversals;
use App\Support\Format;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * The reverse-payment dialog's behaviour, shared by Challans and Registrations.
 *
 * Written as a trait for exactly the reason {@see CollectsPayments} was: both
 * screens include `partials/challan-drawer.blade.php`, so both render the
 * markup, and behaviour duplicated across the two has already drifted once in
 * this codebase. Money is not a thing to maintain two copies of.
 *
 * The including component supplies `scopedChallans()`, so a supervisor can only
 * reverse a payment on a challan they can already see. That is the row-scope
 * half; the capability half is `payments.reverse`, checked here AND again in
 * {@see PaymentReversals::reverse()}. Two checks because this one produces a
 * good error message and that one is the guarantee.
 *
 * Requires GuardsDoubleSubmit for the token. A double-clicked reversal that
 * lands twice would take back twice the money, and unlike a double-clicked
 * payment there is no over-payment guard downstream to catch it — the second
 * one is a perfectly legal partial reversal of the remainder.
 */
trait ReversesPayments
{
    public ?int $reverseId = null;

    public int $reverseAmount = 0;

    public string $reverseReason = '';

    public string $reverseError = '';

    /** The challans this viewer may act on. Supplied by the component. */
    abstract protected function scopedChallans(): Builder;

    public function askReverse(int $paymentId): void
    {
        $payment = $this->reversiblePayment($paymentId);

        if (! $payment) {
            abort(403);
        }

        $this->reverseId = $paymentId;
        // Defaults to reversing whatever is left, which is the common case: a
        // mistyped amount is corrected in full. The supervisor edits it down
        // for a partial.
        $this->reverseAmount = $payment->amount - $payment->reversedAmount();
        $this->reverseReason = '';
        $this->reverseError = '';
        $this->freshOperationKey('reverse');
    }

    public function confirmReverse(): void
    {
        $this->reverseError = '';

        // The dialog has already closed, so there is nothing left to confirm.
        // This is what the second half of a double-click looks like on a fast
        // connection; falling through to abort(403) would render a full-screen
        // access error over a reversal that had in fact just succeeded.
        if ($this->reverseId === null) {
            return;
        }

        $payment = $this->reversiblePayment($this->reverseId);

        if (! $payment) {
            abort(403);
        }

        $amount = $this->reverseAmount;
        $reason = $this->reverseReason;

        try {
            $result = app(Operations::class)->once(
                $this->operationKey('reverse'),
                'payment.reverse',
                fn () => app(PaymentReversals::class)->reverse($payment, auth()->user(), $amount, $reason),
            );
        } catch (Throwable $e) {
            // Shown inline in the dialog rather than as a toast: the supervisor
            // is mid-decision and needs to correct the amount or the reason
            // without the form closing under them.
            $this->reverseError = $e->getMessage();

            return;
        }

        $this->reverseId = null;
        $this->freshOperationKey('reverse');

        if ($result->replayed) {
            // Report the ledger, not the request — the same rule confirmPay
            // follows. Nothing was written this time, so echoing the requested
            // amount back would assert a reversal this request did not make.
            $this->dispatch('bbt-toast',
                tone: 'ok',
                title: 'Already reversed',
                msg: 'Receipt '.$payment->receiptNo().' · '
                    .Format::money($payment->fresh()->reversedAmount()).' reversed in total',
                note: 'Duplicate submission ignored',
            );

            return;
        }

        // `warn`, not `ok`. Money going back out is not a routine success and
        // the toast should not look like one.
        $this->dispatch('bbt-toast',
            tone: 'warn',
            title: 'Payment reversed',
            msg: 'Receipt '.$payment->receiptNo().' · '.Format::money($amount).' · '.$reason,
        );
    }

    /**
     * The payment, if this viewer may reverse it.
     *
     * Both halves of the question at once: does the user hold
     * `payments.reverse`, and is the payment on a challan inside their scope.
     * Returning null for either means the caller cannot accidentally check one
     * and forget the other.
     */
    private function reversiblePayment(int $paymentId): ?Payment
    {
        if (! auth()->user()?->can('payments.reverse')) {
            return null;
        }

        return Payment::query()
            ->with('reversals')
            ->whereIn('challan_id', $this->scopedChallans()->select('challans.id'))
            ->find($paymentId);
    }
}
