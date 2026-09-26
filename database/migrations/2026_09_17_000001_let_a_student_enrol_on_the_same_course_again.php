<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lift the one-live-enrolment-per-student-per-course rule.
 *
 * 2026_08_03_000002 added it to stop an officer who was unsure the first
 * registration had saved from taking the same seat twice. The institute has
 * since asked for it back: the same student legitimately appears on the same
 * course more than once at the same time — a repeat sitting alongside the
 * running one, a second batch taken in parallel, a re-registration raised
 * while the first is still open — and a hard constraint turned every one of
 * those into a refusal an officer could not work around.
 *
 * What was protecting against double-billing is now the officer's judgement,
 * helped by the wizard, which still marks a course the student already holds
 * so the accidental case is visible before it is confirmed. It no longer
 * refuses it.
 *
 * Both the index and the `active_slot` generated column it was built on go:
 * the column exists only to express "live" to that index, and leaving a
 * generated column behind with nothing reading it is a trap for the next
 * person reading the schema — and for the backup writer, which has to strip
 * generated columns out of every INSERT it emits.
 */
return new class extends Migration
{
    private const INDEX = 'admissions_live_enrolment_unique';

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        // Guarded, because an installation that never ran the 08-03 migration
        // (or that has already had this lifted by hand on the server) must not
        // have the deploy die on a missing index.
        if ($this->hasIndex($driver)) {
            DB::statement('DROP INDEX '.($driver === 'sqlite'
                ? self::INDEX
                : self::INDEX.' ON admissions'));
        }

        if (Schema::hasColumn('admissions', 'active_slot')) {
            Schema::table('admissions', function ($table) {
                $table->dropColumn('active_slot');
            });
        }
    }

    /**
     * Putting the rule back is the 08-03 migration's job, verbatim, so the two
     * cannot drift apart. It will refuse to run if duplicates now exist, which
     * is correct: after this migration they are legitimate rows, and which of
     * them to cancel is a question only a human can answer.
     */
    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        $type = in_array($driver, ['mysql', 'mariadb'], true) ? 'TINYINT UNSIGNED' : 'INTEGER';

        if (! Schema::hasColumn('admissions', 'active_slot')) {
            DB::statement(
                "ALTER TABLE admissions ADD COLUMN active_slot {$type} "
                ."GENERATED ALWAYS AS (CASE WHEN status <> 'cancelled' THEN 1 END) VIRTUAL"
            );
        }

        if (! $this->hasIndex($driver)) {
            DB::statement(
                'CREATE UNIQUE INDEX '.self::INDEX.' ON admissions (student_id, course_id, active_slot)'
            );
        }
    }

    private function hasIndex(string $driver): bool
    {
        if ($driver === 'sqlite') {
            return DB::table('sqlite_master')
                ->where('type', 'index')
                ->where('name', self::INDEX)
                ->exists();
        }

        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::connection()->getDatabaseName())
            ->where('table_name', 'admissions')
            ->where('index_name', self::INDEX)
            ->exists();
    }
};
