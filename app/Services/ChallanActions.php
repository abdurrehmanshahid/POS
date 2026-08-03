<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\AppNotification;
use App\Models\Challan;
use App\Models\User;
use App\Support\Format;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Payment and cancellation operations on challans (spec §7.6 / §7.7). Each is a
 * transaction that mutates state and writes an immutable audit row.
 */
class ChallanActions
{
    /** Settle the whole outstanding balance in one movement (spec §7.6). */
    public function markPaid(Challan $challan, User $actor, string $via): Challan
    {
        return $this->recordPayment($challan, $actor, $challan->balance(), $via);
    }

    /**
     * Record one collection against a challan.
     *
     * Partial collection is the normal case at the counter: a student pays an
     * advance on admission and the rest later. Each handover is its own row, so
     * the challan can say who took how much, when and by what method, rather
     * than collapsing the whole history into a single paid flag.
     *
     * The flag is still maintained, because the status pill, the overdue query
     * and every existing report read it; it now simply describes whether the
     * balance has reached zero.
     */
    public function recordPayment(Challan $challan, User $actor, int $amount, string $via, ?string $note = null): Challan
    {
        if (! in_array($via, config('institute.payment_methods'), true)) {
            throw new RuntimeException('Invalid payment method.');
        }
        if ($amount <= 0) {
            throw new RuntimeException('A payment must be greater than zero.');
        }

        return DB::transaction(function () use ($challan, $actor, $amount, $via, $note) {
            // Both the status check and the balance read happen INSIDE the
            // transaction, against a locked row.
            //
            // Read outside it, two officers collecting on the same challan at
            // the same moment saw the same balance, both passed the check below,
            // and both inserted, banking more than was owed. The window is
            // small and the counter is exactly where it happens: two people at
            // two terminals settling one family's fee.
            $locked = Challan::query()->lockForUpdate()->findOrFail($challan->getKey());

            if ($locked->status === 'paid') {
                throw new RuntimeException('Challan is already paid.');
            }

            $balance = $locked->balance();

            if ($amount > $balance) {
                // Refusing rather than clamping: someone handing over more than
                // is owed has misread something, and silently pocketing the
                // difference would put money in the system that reconciles
                // against nothing.
                throw new RuntimeException('That is more than the outstanding balance of '.Format::money($balance).'.');
            }

            $challan->payments()->create([
                'amount' => $amount,
                'method' => $via,
                'received_by' => $actor->id,
                'received_at' => now(),
                'note' => $note,
            ]);

            $settled = $amount >= $balance;

            // Derived from the balance read before the insert, not by asking the
            // relation again: it may already be loaded, and would answer with a
            // cached collection that predates the row we just wrote.
            $paidBefore = $locked->net_amount - $balance;
            $stillDue = $balance - $amount;

            $challan->update([
                'status' => $settled ? 'paid' : $challan->status,
                'paid_at' => $settled ? now() : null,
                'paid_via' => $via,
            ]);

            if ($settled) {
                $challan->installments()->update(['status' => 'paid', 'paid_at' => now()]);
                Audit::markedPaid($challan, $actor, $via);
            } else {
                Audit::record('Part payment received', $actor, [
                    'subject' => $challan,
                    'subject_label' => $challan->challan_no,
                    'field' => 'paid_amount',
                    'old_value' => (string) $paidBefore,
                    'new_value' => (string) ($paidBefore + $amount),
                ]);
            }

            AppNotification::create([
                'type' => 'payment',
                'title' => $settled ? 'Payment recorded' : 'Part payment received',
                'sub' => $challan->admission->student->name.', '.Format::money($amount).' ('.$via.')'
                    .($settled ? '' : ', '.Format::money($stillDue).' still due'),
                'student_id' => $challan->admission->student_id,
                'challan_id' => $challan->id,
                'is_revenue' => true,
            ]);

            return $challan->refresh();
        });
    }

    /**
     * Cancel a registration (spec §7.7). Soft, sets admission to cancelled with
     * a required reason; the linked challan is voided from money totals and lists.
     *
     * A COLLECTED ON registration cannot be cancelled. Every money query in
     * Ledger excludes cancelled admissions, so cancelling one would retroactively
     * erase revenue the institute actually banked from every report, with no
     * refund record and nothing to show it ever happened. Cancellation is for
     * enrolments that were never collected on; giving money back is a refund,
     * which is a separate and explicit act.
     *
     * This tests `hasCollections()` and not the `paid` status. Part payments
     * arrived after this guard did, and a Rs 20,000 advance against a Rs 40,000
     * fee leaves the status short of paid, so a status-only check waved through
     * exactly the case the guard exists to stop.
     */
    public function cancel(Admission $admission, User $actor, string $reason): Admission
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('A cancellation reason is required.');
        }
        if ($admission->challan?->hasCollections()) {
            throw new RuntimeException('Money has been collected against this registration. Record a refund instead of cancelling it.');
        }

        return DB::transaction(function () use ($admission, $actor, $reason) {
            $challan = $admission->challan;
            if ($challan) {
                Audit::cancelled($challan, $actor);
            }

            $admission->update([
                'status' => 'cancelled',
                'rejection_reason' => $reason,
            ]);

            return $admission->refresh();
        });
    }
}
