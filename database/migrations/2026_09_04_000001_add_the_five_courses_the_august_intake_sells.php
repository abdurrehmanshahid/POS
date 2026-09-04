<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The five courses named by the August intake sheet and by nothing before it.
 *
 * ---------------------------------------------------------------------------
 * Where these come from.
 * ---------------------------------------------------------------------------
 *
 * `August Enrollment .xlsx` is a hand-kept sheet, not an export — the POS was
 * offline while it was being maintained, so it is the only record of who
 * enrolled in August. It names seven courses. Three already exist (Graphic
 * Designing, Cyber Security in both its lengths, Odoo ERP Development); these
 * five do not, under any spelling.
 *
 * ---------------------------------------------------------------------------
 * Why a course's LENGTH is part of its identity here.
 * ---------------------------------------------------------------------------
 *
 * The sheet carries a DURATION column the export tool never had, and it
 * matters: two students bought "Digital Media Marketing" in August, one for one
 * month at 20,000 and one for three months at 60,000. Those are two products at
 * two prices, and folding them into one course would put a one-month student on
 * a three-month register and quote the next walk-in a price that depends on
 * which of the two the counter happened to open.
 *
 * The catalogue already works this way where it has had to — `CS-101 Cyber
 * Security` beside `CS-102 Cyber Security (3 Months)`, `DMM-601` for the
 * six-month course — so this follows a convention rather than inventing one.
 *
 * The `-M<n>` suffix is deliberately NOT the existing numeric scheme. Those
 * digits encode a level (DMM-101 is Level-1 Part-A, DMM-201 is Level-2 Part-A),
 * so a three-month unlevelled course cannot be "DMM-301" — that code is taken
 * by Level-3 and means something else entirely. A suffix that reads as the
 * duration it is cannot collide with a level that it is not.
 *
 * ---------------------------------------------------------------------------
 * The fees here do NOT decide what any imported student is billed.
 * ---------------------------------------------------------------------------
 *
 * As with the two migrations before this one. `RollResolver::validateMoney()`
 * bills every imported row from the sheet's own Original/Discounted Price and
 * never from this table, so creating these courses cannot change one rupee of
 * the August intake's history. The fee below is what the counter quotes for a
 * NEW sale, and every one is editable on the Courses screen.
 *
 * The evidence is better than the earlier batches had. Those had to infer a
 * list price from a cloud of discounted ones; this sheet states the list price
 * outright in its `TOTAL FEE` column, separately from the `DISCOUNTED FEE` the
 * student actually paid. Every figure below is that column, and it is
 * unanimous within each course.
 *
 * `trainer_id` is null. Teaching staff are assigned on the Courses screen; a
 * migration inventing a trainer would put a fictional person's name against a
 * real cohort's register.
 */
return new class extends Migration
{
    /** code, title, fee, basis */
    private const COURSES = [
        ['KC-M2', 'Kids Camp (2 Months)', 40000, '6 rows, TOTAL FEE 40,000 on every one'],
        ['GAI-M3', 'Gen A.I (3 Months)', 60000, '4 rows, TOTAL FEE 60,000 on every one'],
        ['DMM-M1', 'Digital Media Marketing (1 Month)', 20000, '1 row, TOTAL FEE 20,000'],
        ['DMM-M3', 'Digital Media Marketing (3 Months)', 60000, '1 row, TOTAL FEE 60,000'],
        ['ECT-M4', 'E-Commerce Track (4 Months)', 60000, '1 row, TOTAL FEE 60,000'],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::COURSES as [$code, $title, $fee, $basis]) {
            // Idempotent, and it never touches a course that already exists —
            // re-running must not reset a fee an administrator has corrected on
            // the Courses screen.
            if (DB::table('courses')->where('code', $code)->exists()) {
                continue;
            }

            DB::table('courses')->insert([
                'code' => $code,
                'title' => $title,
                'trainer_id' => null,   // assigned on the Courses screen
                'fee' => $fee,
                'capacity' => null,     // NULL = unlimited; the sheet records no caps
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Only courses nobody is enrolled on. Dropping a course with admissions
        // against it would orphan `admissions.course_id` and take the fee
        // history of a real student with it.
        $codes = array_column(self::COURSES, 0);

        $ids = DB::table('courses')->whereIn('code', $codes)->pluck('id');
        $enrolled = DB::table('admissions')->whereIn('course_id', $ids)->pluck('course_id')->unique();

        DB::table('courses')
            ->whereIn('code', $codes)
            ->whereNotIn('id', $enrolled)
            ->delete();
    }
};
