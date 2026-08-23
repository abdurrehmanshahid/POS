<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One attendance mark per student, per course, per day.
 *
 * The table was created alongside the rest of the schema but nothing ever wrote
 * to it, so the missing constraint never mattered. Now that a capture screen
 * exists it does: without it, saving the same register twice (a double click, a
 * retried request, two officers on the same class) writes a second row and every
 * attendance percentage silently doubles its denominator.
 *
 * `cohort_id` is deliberately NOT part of the key. A student sits in one batch
 * per course, so course plus student plus date already identifies the mark, and
 * including the batch would let a reassignment produce two marks for one day.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Defensive: the table has never had a writer, so this should be a
        // no-op. It matters if anything wrote to it out of band before the
        // constraint landed, because adding a unique index over duplicates
        // fails outright and takes the deploy with it.
        $duplicates = DB::table('attendances')
            ->select('course_id', 'student_id', 'session_date')
            ->groupBy('course_id', 'student_id', 'session_date')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $dupe) {
            // Keep the most recent mark, which is the correction, and drop the
            // rest rather than guessing which of several is authoritative.
            $keep = DB::table('attendances')
                ->where('course_id', $dupe->course_id)
                ->where('student_id', $dupe->student_id)
                ->where('session_date', $dupe->session_date)
                ->max('id');

            DB::table('attendances')
                ->where('course_id', $dupe->course_id)
                ->where('student_id', $dupe->student_id)
                ->where('session_date', $dupe->session_date)
                ->where('id', '!=', $keep)
                ->delete();
        }

        Schema::table('attendances', function (Blueprint $table) {
            $table->foreignId('cohort_id')->nullable()->after('course_id')
                ->constrained()->nullOnDelete();

            $table->unique(['course_id', 'student_id', 'session_date'], 'attendances_session_unique');
            $table->index('student_id');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropUnique('attendances_session_unique');
        });

        // ── Why the foreign key comes off before its index ──────────────────
        //
        // `up()` creates `attendances_student_id_index`, so `down()` has to
        // remove it — leaving it behind made `migrate:rollback` followed by
        // `migrate` die on "index already exists", which is the bug the CI step
        // "Prove the migrations roll back" was added to catch.
        //
        // Dropping it directly works on SQLite and is REFUSED by MySQL:
        //
        //   SQLSTATE[HY000]: General error: 1553 Cannot drop index
        //   'attendances_student_id_index': needed in a foreign key constraint
        //
        // MySQL will not let go of the last index that can serve a foreign key,
        // and on this table that is the one we are trying to drop. An earlier
        // version of this comment asserted that the constraint was served by a
        // separate auto-created `attendances_student_id_foreign` index and that
        // dropping this one was therefore safe. It is not, and the cost of
        // being wrong was not a failed rollback in isolation: this migration
        // runs inside ConcurrencyTest's `DatabaseMigrations` teardown, the
        // exception aborted that teardown before it could reset
        // RefreshDatabaseState::$migrated, and every one of the 276 tests after
        // it re-seeded into a database that had never been dropped. One bad
        // down() presented as a whole-suite collapse on the MySQL leg only.
        //
        // Removing the constraint first means no index is needed by any
        // constraint, so the drop cannot be refused whatever MySQL's index
        // inventory happens to look like. The constraint is then put back,
        // because create_attendances_table declared it and down() has to leave
        // the schema as that migration left it.
        //
        // Separate Schema::table() calls on purpose: each one is its own ALTER
        // statement, so MySQL evaluates the constraint state between them
        // rather than inside a single batched statement.
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropForeign(['student_id']);
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndex('attendances_student_id_index');
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->foreign('student_id')->references('id')->on('students');
        });

        // See the note in create_cohorts_table's down(): the constraint has to
        // go before the column, and the column has to go at all.
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropForeign(['cohort_id']);
            $table->dropColumn('cohort_id');
        });
    }
};
