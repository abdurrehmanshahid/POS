<?php

namespace App\Services;

use App\Models\Challan;
use App\Models\PaymentReversal;
use App\Models\SuperAdmin;
use App\Support\Format;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The super admin's correction of what a challan bills.
 *
 * Staff cannot do this: once issued, a challan's amounts only ever change
 * through a discount at registration. The super admin owns the platform and is
 * the one who fixes a fee that was billed wrong, so the edit lives on their
 * side, behind a password re-entry and a reason (see the panel's Fee challans
 * screen). It can also correct the money collected so far when a payment was
 * typed wrong; see correctCollected() for how that stays append-only.
 *
 * Net is still derived (base − discount + certificate), never typed. Every
 * other figure that hangs off the amounts is brought back into line in the
 * same transaction, so an edit cannot break what the rest of the system
 * assumes about a challan:
 *
 *   - Σ billed_amount of its live enrolments === base_amount (RevenueShare);
 *   - its installments sum to net_amount (Installments::schedule());
 *   - `status` is `paid` exactly when the money collected covers net.
 */
class ChallanEdits
{
    public function __construct(private Installments $installments) {}

    /**
     * `collected` is optional: the money collected so far as it should read,
     * when it was recorded wrong (69,750 typed for 70,000). Left out, the
     * collections are not touched.
     *
     * @param  array{base_amount:int, discount_amount:int, certificate_amount:int, collected?:int|null}  $amounts
     */
    public function edit(Challan $challan, SuperAdmin $actor, array $amounts, string $reason): Challan
    {
        $reason = trim($reason);
        $base = (int) $amounts['base_amount'];
        $discount = (int) $amounts['discount_amount'];
        $certificate = (int) $amounts['certificate_amount'];
        $collected = isset($amounts['collected']) ? (int) $amounts['collected'] : null;

        if ($reason === '') {
            throw new RuntimeException('A reason is required.');
        }
        if ($base < 0 || $discount < 0 || $certificate < 0 || ($collected ?? 0) < 0) {
            throw new RuntimeException('Amounts cannot be negative.');
        }
        // Same bound the registration applies: past the base, net goes
        // negative into an unsigned column.
        if ($discount > $base) {
            throw new RuntimeException('The discount cannot be more than the course fee.');
        }

        $net = $base - $discount + $certificate;

        return DB::transaction(function () use ($challan, $actor, $base, $discount, $certificate, $collected, $net, $reason) {
            // Locked for the same reason recordPayment() locks: a collection
            // landing between the paid-amount read and the write below would
            // let net drop under money already banked.
            $locked = Challan::query()->lockForUpdate()->findOrFail($challan->getKey());

            if (! $locked->isCharge()
                && $locked->admissions()->where('status', '!=', 'cancelled')->doesntExist()) {
                throw new RuntimeException('This registration is cancelled, so its challan cannot be edited.');
            }

            $wasPaid = $locked->paidAmount();
            $paid = $collected ?? $wasPaid;

            // Refused rather than leaving an overpayment behind: a balance
            // below zero is money the system would hold against nothing.
            if ($net < $paid) {
                throw new RuntimeException(
                    'Net payable would be '.Format::money($net).', less than the '.Format::money($paid)
                    .' collected. Lower the collected amount too, or keep the fee higher.'
                );
            }

            $before = $locked->only(['base_amount', 'discount_amount', 'certificate_amount', 'net_amount', 'status']);
            $amountsChanged = $before['base_amount'] !== $base
                || $before['discount_amount'] !== $discount
                || $before['certificate_amount'] !== $certificate;

            if (! $amountsChanged && $paid === $wasPaid) {
                throw new RuntimeException('Nothing has changed.');
            }

            if ($paid !== $wasPaid) {
                $this->correctCollected($locked, $actor, $wasPaid, $paid, $reason);
            }

            $settled = $paid > 0 && $paid >= $net;

            $locked->update([
                'base_amount' => $base,
                'discount_amount' => $discount,
                // A discount needs a reason (spec). Keep the one it was given;
                // one introduced here takes the edit's reason.
                'discount_reason' => $discount > 0 ? ($locked->discount_reason ?: $reason) : null,
                'discount_approved_by' => $discount > 0 ? $locked->discount_approved_by : null,
                'certificate_amount' => $certificate,
                'net_amount' => $net,
                'status' => $settled ? 'paid' : ($locked->status === 'paid' ? 'unpaid' : $locked->status),
                'paid_at' => $settled ? ($locked->paid_at ?? now()) : null,
            ]);

            $this->reapportion($locked, $base);
            $this->rescheduleTo($locked, $net);
            // rescheduleTo() reconciles when the plan is rewritten; this
            // covers a plan whose parts did not move but whose money did.
            $this->installments->reconcile($locked);

            if (! $amountsChanged) {
                return $locked->refresh();
            }

            Audit::record('Challan amount edited', $actor, [
                'challan_id' => $locked->id,
                'subject' => $locked,
                'subject_label' => $locked->challan_no,
                'field' => 'net_amount',
                'old_value' => Format::money($before['net_amount']),
                'new_value' => Format::money($net),
                'context' => [
                    'reason' => $reason,
                    'before' => $before,
                    'after' => $locked->only(['base_amount', 'discount_amount', 'certificate_amount', 'net_amount', 'status']),
                ],
            ]);

            return $locked->refresh();
        });
    }

