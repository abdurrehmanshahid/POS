<?php

namespace App\Support\Concerns;

use App\Models\Challan;
use App\Services\Installments;
use App\Support\Clock;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * The installment-plan dialog, shared by Challans and Registrations.
 *
 * A trait for the same reason {@see CollectsPayments} and
 * {@see ReversesPayments} are: both screens include
 * `partials/challan-drawer.blade.php`, so both render this markup, and a
 * second copy of anything that writes to the fee schedule is a second copy
 * that can drift.
 *
 * ── Why this exists at all ────────────────────────────────────────────────
 *
 * {@see Installments} has been able to put a challan on a two-part plan since
 * it was written, and until now nothing in the application called
 * `schedule()`. The only challans on a plan were ones the roll importer
 * created from the institute's own spreadsheet; an officer at the counter,
 * facing a parent who wanted to pay half now and half after the first month,
 * had no way to record that. The schedule existed, the ageing report read it,
 * and there was no door into it.
 *
 * ── Why only the balance is derived ───────────────────────────────────────
 *
 * The officer types the advance and the two dates; the second installment is
 * whatever is left. `schedule()` refuses a plan whose parts do not sum to
 * `net_amount` exactly, and two free-typed amounts hit that refusal on almost
 * every rounding — so the amount that could disagree with the fee is simply
 * never asked for.
 *
 * The advance has a floor of {@see Installments::minimumAdvance()}: the
 * certificate charge is incurred on enrolment, so it is collected with the
 * first installment rather than split across both.
 */
trait SchedulesInstallments
{
    public ?int $planId = null;

    public int $planAdvanceAmount = 0;

    public string $planDueFirst = '';

    public string $planDueSecond = '';

    public string $planError = '';

    /** The challans this viewer may act on. Supplied by the component. */
    abstract protected function scopedChallans(): Builder;

    public function askPlan(int $challanId): void
    {
        $challan = $this->plannableChallan($challanId);

        if (! $challan) {
            abort(403);
        }

        $existing = $challan->installments->sortBy('seq')->values();

        // Editing an existing plan starts from that plan, not from a fresh
        // half-and-half: an officer opening it to move one date by a week
        // should not have to re-derive the amounts they agreed last month.
        $this->planAdvanceAmount = $existing->isNotEmpty()
            ? (int) $existing->first()->amount
            : app(Installments::class)->defaultPlan($challan)[0]['amount'];

        $this->planDueFirst = $existing->isNotEmpty()
            ? $existing->first()->due_date->toDateString()
            : Clock::today()->copy()->addDays(7)->toDateString();

        $this->planDueSecond = $existing->count() > 1
            ? $existing->last()->due_date->toDateString()
            : Clock::today()->copy()->addDays(37)->toDateString();

        $this->planId = $challanId;
        $this->planError = '';
    }

    public function confirmPlan(): void
    {
        // The dialog is closed, so there is nothing to schedule. Same guard,
        // and the same reason, as CollectsPayments::confirmPay().
        if ($this->planId === null) {
            return;
        }

        $challan = $this->plannableChallan($this->planId);

        if (! $challan) {
            abort(403);
        }

        $net = (int) $challan->net_amount;
        $advance = (int) $this->planAdvanceAmount;
        $minimum = app(Installments::class)->minimumAdvance($challan);

        // Checked here for the message, and again inside schedule() for the
        // guarantee. An advance equal to the fee is not a plan, and one above
        // it would make the balance negative.
        if ($advance >= $net) {
            $this->planError = 'The advance must be less than the full fee of '.number_format($net).'.';

            return;
        }

        // The certificate charge is incurred on enrolment and collected at the
        // counter, so it cannot be deferred into the balance. Said as a floor
        // rather than silently topped up, because an officer who typed 5,000
        // and got a 5,700 plan would reasonably think the screen was broken.
        if ($advance < $minimum) {
            $this->planError = $challan->certificate_amount > 0
                ? 'The advance must be at least '.number_format($minimum)
                    .', the certificate charges, which are collected with the first installment.'
                : 'The advance must be more than zero.';

            return;
        }

        try {
            app(Installments::class)->schedule($challan, [
                ['amount' => $advance, 'due_date' => $this->planDueFirst],
                ['amount' => $net - $advance, 'due_date' => $this->planDueSecond],
            ]);
        } catch (Throwable $e) {
            $this->planError = $e->getMessage();

            return;
        }

        $this->planId = null;
        $this->dispatch('bbt-toast', tone: 'ok', title: 'Installment plan set',
            msg: $challan->challan_no, note: number_format($advance).' then '.number_format($net - $advance));
    }

    /**
     * Drop the schedule and go back to one payment.
     *
     * Deleting the rows is enough — `plan` and `due_date` are written back to
     * what a single-payment challan looks like, and nothing else in the system
     * reads a schedule that is not there.
     */
    public function clearPlan(int $challanId): void
    {
        $challan = $this->plannableChallan($challanId);

        if (! $challan) {
            abort(403);
        }

        $last = $challan->installments->sortBy('seq')->last();

        $challan->installments()->delete();
        $challan->update([
            'plan' => 'full',
            // Keeps the deadline the plan had arrived at, rather than
            // inventing a new one or leaving the challan with no date at all.
            'due_date' => $last?->due_date ?? $challan->due_date,
        ]);

        $this->planId = null;
        $this->dispatch('bbt-toast', tone: 'ok', title: 'Plan removed', msg: 'Payable in full.');
    }

    /**
     * The challan, if this viewer may put it on a plan.
     *
     * Scoped through the component so an officer cannot reschedule a challan
     * they cannot see, and gated on `registrations.create`.
     *
     * That key rather than a new one of its own, because the registration
     * wizard already lets anybody holding it agree a two-part plan while
     * raising the challan. A separate `challans.schedule` would be a key no
     * existing role holds — it would have to be granted by migration to every
     * role already doing this through the wizard, and until that landed the
     * same officer could set a plan on the way in but not correct it
     * afterwards. Same authority, same key.
     */
    protected function plannableChallan(int $challanId): ?Challan
    {
        if (! auth()->user()->can('registrations.create')) {
            return null;
        }

        return $this->scopedChallans()->with('installments')->find($challanId);
    }
}
