<?php

namespace App\Services;

use App\Models\Challan;
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
 * screen).
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
     * @param  array{base_amount:int, discount_amount:int, certificate_amount:int}  $amounts
     */
    public function edit(Challan $challan, SuperAdmin $actor, array $amounts, string $reason): Challan
    {
        $reason = trim($reason);
        $base = (int) $amounts['base_amount'];
        $discount = (int) $amounts['discount_amount'];
        $certificate = (int) $amounts['certificate_amount'];

        if ($reason === '') {
            throw new RuntimeException('A reason is required.');
        }
        if ($base < 0 || $discount < 0 || $certificate < 0) {
            throw new RuntimeException('Amounts cannot be negative.');
        }
        // Same bound the registration applies: past the base, net goes
        // negative into an unsigned column.
        if ($discount > $base) {
            throw new RuntimeException('The discount cannot be more than the course fee.');
        }

        $net = $base - $discount + $certificate;

        return DB::transaction(function () use ($challan, $actor, $base, $discount, $certificate, $net, $reason) {
            // Locked for the same reason recordPayment() locks: a collection
            // landing between the paid-amount read and the write below would
            // let net drop under money already banked.
            $locked = Challan::query()->lockForUpdate()->findOrFail($challan->getKey());

            if (! $locked->isCharge()
                && $locked->admissions()->where('status', '!=', 'cancelled')->doesntExist()) {
                throw new RuntimeException('This registration is cancelled, so its challan cannot be edited.');
            }

            $paid = $locked->paidAmount();

            // Refused rather than leaving an overpayment behind. The money in
            // the drawer is real; lowering the fee below it needs the payment
            // reversed first, which is its own audited act.
            if ($net < $paid) {
                throw new RuntimeException(
                    'Net payable would be '.Format::money($net).', less than the '.Format::money($paid)
                    .' already collected. Reverse a payment first.'
                );
            }

            $before = $locked->only(['base_amount', 'discount_amount', 'certificate_amount', 'net_amount', 'status']);

            if ($before['base_amount'] === $base
                && $before['discount_amount'] === $discount
                && $before['certificate_amount'] === $certificate) {
                throw new RuntimeException('Nothing has changed.');
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
