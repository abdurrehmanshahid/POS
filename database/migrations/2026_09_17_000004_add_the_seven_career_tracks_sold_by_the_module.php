<?php

use App\Models\Course;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The seven career tracks the institute advertises, each sold by the module.
 *
 * ---------------------------------------------------------------------------
 * Where these come from.
 * ---------------------------------------------------------------------------
 *
 * The institute's own site lists seven CAREER TRACKS — "VIEW ALL 7 TRACKS" —
 * and not one of them existed in this catalogue. What existed were the older,
 * shorter courses the tracks grew out of: `ODOO-301 Odoo ERP Development`
 * beside the Odoo Functional Consultant track, `CS-101 Cyber Security` beside
 * the Cybersecurity track, `GAI-601` beside Generative & Agentic AI. So the
 * counter could sell the old course but not the thing the website was
 * advertising, and a walk-in asking for "the Cloud + MLOps track" could not be
 * enrolled on anything at all.
 *
 * ---------------------------------------------------------------------------
 * Why these are new rows and not edits to the courses they resemble.
 * ---------------------------------------------------------------------------
 *
 * A track is a different product from the course it grew out of: different
 * length, different syllabus, different price. Renaming `ODOO-301` into the
 * Odoo Functional Consultant track would rewrite what 80,000-rupee students
 * already bought — the course title is printed on their fee voucher and read
 * off their admission record — so the old courses are left exactly as they
 * are. Retire any that the tracks supersede on the Courses screen, which is
 * reversible; a migration deciding that is not.
 *
 * ---------------------------------------------------------------------------
 * The module split, and why `fee` is set anyway.
 * ---------------------------------------------------------------------------
 *
 * Every track is 45,000 / 30,000 / 45,000 across three modules, so the full
 * track is 120,000 — the figures the institute gave. Pricing comes from the
 * modules (see {@see Course::priceFor()}), and `courses.fee` is
 * not consulted for a course that has them.
 *
 * It is still written as 120,000 rather than left at some placeholder, for two
 * reasons: the column is NOT NULL, and a row whose `fee` disagreed with what
 * its modules add up to is a trap for the next person reading the table
 * directly. If every module of a track is ever retired, the course falls back
 * to `fee` and still quotes the right price rather than zero.
 *
 * The module names are the institute's own — "Module 1", "Module 2",
 * "Module 3". Inventing syllabus headings here would put words on a fee
 * voucher that no one at the institute chose.
 *
 * `trainer_id` is null and `capacity` is null, for the reasons
 * 2026_09_04_000001 gives: teaching staff are assigned on the Courses screen,
 * and a migration inventing a trainer would put a fictional person's name
 * against a real cohort's register.
 */
return new class extends Migration
{
    /** code, title */
    private const TRACKS = [
        ['TRK-GAAI', 'Generative & Agentic AI'],
        ['TRK-CMLO', 'Cloud + MLOps'],
        ['TRK-ODOO', 'Odoo Functional Consultant'],
        ['TRK-AIFS', 'AI-Integrated Full Stack'],
        ['TRK-CYBR', 'Cybersecurity'],
        ['TRK-APGD', 'AI-Powered Graphic Design'],
        ['TRK-APMK', 'AI-Powered Marketing'],
    ];

    /** seq, title, fee — identical across every track. */
    private const MODULES = [
        [1, 'Module 1', 45000],
        [2, 'Module 2', 30000],
        [3, 'Module 3', 45000],
    ];

    /**
     * A `TRK-` prefix rather than the catalogue's subject codes.
     *
     * `CS-101` is Cyber Security the course; the Cybersecurity TRACK is a
     * different product at four times the price, and a code that differed from
     * it only in its digits would be picked wrong at the counter eventually.
     * The prefix says which kind of thing it is before the reader gets to the
     * subject, which is the distinction that actually matters when choosing.
     */
    private const FULL_TRACK_FEE = 120000;

    public function up(): void
    {
        $now = now();

        foreach (self::TRACKS as [$code, $title]) {
            // Idempotent, and it never touches a track that already exists —
            // re-running must not reset a fee or a module price an
            // administrator has since corrected on the Courses screen.
            if (DB::table('courses')->where('code', $code)->exists()) {
                continue;
            }

            $courseId = DB::table('courses')->insertGetId([
                'code' => $code,
                'title' => $title,
                'trainer_id' => null,   // assigned on the Courses screen
                'fee' => self::FULL_TRACK_FEE,
                'capacity' => null,     // NULL = unlimited
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach (self::MODULES as [$seq, $moduleTitle, $fee]) {
                DB::table('course_modules')->insert([
                    'course_id' => $courseId,
                    'seq' => $seq,
                    'title' => $moduleTitle,
                    'fee' => $fee,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Only tracks nobody is enrolled on. Dropping a course with admissions
        // against it would orphan `admissions.course_id` and take the fee
        // history of a real student with it — and `admission_modules`
        // restricts on delete, so a sold module would refuse anyway.
        $codes = array_column(self::TRACKS, 0);

        $ids = DB::table('courses')->whereIn('code', $codes)->pluck('id');
        $enrolled = DB::table('admissions')->whereIn('course_id', $ids)->pluck('course_id')->unique();
        $removable = $ids->diff($enrolled);

        DB::table('course_modules')->whereIn('course_id', $removable)->delete();
        DB::table('courses')->whereIn('id', $removable)->delete();
    }
};
