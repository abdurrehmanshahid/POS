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
            $table->dropIndex(['student_id']);
            $table->dropConstrainedForeignKey('cohort_id');
        });
    }
};
