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
    /** Record a payment (spec §7.6). Requires a payment method. */
    public function markPaid(Challan $challan, User $actor, string $via): Challan
    {
        $methods = ['Cash', 'Bank transfer', 'Card', 'Wallet', 'Cheque'];
        if (! in_array($via, $methods, true)) {
            throw new RuntimeException('Invalid payment method.');
        }
        if ($challan->status === 'paid') {
            throw new RuntimeException('Challan is already paid.');
        }

        return DB::transaction(function () use ($challan, $actor, $via) {
            $challan->update([
                'status' => 'paid',
                'paid_at' => now(),
                'paid_via' => $via,
            ]);
            $challan->installments()->update(['status' => 'paid', 'paid_at' => now()]);

            Audit::markedPaid($challan, $actor, $via);

            AppNotification::create([
                'type' => 'payment',
                'title' => 'Payment recorded',
                'sub' => $challan->admission->student->name.', '.Format::money($challan->net_amount).' ('.$via.')',
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
     */
    public function cancel(Admission $admission, User $actor, string $reason): Admission
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('A cancellation reason is required.');
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
