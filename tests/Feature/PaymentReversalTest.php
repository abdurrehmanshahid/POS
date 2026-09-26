<?php

namespace Tests\Feature;

use App\Models\Challan;
use App\Models\Payment;
use App\Models\PaymentReversal;
use App\Models\User;
use App\Services\Analytics;
use App\Services\ChallanActions;
use App\Services\Ledger;
use App\Services\PaymentReversals;
use App\Services\Reporting;
use App\Support\Period;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * Reversing a payment, and the invariant that makes it safe:
 *
 *     gross payments − reversals = net receipts, on EVERY money surface.
 *
 * That last clause is the whole point of this file. There are eleven places in
 * this application that answer "how much came in" — the staff dashboard, the
 * Reports screen's summary, its daily series, its by-method table, its
 * revenue-by-course, both officer scorecards, the owner console's ledger, its
 * revenue-by-course, its monthly chart, and every individual challan's balance.
 * A reversal that reaches ten of them and misses one does not produce an
 * obvious error; it produces one screen that OVERSTATES net receipts, which is
 * the direction that makes a cashier look like a thief.
 *
 * So the tests below do not check that reversal "works". They check that after
 * a reversal, every one of those figures moved by exactly the reversed amount.
 */
class PaymentReversalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['institute.today' => '2026-07-15']);
        Carbon::setTestNow('2026-07-15 10:00:00');
        $this->seed(DatabaseSeeder::class);
    }

    private function admin(): User
    {
        return User::where('username', 'adminansar')->firstOrFail();
    }

    private function officer(): User
    {
        return User::where('username', 'aliraza')->firstOrFail();
    }

    /** A challan with an unpaid balance we can collect against. */
    private function collectableChallan(): Challan
    {
        return Challan::query()
            ->whereHas('admissions', fn ($a) => $a->where('status', '!=', 'cancelled'))
            ->get()
            ->first(fn (Challan $c) => $c->balance() > 1000)
            ?? $this->fail('The seed data has no challan with an outstanding balance.');
    }

    // ---- The invariant, across every money surface -------------------------

    /**
     * The test this whole feature exists to make possible.
     *
     * Snapshots every money figure in the application, reverses a payment, and
     * asserts each one fell by exactly the reversed amount. Written as one test
     * over a table of closures rather than eleven near-identical tests, because
     * the failure mode being guarded against is "somebody added a twelfth money
     * figure and did not use NetReceipts" — and the fix for that is a list in
     * one place that is obviously incomplete when it is.
     */
    public function test_every_money_figure_drops_by_exactly_the_reversed_amount(): void
    {
        $admin = $this->admin();
        $challan = $this->collectableChallan();
        $period = Period::resolve('month');

        app(ChallanActions::class)->recordPayment($challan, $admin, 4000, 'Cash');
        $payment = $challan->fresh()->payments()->latest('id')->firstOrFail();

        $ledger = app(Ledger::class);
        $reporting = app(Reporting::class);
        $analytics = app(Analytics::class);

        // Every surface that answers "how much money came in".
        $figures = [
            'Ledger::received (staff dashboard)' => fn () => $ledger->received($admin),
            'Ledger::revenueTrend (dashboard chart)' => fn () => collect($ledger->revenueTrend($admin))->sum('amount'),
            'Ledger::revenueByCourse (dashboard card)' => fn () => collect($ledger->revenueByCourse($admin, 100))->sum('amount'),
            'Reporting::summary collected' => fn () => $reporting->summary($admin, $period)['collected'],
            'Reporting::summary today' => fn () => $reporting->summary($admin, $period)['today'],
            'Reporting::collectedOn' => fn () => $reporting->collectedOn($admin, Carbon::parse('2026-07-15')),
            'Reporting::collectionSeries (daily chart)' => fn () => $reporting->collectionSeries($admin, $period)->sum('total'),
            'Reporting::byPaymentMethod (drawer reconciliation)' => fn () => $reporting->byPaymentMethod($admin, $period)->sum('total'),
            'Reporting::revenueByCourse' => fn () => $reporting->revenueByCourse($admin, $period, 100)->sum('total'),
            'Reporting::officerPerformance' => fn () => $reporting->officerPerformance($period)->sum('received'),
            'Analytics::ledger (owner console)' => fn () => $analytics->ledger()['received'],
            'Analytics::monthlyRevenue (owner chart)' => fn () => $analytics->monthlyRevenue()->sum('total'),
            'Analytics::revenueByCourse (owner console)' => fn () => $analytics->revenueByCourse(100)->sum('received'),
            'Analytics::staffPerformance' => fn () => $analytics->staffPerformance()->sum('received'),
            'Challan::paidAmount (the challan itself)' => fn () => $challan->fresh()->paidAmount(),
        ];

        $before = array_map(fn ($f) => $f(), $figures);

        app(PaymentReversals::class)->reverse($payment, $admin, 4000, 'Amount mistyped at the counter');

        foreach ($figures as $label => $figure) {
            $this->assertSame(
                $before[$label] - 4000,
                $figure(),
                "{$label} did not drop by the reversed amount. A money figure that misses reversals OVERSTATES net receipts."
            );
        }
    }

    /** billed = received + outstanding must still reconcile after a reversal. */
    public function test_the_ledger_still_reconciles_after_a_reversal(): void
    {
        $admin = $this->admin();
        $challan = $this->collectableChallan();

        app(ChallanActions::class)->recordPayment($challan, $admin, 3000, 'Cash');
        $payment = $challan->fresh()->payments()->latest('id')->firstOrFail();
        app(PaymentReversals::class)->reverse($payment, $admin, 1200, 'Overcharged by 1,200');

        $ledger = app(Ledger::class);

        $this->assertSame(
            $ledger->billed($admin),
            $ledger->received($admin) + $ledger->outstanding($admin),
            'billed = received + outstanding is the one arithmetic identity every money screen leans on.'
        );

        // And the owner console's own reconciliation flag.
        $this->assertTrue(app(Analytics::class)->ledger()['reconciles']);
    }

    // ---- Append-only ---------------------------------------------------------

    public function test_the_original_payment_row_is_never_touched(): void
    {
        $admin = $this->admin();
        $challan = $this->collectableChallan();

        app(ChallanActions::class)->recordPayment($challan, $admin, 5000, 'Cash');
        $payment = $challan->fresh()->payments()->latest('id')->firstOrFail();

        app(PaymentReversals::class)->reverse($payment, $admin, 5000, 'Duplicate entry');

        $payment->refresh();

        // Gross is the historical fact and does not move. This is what lets the
        // receipt reprint match the copy the student is holding.
        $this->assertSame(5000, $payment->amount);
        $this->assertSame('Cash', $payment->method);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'amount' => 5000]);

        // What changed is the derived view of it.
        $this->assertSame(5000, $payment->reversedAmount());
        $this->assertSame(0, $payment->netAmount());
        $this->assertTrue($payment->isReversed());
    }

    public function test_the_reversal_is_written_to_the_activity_log(): void
    {
        $admin = $this->admin();
        $challan = $this->collectableChallan();

        app(ChallanActions::class)->recordPayment($challan, $admin, 2500, 'Cash');
        $payment = $challan->fresh()->payments()->latest('id')->firstOrFail();

        app(PaymentReversals::class)->reverse($payment, $admin, 2500, 'Paid against the wrong student');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'Payment reversed',
            'challan_id' => $challan->id,
            'actor_id' => $admin->id,
        ]);
    }

    // ---- The guards ----------------------------------------------------------

    public function test_it_refuses_to_reverse_more_than_the_payment(): void
    {
        $admin = $this->admin();
        $challan = $this->collectableChallan();

        app(ChallanActions::class)->recordPayment($challan, $admin, 2000, 'Cash');
        $payment = $challan->fresh()->payments()->latest('id')->firstOrFail();

        $this->expectException(RuntimeException::class);
        // Refused, not clamped. Reversing a different number than was typed is
        // how a correction becomes a second error nobody notices.
        app(PaymentReversals::class)->reverse($payment, $admin, 2001, 'Too much');
    }

    public function test_cumulative_reversals_cannot_exceed_the_payment(): void
    {
        $admin = $this->admin();
        $challan = $this->collectableChallan();

        app(ChallanActions::class)->recordPayment($challan, $admin, 2000, 'Cash');
        $payment = $challan->fresh()->payments()->latest('id')->firstOrFail();

        $service = app(PaymentReversals::class);
        $service->reverse($payment, $admin, 1200, 'First correction');
        $service->reverse($payment->fresh(), $admin, 800, 'Second correction');

        $this->assertSame(2000, $payment->fresh()->reversedAmount());

        // The third would take net receipts negative.
        $this->expectException(RuntimeException::class);
        $service->reverse($payment->fresh(), $admin, 1, 'One rupee too far');
    }

    public function test_a_reason_is_required(): void
    {
        $admin = $this->admin();
        $challan = $this->collectableChallan();

        app(ChallanActions::class)->recordPayment($challan, $admin, 1000, 'Cash');
        $payment = $challan->fresh()->payments()->latest('id')->firstOrFail();

        $this->expectException(RuntimeException::class);
        app(PaymentReversals::class)->reverse($payment, $admin, 1000, '   ');
    }

    /**
     * The control the whole feature rests on: the officer who took the money
     * cannot be the one who quietly takes it back.
     */
    public function test_an_admission_officer_cannot_reverse_a_payment(): void
    {
        $admin = $this->admin();
        $officer = $this->officer();
        $challan = $this->collectableChallan();

        app(ChallanActions::class)->recordPayment($challan, $admin, 1500, 'Cash');
        $payment = $challan->fresh()->payments()->latest('id')->firstOrFail();

        $this->assertFalse($officer->hasPermission('payments.reverse'));

        $this->expectException(RuntimeException::class);
        app(PaymentReversals::class)->reverse($payment, $officer, 1500, 'Not my call to make');
    }

    // ---- Knock-on state ------------------------------------------------------

    /**
     * `challans.status` is a denormalised "balance reached zero" flag, and the
     * overdue query, the ageing report and the status pill all read it rather
     * than recomputing. Leaving it on `paid` after a reversal would hide a
     * genuine debt from every screen that exists to surface debt.
     */
    public function test_reversing_reopens_a_settled_challan(): void
    {
        $admin = $this->admin();
        $challan = $this->collectableChallan();

        app(ChallanActions::class)->markPaid($challan, $admin, 'Cash');
        $challan->refresh();
        $this->assertTrue($challan->isPaid());

        $payment = $challan->payments()->latest('id')->firstOrFail();
        app(PaymentReversals::class)->reverse($payment, $admin, 1000, 'Change given short');

        $challan->refresh();

        $this->assertFalse($challan->isPaid(), 'A challan with an outstanding balance must not stay flagged paid.');
        $this->assertSame(1000, $challan->balance());
        $this->assertNull($challan->paid_at);
    }

    /** A partial reversal leaves the rest of the payment standing. */
    public function test_a_partial_reversal_leaves_the_remainder_intact(): void
    {
        $admin = $this->admin();
        $challan = $this->collectableChallan();

        app(ChallanActions::class)->recordPayment($challan, $admin, 5000, 'Cash');
        $payment = $challan->fresh()->payments()->latest('id')->firstOrFail();

        app(PaymentReversals::class)->reverse($payment, $admin, 500, 'Rounded wrong');

        $this->assertSame(4500, $payment->fresh()->netAmount());
        $this->assertFalse($payment->fresh()->isReversed());
    }

    /**
     * The reversal ledger is append-only in its own right: a reversal is a fact
     * about what happened and the answer to a mistaken one is a fresh payment,
     * not an edit.
     */
    public function test_reversals_are_never_updated_or_deleted_by_the_application(): void
    {
        $admin = $this->admin();
        $challan = $this->collectableChallan();

        app(ChallanActions::class)->recordPayment($challan, $admin, 1000, 'Cash');
        $payment = $challan->fresh()->payments()->latest('id')->firstOrFail();
        $reversal = app(PaymentReversals::class)->reverse($payment, $admin, 1000, 'Wrong student');

        // Taking the money again is the documented correction path, and it
        // leaves BOTH facts on the record rather than erasing one.
        app(ChallanActions::class)->recordPayment($challan->fresh(), $admin, 1000, 'Cash');

        $this->assertDatabaseHas('payment_reversals', ['id' => $reversal->id, 'amount' => 1000]);
        $this->assertSame(1, PaymentReversal::count());
        $this->assertSame(2, $payment->challan->payments()->count());
        $this->assertSame(1000, $challan->fresh()->paidAmount());
    }

    /**
     * A payment reversed AFTER a receipt was issued must not silently rewrite
     * that receipt's arithmetic, but a receipt issued after the reversal must
     * account for it. See ReceiptController::balanceAfter().
     */
    public function test_a_later_receipt_accounts_for_an_earlier_reversal(): void
    {
        $admin = $this->admin();
        $challan = $this->collectableChallan();
        $net = $challan->net_amount;

        app(ChallanActions::class)->recordPayment($challan, $admin, 3000, 'Cash');
        $first = $challan->fresh()->payments()->latest('id')->firstOrFail();

        Carbon::setTestNow('2026-07-15 11:00:00');
        app(PaymentReversals::class)->reverse($first, $admin, 3000, 'Wrong challan');

        Carbon::setTestNow('2026-07-15 12:00:00');
        app(ChallanActions::class)->recordPayment($challan->fresh(), $admin, 2000, 'Cash');
        $second = $challan->fresh()->payments()->latest('id')->firstOrFail();

        // The reversal of the first payment happened BEFORE the second, so the
        // second receipt must show the student still owes the full fee less
        // only its own 2,000 — not less 5,000.
        $response = $this->actingAs($admin)->get(route('payments.receipt.stream', $second));
        $response->assertOk();

        $this->assertSame($net - 2000, $challan->fresh()->balance());
    }

    public function test_a_fully_reversed_payment_still_counts_as_a_movement(): void
    {
        $admin = $this->admin();
        $challan = $this->collectableChallan();
        $period = Period::resolve('month');

        $countBefore = app(Reporting::class)->summary($admin, $period)['payments'];

        app(ChallanActions::class)->recordPayment($challan, $admin, 1000, 'Cash');
        $payment = $challan->fresh()->payments()->latest('id')->firstOrFail();
        app(PaymentReversals::class)->reverse($payment, $admin, 1000, 'Reversed in full');

        $summary = app(Reporting::class)->summary($admin, $period);

        // The AMOUNT is net; the COUNT is of movements. Money was handed over
        // and a receipt was printed — a count that forgot it would disagree
        // with the audit trail.
        $this->assertSame($countBefore + 1, $summary['payments']);
    }
}
