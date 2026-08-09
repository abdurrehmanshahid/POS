<?php

namespace App\Support\Concerns;

use App\Services\ChallanActions;
use App\Services\Operations;
use App\Support\Format;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * The mark-paid dialog's behaviour, shared by Challans and Registrations.
 *
 * Both screens already share the dialog's markup through
 * `partials/challan-drawer.blade.php`; only the behaviour behind it was
 * duplicated, and it had already drifted — one screen cached `$settled` before
 * building its toast and the other re-read it, and the explanatory comments
 * were full in one file and abbreviated in the other. Collecting money is not a
 * thing to maintain two copies of.
 *
 * The including component supplies `scopedChallans()`, because the two screens
 * legitimately scope differently: Challans goes through `Ledger` (which
 * excludes cancelled admissions from the money screens) while Registrations
 * lists everything it can see.
 *
 * Requires GuardsDoubleSubmit for the token.
 */
trait CollectsPayments
{
    public ?int $payId = null;

    public string $payMethod = '';

    public int $payAmount = 0;

    /** The challans this viewer may collect against. */
    abstract protected function scopedChallans(): Builder;

    public function askPay(int $id): void
    {
        $this->payId = $id;
        $this->payMethod = '';
        // Defaults to settling in full, which is the common case; the officer
        // edits it down when the student is paying an advance.
        $this->payAmount = (int) ($this->scopedChallans()->find($id)?->balance() ?? 0);
        // One token per opened dialog. Both halves of a double-click carry it;
        // a genuine second payment reopens the dialog and gets a new one.
        $this->freshOperationKey('pay');
    }

    public function confirmPay(): void
    {
        // The dialog has already closed, so there is nothing left to confirm.
        // This is precisely what the second half of a double-click looks like
        // on a fast connection, and it used to fall through to abort(403) —
        // which Livewire renders as a full-screen "You do not have access to
        // this screen" on top of a payment that had in fact just succeeded.
        // Nothing was wrong with the officer's permissions and nothing was
        // wrong with the payment, so the only honest response is to do nothing.
        if ($this->payId === null) {
            return;
        }

        $challan = $this->scopedChallans()->find($this->payId);
        // Still a genuine 403: an id was submitted for a challan this user
        // cannot see, which is a scope violation rather than a stray click.
        if (! $challan || ! auth()->user()->can('challans.pay')) {
            abort(403);
        }

        // Read before the guard runs, because on a replay the work does not run
        // and the toast still has to describe what the officer asked for.
        $amount = $this->payAmount;
        $method = $this->payMethod;

        try {
            $result = app(Operations::class)->once($this->operationKey('pay'), 'payment.record',
                fn () => app(ChallanActions::class)->recordPayment($challan, auth()->user(), $amount, $method));
        } catch (Throwable $e) {
            $this->dispatch('bbt-toast', tone: 'err', title: 'Could not record payment', msg: $e->getMessage());

            return;
        }

        $this->payId = null;
        // A new token, so the next collection from this drawer is its own
        // operation and cannot be mistaken for a repeat of this one.
        $this->freshOperationKey('pay');

        $fresh = $challan->fresh();

        if ($result->replayed) {
            // Report the ledger, not the request.
            //
            // Nothing was written this time, so echoing `$amount` and `$method`
            // back would assert a collection this request did not make — and
            // since the token is client-supplied, that made the toast a way to
            // display "Rs 20,000 · Cash received" over an empty ledger. What is
            // true on a replay is only what the challan already holds.
            $this->dispatch('bbt-toast',
                tone: 'ok',
                title: $fresh->isPaid() ? 'Already settled' : 'Already recorded',
                msg: $fresh->challan_no.' · '.Format::money($fresh->paidAmount()).' collected'
                    .($fresh->isPaid() ? '' : ' · '.Format::money($fresh->balance()).' still due'),
                note: 'Duplicate submission ignored',
            );

            return;
        }

        $this->dispatch('bbt-toast',
            tone: 'ok',
            title: $fresh->isPaid() ? 'Payment recorded' : 'Part payment received',
            msg: $challan->challan_no.' · '.Format::money($amount).' · '.$method,
        );
    }
}
