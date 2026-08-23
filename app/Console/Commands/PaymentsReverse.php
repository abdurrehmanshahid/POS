<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentReversals;
use App\Support\Format;
use Illuminate\Console\Command;
use Throwable;

/**
 * Reverse a recorded payment from the command line.
 *
 * The in-app action (Challans screen, supervisor only) is the normal route and
 * the one the counter will use. This exists for the cases the UI cannot reach:
 *
 *   - a correction needed before anyone is enrolled with the right permission;
 *   - a payment on a challan whose enrolments are all cancelled, which every
 *     money screen deliberately hides;
 *   - a scripted correction of several rows after an import went wrong.
 *
 * It enforces exactly the same rules as the screen, because it calls exactly
 * the same service. A second reversal implementation "just for the CLI" would
 * be the third money path in this codebase to drift from its twin, and the
 * previous two both cost a release.
 *
 * It is not a way around the permission check: --actor must name a user who
 * holds `payments.reverse`, and the service refuses otherwise. The audit row
 * names that person, not "the system", because somebody did authorise this and
 * the ledger should say who.
 */
class PaymentsReverse extends Command
{
    protected $signature = 'payments:reverse
        {payment : The payment id (not the receipt number)}
        {--actor= : Username of the supervisor authorising this. Must hold payments.reverse}
        {--amount= : PKR to reverse. Omit to reverse whatever remains in full}
        {--reason= : Why. Required, and it is written into the audit trail}
        {--force : Skip the confirmation prompt (for scripted corrections)}';

    protected $description = 'Reverse a recorded payment, append-only, with an audit entry';

    public function handle(PaymentReversals $reversals): int
    {
        $payment = Payment::with(['challan.student', 'reversals', 'receiver'])->find($this->argument('payment'));

        if (! $payment) {
            $this->error('No payment with id '.$this->argument('payment').'.');
            $this->line('This is the payments.id, not the receipt number. Receipt BBT-R-000042 is usually id 42, but do not guess — check.');

            return self::FAILURE;
        }

        $actor = $this->resolveActor();

        if (! $actor) {
            return self::FAILURE;
        }

        $remaining = $payment->amount - $payment->reversedAmount();
        $amount = $this->option('amount') !== null ? (int) $this->option('amount') : $remaining;
        $reason = trim((string) $this->option('reason'));

        if ($reason === '') {
            $reason = trim((string) $this->ask('Reason for the reversal (recorded in the audit trail)'));
        }

        // Shown before the confirmation, because the operator is about to
        // change money and the id they typed is the only thing standing between
        // the right payment and a neighbouring one.
        $this->newLine();
        $this->line('  Receipt      '.$payment->receiptNo());
        $this->line('  Student      '.($payment->challan?->student?->name ?? '—'));
        $this->line('  Challan      '.($payment->challan?->challan_no ?? '—'));
        $this->line('  Taken        '.Format::money($payment->amount).' · '.$payment->method
            .' · '.$payment->received_at?->toDateString()
            .' · by '.($payment->receiver?->name ?? 'unknown'));

        if ($payment->reversedAmount() > 0) {
            $this->line('  Already back '.Format::money($payment->reversedAmount()));
        }

        $this->line('  Reversing    '.Format::money($amount).' of '.Format::money($remaining).' reversible');
        $this->line('  Authorised   '.$actor->name.' ('.$actor->username.')');
        $this->line('  Reason       '.$reason);
        $this->newLine();

        if (! $this->option('force') && ! $this->confirm('Record this reversal? It cannot be undone.', false)) {
            $this->line('Nothing was changed.');

            return self::SUCCESS;
        }

        try {
            $reversal = $reversals->reverse($payment, $actor, $amount, $reason);
        } catch (Throwable $e) {
            // The service's messages are written to be read by a supervisor, so
            // they are safe to show verbatim.
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $challan = $payment->challan?->fresh();

        $this->info('Reversal #'.$reversal->id.' recorded: '.Format::money($amount).' against receipt '.$payment->receiptNo().'.');

        if ($challan) {
            $this->line('  Challan '.$challan->challan_no.' now: '
                .Format::money($challan->paidAmount()).' collected · '
                .Format::money($challan->balance()).' due · status '.$challan->status);
        }

        $this->line('  Written to the activity log as "Payment reversed".');

        return self::SUCCESS;
    }

    /**
     * The supervisor authorising this.
     *
     * Deliberately no "system" fallback. A reversal with no named authoriser is
     * the thing an auditor cannot distinguish from tampering, and the whole
     * argument for this feature was that the current alternative — a developer
     * at a SQL prompt — has exactly that property.
     */
    private function resolveActor(): ?User
    {
        $username = (string) $this->option('actor');

        if ($username === '') {
            $this->error('--actor is required: name the supervisor authorising this reversal.');
            $this->line('It is written into the audit trail. A correction with nobody\'s name on it is indistinguishable from tampering.');

            return null;
        }

        $actor = User::where('username', $username)->first();

        if (! $actor) {
            $this->error('No user with username '.$username.'.');

            return null;
        }

        if (! $actor->hasPermission('payments.reverse')) {
            $this->error($actor->name.' does not hold the payments.reverse permission.');
            $this->line('Grant it in Staff & Roles, or name someone who already holds it. Administrator holds it by default; Admission Officer deliberately does not.');

            return null;
        }

        return $actor;
    }
}
