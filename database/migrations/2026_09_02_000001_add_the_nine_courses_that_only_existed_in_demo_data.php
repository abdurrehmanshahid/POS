<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The nine courses the roll sells that only ever existed in `DemoDataSeeder`.
 *
 * ---------------------------------------------------------------------------
 * Why these were missing, and why it was invisible.
 * ---------------------------------------------------------------------------
 *
 * `..._000001_add_the_courses_the_institutes_roll_actually_sells` added 27
 * courses — the Part-B and Level-2 entries — and assumed the base courses were
 * already there. They were, on a developer's machine: `DemoDataSeeder` creates
 * WD-101, AI-201, DMM-101, SHOP-101, GD-101, SKC-101, ODOO-301, VE-101 and
 * ROB-101 as fixtures, with invented fees and invented trainers.
 *
 * `DatabaseSeeder` runs that seeder only in `local` and `testing`, and go-live
 * checklist P2-09 says to run only `RolePermissionSeeder` and
 * `SuperAdminSeeder`. So on production these nine did not exist, and
 * `config/roll-import.php` has aliases pointing straight at five of their codes.
 *
 * Measured on the production box, 2026-09-02: **208 of 478 roll rows** were
 * refused with `unknown course`. This is B-08's second half, and it is the same
 * fault as its first — a production dependency living in a seeder production is
 * forbidden to run. `ProductionSeedingTest` now fails the day this lands, which
 * is how it was written.
 *
 * ---------------------------------------------------------------------------
 * The fees here do NOT decide what any imported student is billed.
 * ---------------------------------------------------------------------------
 *
 * Stated again from the earlier migration because it is the whole reason this
 * can be written without waiting on the institute. `RollResolver::validateMoney()`
 * bills every imported row from the spreadsheet's own Original/Discounted Price
 * and never from this table. Creating these courses cannot change one rupee of
 * imported history. The fee below is only what the counter quotes for a NEW
 * sale, and every one is editable on the Courses screen.
 *
 * Same methodology as the 27, so the two sets can be read together: each fee is
 * the **most common single-course price for that course in the roll**. Rows
 * naming several courses are excluded, because such a row carries ONE combined
 * price for the whole invoice and attributing it to each course invents prices
 * that were never charged. `basis` records how much evidence each number has.
 *
 * Note how much better evidenced most of these are than the earlier batch —
 * these are the institute's highest-volume products, so the modal price rests
 * on 30 rows rather than one. The demo seeder's guesses were wrong about nearly
 * all of them: Super Kid Camp was seeded at 15,000 and actually sells at
 * 20,000, Shopify at 25,000 against a real 20,000.
 *
 * `trainer_id` is null. Teaching staff are assigned on the Courses screen; a
 * migration inventing a trainer would put a fictional person's name against a
 * real cohort's register.
 */
return new class extends Migration
{
    /** code, title, fee, basis */
    private const COURSES = [
        // Well evidenced: high-volume products, the modal price is a real cluster.
        ['SKC-101', 'Super Kid Camp', 20000, '31 rows, 20,000 x17, spread 10,000-40,000'],
        ['DMM-101', 'Digital Media Marketing (Level-1 Part-A)', 10000, '30 rows, 10,000 x10, spread 5,000-13,000'],
        ['SHOP-101', 'Shopify', 20000, '25 rows, 20,000 x11, spread 15,000-35,000'],
        ['AI-201', 'Artificial Intelligence (Level-1 Part-A)', 12000, '20 rows, 12,000 x5, spread 5,000-16,000'],
        ['VE-101', 'Video Editing and YouTube Automation', 20000, '13 rows, 20,000 x8, spread 12,000-26,000'],
        ['GD-101', 'Graphic Designing', 20000, '9 rows, 20,000 x6, spread 20,000-24,000'],

        // Weak: the commonest price is still a minority of the rows.
        ['WD-101', 'Web Development (Level-1 Part-A)', 13000, 'WEAK - 16 rows, 13,000 x4, spread 6,000-25,000'],

        // THIN: one or two observations. Confirm before quoting.
        ['ROB-101', 'STEM Robotics', 12000, 'THIN - 1 row'],
        // Two rows, 40,000 and 64,992, so there is no modal price at all. The
        // FLOOR is taken rather than the higher figure, matching how CS-101 was
        // handled: quoting a new student too low is a conversation, quoting them
        // 25,000 too high loses the sale before anyone can have it.
        ['ODOO-301', 'Odoo ERP Development', 40000, 'THIN - 2 rows, 40,000 and 64,992, no modal price; floor taken'],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::COURSES as [$code, $title, $fee, $basis]) {
            // Idempotent, and it never touches a course that already exists.
            // On a developer's machine `DemoDataSeeder` has usually created
            // these already; re-running must not reset a fee an administrator
            // has corrected on the Courses screen, which is the entire point of
            // those numbers being editable.
            if (DB::table('courses')->where('code', $code)->exists()) {
                continue;
            }

            DB::table('courses')->insert([
                'code' => $code,
                'title' => $title,
                'trainer_id' => null,   // assigned on the Courses screen
                'fee' => $fee,
                'capacity' => null,     // NULL = unlimited; the roll records no caps
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
