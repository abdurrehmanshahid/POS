<?php

namespace App\Services;

use App\Models\Challan;
use App\Models\Installment;
use App\Models\Payment;
use App\Support\Clock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Scheduled installments on a challan (spec §2.9).
 *
 * The distinction that makes this worth having alongside `payments`:
 *
 *   - `payments` is a LEDGER. It records what actually happened: who handed
 *     over how much, when, by which method. It is append-only history.
 *   - `installments` is a SCHEDULE. It records what is *supposed* to happen:
 *     this much by this date, then the rest by that date.
 *
 * Part payments already let a student pay in stages, so for a while the
 * schedule looked redundant. It is not, and the institute's own exported roll
 * is the proof: it carries an "Advance Payment" and a "Second Installment"
 * against a "Pending Payment Due Date". Without a schedule there is no way to
 * answer "who owes me money *today*", only "who owes me money", because a
 * challan's single due_date cannot express a second deadline.
 *
 * The two are never allowed to disagree. Installment status is DERIVED from the
 * payments ledger by `reconcile()` rather than maintained alongside it, so no
 * sequence of collections, corrections or imports can leave the schedule
 * claiming something the money does not support.
 */
class Installments
{
    /**
     * Put a challan onto a two-part schedule.
     *
     * The amounts must sum to exactly `net_amount`. A schedule that does not
     * add up to the fee is worse than no schedule: it silently changes what the
     * student owes, and every ageing report downstream would inherit the error.
     *
     * @param  list<array{amount:int,due_date:string}>  $parts  1 or 2 parts, in order
     */
    public function schedule(Challan $challan, array $parts): Challan
    {
        $parts = array_values($parts);

        if ($parts === [] || count($parts) > 2) {
            throw new InvalidArgumentException('A challan carries one or two installments.');
        }

        $total = 0;
        foreach ($parts as $i => $part) {
            $amount = (int) ($part['amount'] ?? 0);
            if ($amount <= 0) {
                throw new InvalidArgumentException('Installment '.($i + 1).' must be greater than zero.');
            }
            $total += $amount;
        }

        if ($total !== (int) $challan->net_amount) {
            throw new InvalidArgumentException(
                'The installments total '.$total.' but the fee is '.$challan->net_amount.'. They must match.'
            );
        }

        // Later installments may not fall due before earlier ones. A schedule
        // out of date order makes "the next one due" meaningless, and that is
        // the single question this table exists to answer.
        for ($i = 1; $i < count($parts); $i++) {
            if (strtotime($parts[$i]['due_date']) < strtotime($parts[$i - 1]['due_date'])) {
                throw new InvalidArgumentException('Each installment must fall due on or after the one before it.');
            }
        }

        return DB::transaction(function () use ($challan, $parts) {
            // Re-scheduling replaces the plan outright. Editing rows in place
            // would leave a stale second installment behind when a two-part
            // plan becomes one-part.
            $challan->installments()->delete();

            foreach ($parts as $i => $part) {
                $challan->installments()->create([
                    'seq' => $i + 1,
                    'amount' => (int) $part['amount'],
                    'due_date' => $part['due_date'],
                    'status' => 'unpaid',
                ]);
            }

            $challan->update([
                'plan' => count($parts) > 1 ? 'split' : 'full',
                // The challan's own due date tracks the LAST installment, so
                // every existing query that reads challans.due_date keeps
                // meaning "the date by which this fee must be fully settled".
                'due_date' => end($parts)['due_date'],
            ]);

            return $this->reconcile($challan->refresh());
        });
    }

