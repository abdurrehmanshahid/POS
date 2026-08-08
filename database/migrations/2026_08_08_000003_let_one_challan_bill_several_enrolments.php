<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One invoice can bill several enrolments.
 *
 * The institute's own paperwork works this way and always has. Invoice #1077
 * bills one student for three Shopify courses on a single document: one fee of
 * 80,000, one 50% discount, one advance, one balance, with the courses listed
 * as bullets. The exported roll agrees, 102 of its rows name more than one
 * course against a single price.
 *
 * The application billed the opposite way: one challan per enrolment, enforced
 * by a UNIQUE on `challans.admission_id`. That same registration produced three
 * separate invoices with three separate fees, so every document handed over the
 * counter would have changed shape on the institute's first day.
 *
 * ── Why this is shaped the way it is ──────────────────────────────────────
 *
 * The obvious move is to drop `challans.admission_id` and hang everything off
 * a new `admissions.challan_id`. That would have meant rewriting ten SQL joins
 * across Ledger, Reporting and Analytics, plus roughly fifteen `$challan->
 * admission` call sites, in one change, on the day of a handover.
 *
 * So the column stays, and keeps its UNIQUE, but its meaning narrows: it is now
 * the ANCHOR enrolment of the invoice, the one whose course headlines it. Every
 * invoice still has exactly one anchor, so the UNIQUE remains true and no index
 * is dropped and no query is invalidated. Additional enrolments join the same
 * invoice through `admissions.challan_id`.
 *
 * `billed_amount` records what share of the invoice each enrolment carries.
 * Without it, revenue-by-course could only credit the anchor and would silently
 * report zero for the other two Shopify courses. It is also exactly the number
 * the spreadsheet import needs in order to split a multi-course row, which the
 * sheet itself does not state.
 *
 * Invariant, asserted below and maintained by RegistrationService:
 *
 *     challan.base_amount === Σ billed_amount of its enrolments
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admissions', function (Blueprint $table) {
            // Nullable: an enrolment created with billing switched off has no
            // invoice at all, which is a supported state (the "Generate fee
            // challan(s)" toggle on the wizard's review step).
            $table->foreignId('challan_id')->nullable()->after('course_id')
                ->constrained('challans')->nullOnDelete();

            // Nullable rather than defaulted to zero: null means "not billed",
            // which is a different statement from "billed nothing", and a
            // scholarship worth the whole fee is a real case.
            $table->unsignedInteger('billed_amount')->nullable()->after('challan_id');

            $table->index('challan_id');
        });

        // Backfill from the existing one-to-one link. Every challan today bills
        // exactly one enrolment for its whole base amount, so both columns are
        // fully determined and nothing is guessed.
        //
        // One statement, with correlated subqueries rather than a JOIN, because
        // SQLite has no UPDATE ... JOIN. Loading every challan and issuing an
        // UPDATE per row would mean one round trip per invoice on a migration
        // the deployment guide tells the operator to run by hand against the
        // live database.
        DB::statement('
            UPDATE admissions
               SET challan_id    = (SELECT c.id FROM challans c WHERE c.admission_id = admissions.id),
                   billed_amount = (SELECT c.base_amount FROM challans c WHERE c.admission_id = admissions.id)
             WHERE EXISTS (SELECT 1 FROM challans c WHERE c.admission_id = admissions.id)
        ');

        $this->assertInvariantHolds();
    }

    /**
     * Every invoice must be worth exactly the sum of what it bills.
     *
     * Checked here rather than trusted, because this migration runs against a
     * live fee ledger and a silent mismatch would surface later as a revenue
     * report that does not reconcile, with nothing pointing back to this
     * change as the cause.
     */
    private function assertInvariantHolds(): void
    {
        $broken = DB::table('challans')
            ->leftJoin('admissions', 'admissions.challan_id', '=', 'challans.id')
            ->groupBy('challans.id', 'challans.challan_no', 'challans.base_amount')
            ->havingRaw('COALESCE(SUM(admissions.billed_amount), 0) <> challans.base_amount')
            ->select('challans.challan_no', 'challans.base_amount', DB::raw('COALESCE(SUM(admissions.billed_amount), 0) as billed'))
            ->get();

        if ($broken->isEmpty()) {
            return;
        }

        $detail = $broken->take(10)
            ->map(fn ($row) => $row->challan_no.' is '.$row->base_amount.' but bills '.$row->billed)
            ->implode('; ');

        throw new RuntimeException(
            'Backfill left '.$broken->count().' challan(s) whose fee does not match what they bill: '
            .$detail.'. Nothing was dropped, so the previous state is intact; resolve these before migrating.'
        );
    }

    public function down(): void
    {
        Schema::table('admissions', function (Blueprint $table) {
            $table->dropForeign(['challan_id']);
            $table->dropIndex(['challan_id']);
            $table->dropColumn(['challan_id', 'billed_amount']);
        });
    }
};
