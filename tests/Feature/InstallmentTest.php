<?php

namespace Tests\Feature;

use App\Models\Challan;
use App\Models\User;
use App\Services\ChallanActions;
use App\Services\Installments;
use App\Support\Clock;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Scheduled installments: what the student is supposed to pay and by when.
 *
 * The distinction under test throughout is between the SCHEDULE (installments)
 * and the LEDGER (payments). The schedule never records money; it is derived
 * from the ledger on every collection. These tests exist mostly to pin that
 * derivation down, because the failure mode of getting it wrong is a dues
 * report that disagrees with the cash actually taken.
 */
class InstallmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function admin(): User
    {
        return User::where('username', 'adminansar')->firstOrFail();
    }

    /** Unpaid, net 25,000, no collections against it. */
    private function challan(): Challan
    {
        return Challan::where('challan_no', 'BBT-CH-2026-1076')->firstOrFail();
    }

    private function svc(): Installments
    {
        return app(Installments::class);
    }

    private function days(int $n): string
    {
        return Clock::today()->copy()->addDays($n)->toDateString();
    }

    // ---- The schedule must describe the fee, exactly ------------------------

    public function test_a_schedule_that_does_not_sum_to_the_fee_is_refused(): void
    {
        $challan = $this->challan();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must match/');

        $this->svc()->schedule($challan, [
            ['amount' => 10000, 'due_date' => $this->days(7)],
            ['amount' => 10000, 'due_date' => $this->days(37)], // 20,000 ≠ 25,000
        ]);
    }

    public function test_installments_may_not_fall_due_out_of_order(): void
    {
        $challan = $this->challan();

        $this->expectException(InvalidArgumentException::class);

        $this->svc()->schedule($challan, [
            ['amount' => 15000, 'due_date' => $this->days(37)],
            ['amount' => 10000, 'due_date' => $this->days(7)],
        ]);
    }

    public function test_a_zero_installment_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->svc()->schedule($this->challan(), [
            ['amount' => 25000, 'due_date' => $this->days(7)],
            ['amount' => 0, 'due_date' => $this->days(37)],
        ]);
    }

    public function test_scheduling_marks_the_challan_split_and_tracks_the_final_date(): void
    {
        $challan = $this->svc()->schedule($this->challan(), [
            ['amount' => 10000, 'due_date' => $this->days(7)],
            ['amount' => 15000, 'due_date' => $this->days(37)],
        ]);

        $challan->refresh();

        $this->assertSame('split', $challan->plan);
        $this->assertCount(2, $challan->installments);
        // The challan's own due date is the LAST deadline, so every existing
        // query reading challans.due_date still means "settled in full by".
        $this->assertSame($this->days(37), $challan->due_date->toDateString());
    }

    // ---- Money is applied to the schedule oldest-first ----------------------

    public function test_an_advance_covering_the_first_installment_settles_only_that_one(): void
    {
        $challan = $this->svc()->schedule($this->challan(), [
            ['amount' => 10000, 'due_date' => $this->days(7)],
            ['amount' => 15000, 'due_date' => $this->days(37)],
        ]);

        app(ChallanActions::class)->recordPayment($challan, $this->admin(), 10000, 'Cash');

        $schedule = $challan->refresh()->installments()->orderBy('seq')->get();

        $this->assertSame('paid', $schedule[0]->status, 'The advance covers installment one exactly.');
        $this->assertSame('unpaid', $schedule[1]->status, 'The balance is still owed.');
        $this->assertNotSame('paid', $challan->status, 'The fee as a whole is not settled.');
    }

    public function test_a_payment_short_of_the_first_installment_settles_nothing(): void
    {
        $challan = $this->svc()->schedule($this->challan(), [
            ['amount' => 10000, 'due_date' => $this->days(7)],
            ['amount' => 15000, 'due_date' => $this->days(37)],
        ]);

        app(ChallanActions::class)->recordPayment($challan, $this->admin(), 9999, 'Cash');

        $schedule = $challan->refresh()->installments()->orderBy('seq')->get();

        // Part of an installment is not an installment. Rounding this up would
        // clear a deadline the student has not actually met.
        $this->assertSame('unpaid', $schedule[0]->status);
        $this->assertSame('unpaid', $schedule[1]->status);
        $this->assertSame(9999, $challan->paidAmount(), 'The money is still banked and reported.');
    }

    public function test_settling_the_fee_settles_every_installment(): void
    {
        $challan = $this->svc()->schedule($this->challan(), [
            ['amount' => 10000, 'due_date' => $this->days(7)],
            ['amount' => 15000, 'due_date' => $this->days(37)],
        ]);

        app(ChallanActions::class)->markPaid($challan, $this->admin(), 'Bank transfer');

        $challan->refresh();

        $this->assertSame('paid', $challan->status);
        $this->assertSame(0, $challan->installments()->where('status', 'unpaid')->count());
    }

    // ---- The reason the schedule exists ------------------------------------

    /**
     * The case a single `due_date` on the challan cannot express, and the whole
     * justification for keeping the installments table.
     */
    public function test_a_missed_first_installment_makes_the_challan_overdue_before_the_final_date(): void
    {
        $challan = $this->svc()->schedule($this->challan(), [
            ['amount' => 10000, 'due_date' => $this->days(-3)],  // deadline passed
            ['amount' => 15000, 'due_date' => $this->days(30)],  // still in the future
        ]);

        $challan->refresh()->load('installments');

        $this->assertTrue(
            $challan->isOverdue(),
            'The first deadline has passed unpaid, so the student is overdue even though the final date has not arrived.'
        );
        $this->assertSame('overdue', $challan->paymentState());
    }

    public function test_paying_the_first_installment_on_time_leaves_the_challan_current(): void
    {
        $challan = $this->svc()->schedule($this->challan(), [
            ['amount' => 10000, 'due_date' => $this->days(-3)],
            ['amount' => 15000, 'due_date' => $this->days(30)],
        ]);

        app(ChallanActions::class)->recordPayment($challan, $this->admin(), 10000, 'Cash');

        $challan->refresh()->load('installments');

        $this->assertFalse(
            $challan->isOverdue(),
            'Installment one is settled and installment two is not yet due.'
        );
        $this->assertSame('unpaid', $challan->paymentState());
    }

    // ---- Derivation properties ---------------------------------------------

    public function test_reconcile_is_idempotent(): void
    {
        $challan = $this->svc()->schedule($this->challan(), [
            ['amount' => 10000, 'due_date' => $this->days(7)],
            ['amount' => 15000, 'due_date' => $this->days(37)],
        ]);

        app(ChallanActions::class)->recordPayment($challan, $this->admin(), 10000, 'Cash');

        $first = $challan->refresh()->installments()->orderBy('seq')->pluck('status')->all();

        $this->svc()->reconcile($challan);
        $this->svc()->reconcile($challan);

        $this->assertSame($first, $challan->refresh()->installments()->orderBy('seq')->pluck('status')->all());
    }

    public function test_rescheduling_replaces_the_plan_rather_than_editing_it(): void
    {
        $challan = $this->svc()->schedule($this->challan(), [
            ['amount' => 10000, 'due_date' => $this->days(7)],
            ['amount' => 15000, 'due_date' => $this->days(37)],
        ]);

        // Back to a single payment. The second installment must not survive.
        $challan = $this->svc()->schedule($challan, [
            ['amount' => 25000, 'due_date' => $this->days(14)],
        ]);

        $challan->refresh();

        $this->assertCount(1, $challan->installments);
        $this->assertSame('full', $challan->plan);
        $this->assertSame($this->days(14), $challan->due_date->toDateString());
    }

    public function test_the_default_plan_halves_the_fee_and_always_sums_to_it(): void
    {
        $challan = $this->challan();

        // An odd fee is the case that catches a naive round() on both halves.
        $challan->forceFill(['net_amount' => 24999])->save();

        $parts = $this->svc()->defaultPlan($challan);

        $this->assertSame(12500, $parts[0]['amount'], 'The odd rupee goes into the first installment.');
        $this->assertSame(12499, $parts[1]['amount']);
        $this->assertSame(24999, $parts[0]['amount'] + $parts[1]['amount']);
    }

    public function test_a_challan_with_no_schedule_is_untouched_by_reconcile(): void
    {
        $challan = $this->challan();

        $this->assertSame('full', $challan->plan);

        app(ChallanActions::class)->recordPayment($challan, $this->admin(), 5000, 'Cash');

        $challan->refresh();

        $this->assertCount(0, $challan->installments);
        $this->assertSame(5000, $challan->paidAmount());
        $this->assertSame(20000, $challan->balance());
    }
}
