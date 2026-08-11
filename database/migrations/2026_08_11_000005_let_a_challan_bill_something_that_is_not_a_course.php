<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A challan can now bill something that is not an enrolment.
 *
 * 32 rows of the institute's roll charge real money against something nobody
 * enrols on: "Recovery (Batch 4)" (23), "Co-working Space" (6) and
 * "Certificate Fee (Batch# 02)" (3). A room booking is not a course, and
 * creating it as one would give it a trainer, seats and a place in course
 * revenue reports as though it were teaching.
 *
 * ---------------------------------------------------------------------------
 * Why `student_id` rather than a nullable `admission_id` alone
 * ---------------------------------------------------------------------------
 *
 * Every challan belongs to a student, whether or not it bills an enrolment.
 * That was always true and was simply never written down: the student was
 * reached through `admission->student`, a chain that only exists because the
 * anchor admission happened to be there. Making `admission_id` nullable without
 * this would have turned every one of those reads into a nullable chain, in the
 * drawer, the voucher, the ageing report, the exports and the receipt.
 *
 * So the relationship the system actually has is stated directly. `student_id`
 * is backfilled for every existing row and is NOT NULL: a challan with no
 * student is not a charge, it is a bug.
 *
 * ---------------------------------------------------------------------------
 * `raised_by`, and the reason this migration is the dangerous one
 * ---------------------------------------------------------------------------
 *
 * `Ledger::scopedChallans()` is the single gate every money figure in this
 * system passes through — billed, received, outstanding, every report — and it
 * reads `whereHas('admissions', ...)`. A challan with NO admission matches
 * nothing, so without a second path a non-course charge would be billed,
 * collected, audited, and then absent from every total in the application.
 *
 * That is exactly BUG-27's shape, and BUG-27's lesson is that this class of
 * fault is invisible from inside: `outstanding = billed − received` still
 * balances perfectly when a row is missing from BOTH sides. Nothing the
 * database can check would have caught it.
 *
 * So a charge carries its own officer. `raised_by` is backfilled from the
 * anchor admission's `enrolled_by`, which is where scoping already came from,
 * and the scope query gains an explicit branch for admission-less challans.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Added nullable first, backfilled, then tightened. Adding a NOT NULL
        // column to a populated table has nothing to put in it.
        Schema::table('challans', function (Blueprint $table) {
            $table->foreignId('student_id')->nullable()->after('challan_no')
                ->constrained('students')->cascadeOnDelete();
            $table->foreignId('raised_by')->nullable()->after('student_id')
                ->constrained('users');
            // What the money is for, when it is not a course. NULL on an
            // enrolment challan, where the courses already say.
            $table->string('description', 160)->nullable()->after('raised_by');
        });

        // Backfill from the anchor admission, which is where both facts have
        // been living all along.
        DB::statement('
            UPDATE challans
               SET student_id = (SELECT a.student_id FROM admissions a WHERE a.id = challans.admission_id),
                   raised_by  = (SELECT a.enrolled_by FROM admissions a WHERE a.id = challans.admission_id)
             WHERE admission_id IS NOT NULL
        ');

        Schema::table('challans', function (Blueprint $table) {
            $table->foreignId('student_id')->nullable(false)->change();
            $table->foreignId('raised_by')->nullable(false)->change();
            // A charge has no enrolment to point at.
            $table->foreignId('admission_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // A charge cannot survive this: it has no admission to anchor to, and
        // `admission_id` is about to be NOT NULL again. Removed rather than
        // left to fail the constraint, and its payments go with it by the
        // existing cascade.
        DB::table('challans')->whereNull('admission_id')->delete();

        Schema::table('challans', function (Blueprint $table) {
            $table->foreignId('admission_id')->nullable(false)->change();
            $table->dropConstrainedForeignId('student_id');
            $table->dropConstrainedForeignId('raised_by');
            $table->dropColumn('description');
        });
    }
};
