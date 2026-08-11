<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The 27 courses the institute sells that the catalogue had never heard of.
 *
 * A migration rather than a seeder because this is real catalogue data that has
 * to exist in production, and `DatabaseSeeder` deliberately runs `DemoDataSeeder`
 * only in local and testing. A seeder would have created these on a developer's
 * machine and nowhere else.
 *
 * ---------------------------------------------------------------------------
 * The fees here do NOT decide what any imported student is billed.
 * ---------------------------------------------------------------------------
 *
 * `RollResolver::validateMoney()` bills every imported row from the
 * spreadsheet's own Original/Discounted Price and never from this table — on
 * purpose, because the catalogue and the roll disagree on nine rows out of ten
 * (Super Kid Camp is seeded at 15,000 and sells at 20,000). So creating these
 * courses cannot change one rupee of imported history. The fee below is only
 * what the counter quotes for a NEW sale, and every one of them is editable on
 * the Courses screen.
 *
 * Each fee is the most common single-course price for that course in
 * `student_details_report (45).xlsx`. Rows naming several courses were excluded
 * from that calculation: such a row carries ONE combined price for the whole
 * invoice, so attributing it to each course named invents prices that were
 * never charged. `basis` records how much evidence each number actually has —
 * several rest on a single observation and are flagged THIN so nobody mistakes
 * them for established prices.
 *
 * Levels are created as SEPARATE courses rather than merged into the existing
 * entries. "Shopify (Level-1 Part A)" is not assumed to be the same product as
 * "Shopify", and "Graphic Designing (Level-1 Part-A)" not the same as "Graphic
 * Designing". Splitting is the recoverable mistake — two courses can be merged
 * later by an administrator — whereas merging fuses two cohorts' histories in a
 * way that cannot be undone once money hangs off it.
 */
return new class extends Migration
{
    /** code, title, fee, basis */
    private const COURSES = [
        // Well evidenced: the modal price is a real cluster.
        ['BCS-101', 'Basic to Advance Computer Skills (2 Months)', 10000, '34 rows, 10,000 x16'],
        ['DMM-301', 'Digital Media Marketing (Level-3)', 24000, '26 rows, 24,000 x12'],
        ['DO-101', 'DevOps', 25000, '12 rows, 25,000 x8'],
        ['DMM-601', 'Digital Media Marketing (6 Months)', 40000, '12 rows, 40,000 x7'],
        ['AIC-101', 'AI for Children + Python', 15000, '5 rows, all 15,000'],
        ['DMM-102', 'Digital Media Marketing (Level-1 Part-B)', 10000, '14 rows, 10,000 x6'],
        ['GD-103', 'Graphic Designing (Level-1 Part-B)', 10000, '6 rows, 10,000 x5'],
        ['GD-102', 'Graphic Designing (Level-1 Part-A)', 10000, '6 rows, 10,000 x4'],
        ['CS-102', 'Cyber Security (3 Months)', 12000, '8 rows, 12,000 x4'],
        ['UX-101', 'UI/UX Designing', 20000, '5 rows, 20,000 x3'],
        ['AI-202', 'Artificial Intelligence (Level-1 Part-B)', 12000, '9 rows, 12,000 x3'],
        ['DMM-202', 'Digital Media Marketing (Level-2 Part-B)', 10000, '5 rows, 10,000 x3'],
        ['GAI-601', 'Generative AI (6 Months)', 60000, '8 rows, 60,000 x3'],
        ['DMM-201', 'Digital Media Marketing (Level-2 Part-A)', 10000, '3 rows, 10,000 x2'],

        // Weak: the commonest price is still a minority of the rows.
        ['AIP-101', 'AI for Professionals', 20000, 'WEAK - 6 rows, 20,000 x2, spread 10,000-24,000'],
        ['SHOP-102', 'Shopify (Level-1 Part-A)', 10000, 'WEAK - 5 rows, 10,000 x2, spread 5,000-15,000'],
        ['WD-102', 'Web Development (Level-1 Part-B)', 10000, 'WEAK - 4 rows, spread 6,000-13,000'],
        ['CS-101', 'Cyber Security', 30000, 'WEAK - 4 rows; the 80,004 outlier was rejected, 30,000 is the floor'],

        // THIN: one or two observations. Confirm these before quoting them.
        ['AI-203', 'Artificial Intelligence (Level-2 Part-A)', 14000, 'THIN - 2 rows'],
        ['AI-204', 'Artificial Intelligence (Level-2 Part-B)', 10000, 'THIN - 2 rows'],
        ['SHOP-103', 'Shopify (Level-1 Part-B)', 10000, 'THIN - 2 rows'],
        ['CS-103', 'Cyber Security (Level-1 Part-B)', 13000, 'THIN - no single-course row; 13,000 is the floor seen'],
        ['GD-201', 'Graphic Designing (Level-2 Part-A)', 10000, 'THIN - 1 row'],
        ['GD-202', 'Graphic Designing (Level-2 Part-B)', 10000, 'THIN - 1 row'],
        ['WD-201', 'Web Development (Level-2 Part-A)', 6000, 'THIN - 1 row'],
        ['CHI-101', 'Chinese Language', 30000, 'THIN - 1 row'],
        ['PS-101', 'Public Speaking', 10000, 'THIN - 1 row'],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::COURSES as [$code, $title, $fee, $basis]) {
            // Idempotent, and it never touches a course that already exists.
            // Re-running must not reset a fee an administrator has corrected on
            // the Courses screen, which is the whole point of those numbers
            // being editable.
            if (DB::table('courses')->where('code', $code)->exists()) {
                continue;
            }

            DB::table('courses')->insert([
                'code' => $code,
                'title' => $title,
                'trainer_id' => null,   // assigned on the Courses screen
                'fee' => $fee,
                'capacity' => null,     // unlimited until the institute caps it
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Only courses that carry no enrolment. Dropping a course that students
        // are attached to would orphan admissions and the money hanging off
        // them; if a course has been used, reversing this migration is not the
        // right tool for removing it.
        $codes = array_column(self::COURSES, 0);

        $ids = DB::table('courses')->whereIn('code', $codes)->pluck('id');
        $inUse = DB::table('admissions')->whereIn('course_id', $ids)->pluck('course_id')->unique();

        DB::table('courses')
            ->whereIn('code', $codes)
            ->whereNotIn('id', $inUse)
            ->delete();
    }
};
