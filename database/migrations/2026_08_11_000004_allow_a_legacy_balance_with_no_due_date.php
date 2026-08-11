<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `challans.due_date` becomes nullable, so a legacy debt can be owed without
 * pretending to know when it falls due.
 *
 * 33 rows of the institute's roll carry an outstanding balance and no "Pending
 * Payment Due Date". The importer used to refuse them. The alternative it was
 * offered — fall back to the registration date, which is what `RollPersister`
 * already did for dated rows — would have been the worst possible answer here:
 * every one of those 33 balances would have become instantly, silently overdue
 * by months, and the institute would have started chasing parents over a
 * deadline nobody ever set.
 *
 * NULL means exactly one thing: money is owed and no deadline was recorded.
 *
 * ---------------------------------------------------------------------------
 * What already handles NULL, and what had to be taught
 * ---------------------------------------------------------------------------
 *
 * Most of the money layer needed no change, which is worth recording so nobody
 * "fixes" it later:
 *
 *  - `Challan::isOverdue()` already tests `due_date !== null` first.
 *  - `Challan::scopeOverdue()` compares `due_date < today` in SQL, and NULL
 *    compared to anything is NULL, which is not true — so an undated challan is
 *    excluded from every overdue query by the semantics of SQL itself, not by
 *    an added condition.
 *  - `Ledger::outstanding()` derives from the payments ledger and never reads a
 *    date, so an undated balance still counts as money owed.
 *
 * One place was genuinely wrong: `Reporting::duesAgeing()` did
 * `Carbon::parse($challan->due_date)`, and `Carbon::parse(null)` quietly returns
 * NOW. Every undated debt would have landed in "Not yet due" — filed as
 * healthy, current money among balances that really are current. It now gets an
 * "Unscheduled" bucket of its own, so Rs 450,688 of legacy debt is visible as
 * something needing a decision rather than hidden as something needing nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('challans', function (Blueprint $table) {
            $table->date('due_date')->nullable()->change();
        });
    }

    public function down(): void
    {
        // There is no date to put back, and the column is indexed and NOT NULL
        // on the way down. The registration-date fallback this migration exists
        // to avoid is used only here, where the alternative is a rollback that
        // cannot complete at all.
        DB::table('challans')
            ->whereNull('due_date')
            ->update(['due_date' => DB::raw('DATE(created_at)')]);

        Schema::table('challans', function (Blueprint $table) {
            $table->date('due_date')->nullable(false)->change();
        });
    }
};