    /**
     * Re-derive installment status from the payments ledger.
     *
     * Money is applied to the schedule in `seq` order: the ledger records a
     * total handed over, not which installment the student had in mind, and
     * oldest-first is both the universal convention and the only order that
     * makes the ageing report honest.
     *
     * Idempotent by construction. It reads the ledger and writes the conclusion,
     * so running it twice, or after a correction, or after an import that
     * inserted payments directly, all converge on the same answer.
     */
    public function reconcile(Challan $challan): Challan
    {
        $schedule = $challan->installments()->orderBy('seq')->get();

        if ($schedule->isEmpty()) {
            return $challan;
        }

        // Queried rather than read off the model. `paidAmount()` answers from
        // the loaded relation when there is one, and the caller that matters
        // most, ChallanActions::recordPayment(), has just inserted a payment
        // that an already-loaded collection knows nothing about. Reconciling
        // against a stale total would leave the schedule one collection behind.
        // Both reads happen ONCE, outside the loop. This runs inside
        // recordPayment's transaction while holding a row lock on the challan,
        // and the previous version re-queried the payments table and re-summed
        // the schedule for every instalment it settled, so a two-part plan held
        // that lock across five queries instead of two.
        $payments = $challan->payments()->with('reversals')->orderBy('received_at')->orderBy('id')->get();
        // Net of reversals. An instalment settled by money that has since been
        // given back is not settled, and leaving it marked paid would hide a
        // real debt from the ageing report and the overdue query — the two
        // places the institute goes to find out who owes what.
        $unapplied = (int) $payments->sum(fn ($payment) => $payment->netAmount());

        $threshold = 0;

        foreach ($schedule as $installment) {
            $covered = $unapplied >= $installment->amount;
            $threshold += (int) $installment->amount;

            $installment->update([
                'status' => $covered ? 'paid' : 'unpaid',
                // Dated by the collection that settled it where we can tell,
                // so the schedule agrees with the ledger rather than with the
                // clock at the moment reconcile happened to run.
                'paid_at' => $covered ? ($installment->paid_at ?? $this->settledAt($payments, $threshold)) : null,
            ]);

            $unapplied = max(0, $unapplied - $installment->amount);
        }

        return $challan;
    }

    /**
     * When the cumulative ledger first reached `$threshold`.
     *
     * Walks the collections in arrival order and returns the date of the one
     * that tipped the running total past this instalment's cumulative share.
     * Falls back to now() only when the schedule was satisfied by payments that
     * predate it, which is what an import of historical money looks like.
     *
     * @param  Collection<int,Payment>  $payments
     */
    private function settledAt(Collection $payments, int $threshold): mixed
    {
        $running = 0;

        foreach ($payments as $payment) {
            $running += (int) $payment->amount;
            if ($running >= $threshold) {
                return $payment->received_at;
            }
        }

        return now();
    }

    /**
     * The default two-part plan the wizard offers: half now, half later.
     *
     * The odd rupee goes into the FIRST installment, so the remainder collected
     * at the counter on admission day is the larger one and the balance chased
     * later is the round number. `net − first` rather than a second round()
     * guarantees the two always sum to the fee exactly.
     *
     * The CERTIFICATE CHARGE is added to the first installment whole, and the
     * tuition is what gets halved. It is a cost the institute incurs on
     * enrolment rather than over the course, so splitting it across two dates
     * would mean chasing 350 rupees in October for something already paid for
     * in July. {@see minimumAdvance()} is the same rule stated as a floor, so
     * an officer editing the amounts cannot push the charge into the balance.
     *
     * @return list<array{amount:int,due_date:string}>
     */
    public function defaultPlan(Challan $challan, ?string $firstDue = null, ?string $secondDue = null): array
    {
        $net = (int) $challan->net_amount;
        $certificate = (int) $challan->certificate_amount;
        $first = $certificate + (int) ceil(($net - $certificate) / 2);

        return [
            ['amount' => $first, 'due_date' => $firstDue ?? Clock::today()->copy()->addDays(7)->toDateString()],
            ['amount' => $net - $first, 'due_date' => $secondDue ?? Clock::today()->copy()->addDays(37)->toDateString()],
        ];
    }

    /**
     * The least the first installment may be.
     *
     * The certificate charge, or one rupee on an invoice that carries none.
     * Stated here so the registration wizard and the challan drawer enforce
     * the same floor rather than each inventing one, and so the rule lives
     * beside the default plan that already honours it.
     */
    public function minimumAdvance(Challan $challan): int
    {
        return max(1, (int) $challan->certificate_amount);
    }
}