    /**
     * Bring the money collected to `$to`, without editing a payment.
     *
     * `payments` is append-only (see Payment and PaymentReversals), so a
     * wrongly typed collection is corrected the way the ledger is built to be
     * corrected, and both facts stay on the record:
     *
     *   - Too little recorded: a payment for the difference, dated, attributed
     *     and paid the same way as the last collection, because that is when
     *     and how the money really arrived, and the day's cash reconciles.
     *   - Too much recorded: reversals, newest payment first, since the latest
     *     handover is the likeliest to be the one typed wrong.
     */
    private function correctCollected(Challan $challan, SuperAdmin $actor, int $from, int $to, string $reason): void
    {
        $note = 'Correction by super admin: '.$reason;

        $payments = $challan->payments()->with('reversals')
            ->orderByDesc('received_at')->orderByDesc('id')->lockForUpdate()->get();

        if ($to > $from) {
            $last = $payments->first();

            $challan->payments()->create([
                'amount' => $to - $from,
                'method' => $last?->method ?? config('institute.payment_methods')[0],
                'received_by' => $last?->received_by,
                'received_at' => $last?->received_at ?? now(),
                'note' => mb_substr($note, 0, 255),
            ]);
        } else {
            $left = $from - $to;

            foreach ($payments as $payment) {
                $take = min($left, $payment->netAmount());
                if ($take <= 0) {
                    continue;
                }

                // `approved_by` is a staff account; a super admin is not one,
                // so it stays null and the audit row below names who did it.
                PaymentReversal::create([
                    'payment_id' => $payment->id,
                    'amount' => $take,
                    'reason' => mb_substr($note, 0, 255),
                    'approved_by' => null,
                ]);

                $left -= $take;
                if ($left === 0) {
                    break;
                }
            }
        }

        Audit::record('Collected amount corrected', $actor, [
            'challan_id' => $challan->id,
            'subject' => $challan,
            'subject_label' => $challan->challan_no,
            'field' => 'paid_amount',
            'old_value' => Format::money($from),
            'new_value' => Format::money($to),
            'context' => ['reason' => $reason],
        ]);
    }

    /**
     * Spread a new base across the live enrolments in proportion to what each
     * billed before, the odd rupees to the last, so they still sum to it.
     */
    private function reapportion(Challan $challan, int $base): void
    {
        $live = $challan->admissions()->where('status', '!=', 'cancelled')->get();

        if ($live->isEmpty()) {
            return;
        }

        $old = (int) $live->sum('billed_amount');
        $given = 0;

        foreach ($live->values() as $i => $admission) {
            $share = $i === $live->count() - 1
                ? $base - $given
                : ($old > 0 ? intdiv((int) $admission->billed_amount * $base, $old) : intdiv($base, $live->count()));

            $admission->update(['billed_amount' => $share]);
            $given += $share;
        }
    }

    /**
     * Keep a split plan summing to the new net.
     *
     * A rise goes on the last part. A cut comes off the last parts first,
     * dropping any it empties, so the earliest dates (the ones most likely
     * already paid) are left as agreed. One part left means the plan is over.
     */
    private function rescheduleTo(Challan $challan, int $net): void
    {
        $parts = $challan->installments()->orderBy('seq')->get()
            ->map(fn ($i) => ['amount' => (int) $i->amount, 'due_date' => $i->due_date->toDateString()])
            ->all();

        if ($parts === []) {
            return;
        }

        $diff = $net - array_sum(array_column($parts, 'amount'));

        if ($diff >= 0) {
            $parts[count($parts) - 1]['amount'] += $diff;
        } else {
            $cut = -$diff;
            while ($cut > 0 && $parts !== []) {
                $last = count($parts) - 1;
                $take = min($cut, $parts[$last]['amount']);
                $parts[$last]['amount'] -= $take;
                $cut -= $take;
                if ($parts[$last]['amount'] === 0) {
                    array_pop($parts);
                }
            }
        }

        if ($net === 0 || $parts === []) {
            $challan->installments()->delete();
            $challan->update(['plan' => 'full']);

            return;
        }

        $this->installments->schedule($challan->refresh(), $parts);
    }
}
