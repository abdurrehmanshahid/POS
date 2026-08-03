<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A student may hold at most ONE live enrolment on a given course.
 *
 * Nothing stopped the same student being registered onto the same course twice,
 * which produced two admissions, two challans and two fees for one seat. At the
 * counter it is an easy mistake to make: the officer is not sure the first
 * registration saved, so they do it again.
 *
 * The constraint is deliberately on LIVE enrolments only, not on the pair
 * outright. Re-taking a course is legitimate, that is what batches are for, and
 * so is enrolling again after a cancellation. What is never legitimate is being
 * on the same course twice at the same time.
 *
 * "Live" is expressed as a VIRTUAL generated column that is 1 while the
 * admission is not cancelled and NULL once it is. Both MySQL 8 and SQLite
 * exclude NULLs from uniqueness, so the index permits any number of cancelled
 * rows for a pair while allowing only one live one.
 *
 * A generated column rather than a real one, and a virtual one rather than
 * stored, for two reasons: it cannot drift out of step with `status` even when
 * something writes with a mass update that fires no model events, and SQLite
 * only permits VIRTUAL generated columns to be added by ALTER TABLE.
 *
 * The cohorts migration reached for a service-level rule in a similar spot and
 * noted that MySQL and SQLite disagree on partial index syntax. They do, but
 * they agree on this, so the rule gets a real backstop rather than only a
 * well-behaved caller.
 */
return new class extends Migration
{
    private const INDEX = 'admissions_live_enrolment_unique';

    public function up(): void
    {
        $this->assertNoExistingDuplicates();

        $driver = DB::connection()->getDriverName();

        // TINYINT UNSIGNED on MySQL, INTEGER on SQLite. The expression is
        // identical on both.
        $type = in_array($driver, ['mysql', 'mariadb'], true) ? 'TINYINT UNSIGNED' : 'INTEGER';

        DB::statement(
            "ALTER TABLE admissions ADD COLUMN active_slot {$type} "
            ."GENERATED ALWAYS AS (CASE WHEN status <> 'cancelled' THEN 1 END) VIRTUAL"
        );

        DB::statement(
            'CREATE UNIQUE INDEX '.self::INDEX.' ON admissions (student_id, course_id, active_slot)'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX '.(DB::connection()->getDriverName() === 'sqlite'
            ? self::INDEX
            : self::INDEX.' ON admissions'));

        Schema::table('admissions', function ($table) {
            $table->dropColumn('active_slot');
        });
    }

    /**
     * Refuse to run over data the constraint would reject.
     *
     * Adding the index on top of duplicates fails at the driver with an opaque
     * error part-way through a deploy. Worse would be "fixing" them here by
     * cancelling one of each pair: each duplicate carries its own challan, and
     * possibly its own collections, so which of the two is the real enrolment is
     * a question only a human can answer. Fail early, name the rows, let them
     * decide.
     */
    private function assertNoExistingDuplicates(): void
    {
        $duplicates = DB::table('admissions')
            ->select('student_id', 'course_id', DB::raw('COUNT(*) as total'))
            ->where('status', '!=', 'cancelled')
            ->groupBy('student_id', 'course_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isEmpty()) {
            return;
        }

        $detail = $duplicates
            ->map(fn ($d) => "student {$d->student_id} on course {$d->course_id} ({$d->total} times)")
            ->implode('; ');

        throw new RuntimeException(
            'Cannot enforce one live enrolment per student per course: existing duplicates found. '
            .'Cancel the surplus admissions (and refund or void their challans) first, then re-run. '
            .$detail
        );
    }
};
